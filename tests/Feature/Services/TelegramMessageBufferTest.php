<?php

use App\Jobs\ProcessTelegramPendingBatch;
use App\Models\TelegramChatBatch;
use App\Models\TelegramPendingMessage;
use App\Services\Telegram\TelegramMessageBuffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->buffer = new TelegramMessageBuffer;
});

test('storeMessage inserts a new pending message', function () {
    $this->buffer->storeMessage(123456789, 1, 'Hola bot', 101);

    expect(TelegramPendingMessage::query()->count())->toBe(1);

    $message = TelegramPendingMessage::query()->first();

    expect($message->chat_id)->toBe(123456789);
    expect($message->message_id)->toBe(1);
    expect($message->text)->toBe('Hola bot');
    expect($message->update_id)->toBe(101);
    expect($message->is_edit)->toBeFalse();
});

test('storeMessage upserts in place for an edited message and never duplicates', function () {
    $this->buffer->storeMessage(123456789, 1, 'Hola bot', 101);
    $this->buffer->storeMessage(123456789, 1, 'Hola bot, corregido', 102, isEdit: true);

    expect(TelegramPendingMessage::query()->count())->toBe(1);

    $message = TelegramPendingMessage::query()->first();

    expect($message->text)->toBe('Hola bot, corregido');
    expect($message->update_id)->toBe(102);
    expect($message->is_edit)->toBeTrue();
});

test('storeMessage persists an incoming photo file_id', function () {
    $this->buffer->storeMessage(123456789, 1, 'Mira la foto', 101, photoFileId: 'file-id-123');

    expect(TelegramPendingMessage::query()->count())->toBe(1);

    $message = TelegramPendingMessage::query()->first();

    expect($message->photo_file_id)->toBe('file-id-123');
    expect($message->text)->toBe('Mira la foto');
});

test('storeMessage leaves photo_file_id null for a plain text message', function () {
    $this->buffer->storeMessage(123456789, 1, 'Hola bot', 101);

    expect(TelegramPendingMessage::query()->first()->photo_file_id)->toBeNull();
});

test('storeMessage upserts the photo file_id in place, still keyed on chat_id and message_id', function () {
    $this->buffer->storeMessage(123456789, 1, 'Mira la foto', 101, photoFileId: 'file-id-123');
    $this->buffer->storeMessage(123456789, 1, 'Mira la foto corregida', 102, isEdit: true, photoFileId: 'file-id-456');

    expect(TelegramPendingMessage::query()->count())->toBe(1);

    $message = TelegramPendingMessage::query()->first();

    expect($message->text)->toBe('Mira la foto corregida');
    expect($message->photo_file_id)->toBe('file-id-456');
    expect($message->update_id)->toBe(102);
});

test('scheduleIfNeeded arms a debounced batch job on the first message', function () {
    $this->buffer->storeMessage(123456789, 1, 'Hola bot', 101);

    $this->buffer->scheduleIfNeeded(123456789);

    $batch = TelegramChatBatch::query()->find(123456789);

    expect($batch)->not->toBeNull();
    expect($batch->scheduled_at)->not->toBeNull();
    expect($batch->scheduled_at->isFuture())->toBeTrue();
    expect($batch->processing_at)->toBeNull();

    Queue::assertPushed(ProcessTelegramPendingBatch::class, fn ($job): bool => $job->chatId === 123456789 && $job->delay !== null);
});

test('scheduleIfNeeded does not dispatch again while a batch is already scheduled in the future', function () {
    TelegramChatBatch::factory()->create([
        'chat_id' => 123456789,
        'scheduled_at' => now()->addSeconds(TelegramMessageBuffer::DEBOUNCE_SECONDS),
    ]);

    $this->buffer->scheduleIfNeeded(123456789);

    $batch = TelegramChatBatch::query()->find(123456789);

    Queue::assertNothingPushed();
    expect($batch->scheduled_at)->not->toBeNull();
    expect($batch->processing_at)->toBeNull();
});

test('scheduleIfNeeded skips while a batch is currently processing', function () {
    TelegramChatBatch::factory()->create([
        'chat_id' => 123456789,
        'processing_at' => now(),
    ]);

    $this->buffer->scheduleIfNeeded(123456789);

    $batch = TelegramChatBatch::query()->find(123456789);

    Queue::assertNothingPushed();
    expect($batch->processing_at)->not->toBeNull();
    expect($batch->scheduled_at)->toBeNull();
});

test('scheduleIfNeeded reclaims a stale processing batch and re-arms the debounce', function () {
    TelegramChatBatch::factory()->create([
        'chat_id' => 123456789,
        'processing_at' => now()->subMinutes(TelegramMessageBuffer::STALE_THRESHOLD + 1),
    ]);

    $this->buffer->scheduleIfNeeded(123456789);

    $batch = TelegramChatBatch::query()->find(123456789);

    expect($batch->processing_at)->toBeNull();
    expect($batch->scheduled_at)->not->toBeNull();
    expect($batch->scheduled_at->isFuture())->toBeTrue();

    Queue::assertPushed(ProcessTelegramPendingBatch::class, fn ($job): bool => $job->chatId === 123456789);
});

test('beginProcessing claims the chat and clears any pending schedule', function () {
    TelegramChatBatch::factory()->create([
        'chat_id' => 123456789,
        'scheduled_at' => now()->addSeconds(5),
    ]);

    $this->buffer->beginProcessing(123456789);

    $batch = TelegramChatBatch::query()->find(123456789);

    expect($batch->processing_at)->not->toBeNull();
    expect($batch->scheduled_at)->toBeNull();
});

test('pendingFor returns the buffered messages in insertion order', function () {
    $second = TelegramPendingMessage::factory()->create(['chat_id' => 123456789, 'message_id' => 2, 'text' => 'Dos']);
    $first = TelegramPendingMessage::factory()->create(['chat_id' => 123456789, 'message_id' => 1, 'text' => 'Uno']);

    $pending = $this->buffer->pendingFor(123456789);

    expect($pending->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('deletePending removes only the given rows', function () {
    $first = TelegramPendingMessage::factory()->create(['chat_id' => 123456789, 'message_id' => 1]);
    $otherChat = TelegramPendingMessage::factory()->create(['chat_id' => 987654321, 'message_id' => 2]);

    $this->buffer->deletePending($this->buffer->pendingFor(123456789));

    expect(TelegramPendingMessage::query()->find($first->id))->toBeNull();
    expect(TelegramPendingMessage::query()->find($otherChat->id))->not->toBeNull();
});

test('endProcessing releases the claim and reschedules immediately when messages arrived during the AI call', function () {
    $this->buffer->beginProcessing(123456789);

    TelegramPendingMessage::factory()->create(['chat_id' => 123456789, 'message_id' => 1, 'text' => 'Straggler']);

    $this->buffer->endProcessing(123456789);

    $batch = TelegramChatBatch::query()->find(123456789);

    expect($batch->processing_at)->toBeNull();
    expect($batch->scheduled_at)->not->toBeNull();

    Queue::assertPushed(ProcessTelegramPendingBatch::class, fn ($job): bool => $job->chatId === 123456789 && $job->delay === null);
});

test('endProcessing leaves the chat idle when nothing remains', function () {
    $this->buffer->beginProcessing(123456789);

    $this->buffer->endProcessing(123456789);

    $batch = TelegramChatBatch::query()->find(123456789);

    expect($batch->processing_at)->toBeNull();
    expect($batch->scheduled_at)->toBeNull();

    Queue::assertNothingPushed();
});
