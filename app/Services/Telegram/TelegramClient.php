<?php

namespace App\Services\Telegram;

use App\Models\TelegramSetting;
use App\Services\Telegram\Exceptions\TelegramApiException;
use App\Services\Telegram\Exceptions\TelegramNotConfiguredException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use TelegramBot\Api\Types\PhotoSize;
use TelegramBot\Api\Types\Update;

/**
 * Thin wrapper around the Telegram Bot API.
 *
 * Credentials are read from the database (TelegramSetting singleton) and the
 * low-level HTTP calls go through an injectable Guzzle client so tests can run
 * against a mock handler without real network access. No conversation or
 * business logic lives here.
 */
class TelegramClient
{
    private const API_BASE = 'https://api.telegram.org';

    private ClientInterface $http;

    private string $token;

    /**
     * @throws TelegramNotConfiguredException
     */
    public function __construct(?ClientInterface $http = null)
    {
        $token = TelegramSetting::singleton()->bot_token;

        if ($token === null || $token === '') {
            throw new TelegramNotConfiguredException;
        }

        $this->http = $http ?? new GuzzleClient;
        $this->token = $token;
    }

    /**
     * Fetch new updates from the Telegram Bot API.
     *
     * @return array<int, array{update_id: int, chat_id?: int|string, message_id?: int, text?: string, photo?: string, edit?: bool}>
     */
    public function getUpdates(?int $offset = null, int $timeout = 0): array
    {
        $updates = $this->request('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
        ]);

        return array_map(
            fn (array $data): array => $this->normalizeUpdate($data),
            (array) $updates,
        );
    }

    /**
     * Send a message to the given chat.
     *
     * @param  string|null  $parseMode  Optional parse mode ('HTML', 'Markdown', etc.).
     * @return array<string, mixed> The Telegram "Message" result payload.
     */
    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }

        return (array) $this->request('sendMessage', $params);
    }

    /**
     * Set the bot's command menu.
     *
     * @param  array<int, array{command: string, description: string}>  $commands
     */
    public function setMyCommands(array $commands): bool
    {
        return (bool) $this->request('setMyCommands', ['commands' => $commands]);
    }

    /**
     * Edit the text of a previously sent message.
     *
     * @param  string|null  $parseMode  Optional parse mode ('HTML', 'Markdown', etc.).
     * @return array<string, mixed> The Telegram "Message" result payload.
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, ?string $parseMode = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ];

        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }

        return (array) $this->request('editMessageText', $params);
    }

    /**
     * Delete a previously sent message.
     */
    public function deleteMessage(int|string $chatId, int $messageId): bool
    {
        return (bool) $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * Send a local image file as a photo message to the given chat.
     *
     * The file is uploaded via multipart/form-data, not JSON.
     *
     * @param  string|null  $parseMode  Optional parse mode ('HTML', 'Markdown', etc.).
     * @return array<string, mixed> The Telegram "Message" result payload.
     */
    public function sendPhoto(int|string $chatId, string $photoPath, ?string $caption = null, ?string $parseMode = null): array
    {
        $multipart = [
            ['name' => 'chat_id', 'contents' => (string) $chatId],
            ['name' => 'photo', 'contents' => fopen($photoPath, 'r')],
        ];

        if ($caption !== null && $caption !== '') {
            $multipart[] = ['name' => 'caption', 'contents' => $caption];

            if ($parseMode !== null) {
                $multipart[] = ['name' => 'parse_mode', 'contents' => $parseMode];
            }
        }

        return (array) $this->requestMultipart('sendPhoto', $multipart);
    }

    /**
     * Resolve a Telegram file_id to its download metadata.
     *
     * @return array<string, mixed> The Telegram "File" result payload (file_id, file_unique_id, file_size?, file_path?).
     */
    public function getFile(string $fileId): array
    {
        return (array) $this->request('getFile', ['file_id' => $fileId]);
    }

    /**
     * Download a Telegram-hosted file to the given local destination path.
     *
     * The caller decides the destination directory; this method only writes to
     * the provided path.
     *
     * @throws TelegramApiException
     */
    public function downloadFile(string $filePath, string $destinationPath): void
    {
        $url = self::API_BASE.'/file/bot'.$this->token.'/'.$filePath;

        try {
            $response = $this->http->request('GET', $url, ['http_errors' => false]);
        } catch (GuzzleException $e) {
            throw new TelegramApiException("Telegram file download '{$filePath}' failed.", 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            throw new TelegramApiException("Telegram file download '{$filePath}' failed: HTTP {$response->getStatusCode()}.");
        }

        if (file_put_contents($destinationPath, (string) $response->getBody()) === false) {
            throw new TelegramApiException("Telegram file download '{$filePath}' failed: unable to write '{$destinationPath}'.");
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return mixed The Telegram result payload (array, bool, etc.).
     *
     * @throws TelegramApiException
     */
    private function request(string $method, array $params): mixed
    {
        return $this->send($method, ['json' => $params]);
    }

    /**
     * @param  array<int, array{name: string, contents: mixed}>  $multipart
     * @return mixed The Telegram result payload (array, bool, etc.).
     *
     * @throws TelegramApiException
     */
    private function requestMultipart(string $method, array $multipart): mixed
    {
        return $this->send($method, ['multipart' => $multipart]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return mixed The Telegram result payload (array, bool, etc.).
     *
     * @throws TelegramApiException
     */
    private function send(string $method, array $options): mixed
    {
        try {
            $response = $this->http->request('POST', $this->endpoint($method), $options);
        } catch (GuzzleException $e) {
            throw new TelegramApiException("Telegram API request '{$method}' failed.", 0, $e);
        }

        $payload = json_decode((string) $response->getBody(), true);

        if (! is_array($payload) || ($payload['ok'] ?? false) !== true) {
            $description = is_array($payload) ? ($payload['description'] ?? 'Unknown error') : 'Invalid JSON response';

            throw new TelegramApiException("Telegram API request '{$method}' failed: {$description}");
        }

        return $payload['result'];
    }

    private function endpoint(string $method): string
    {
        return self::API_BASE.'/bot'.$this->token.'/'.$method;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{update_id: int, chat_id?: int|string, message_id?: int, text?: string, photo?: string, edit?: bool}
     */
    private function normalizeUpdate(array $data): array
    {
        $update = Update::fromResponse($data);

        $normalized = ['update_id' => $update->getUpdateId()];

        $message = $update->getMessage();
        $isEdit = false;

        if ($message === null) {
            $message = $update->getEditedMessage();
            $isEdit = $message !== null;
        }

        if ($message === null) {
            return $normalized;
        }

        $normalized['chat_id'] = $message->getChat()->getId();
        $normalized['message_id'] = (int) $message->getMessageId();

        if ($isEdit) {
            $normalized['edit'] = true;
        }

        if ($message->getText() !== null) {
            $normalized['text'] = $message->getText();
        }

        $photoSizes = $message->getPhoto();

        if (is_array($photoSizes) && $photoSizes !== []) {
            if ($message->getCaption() !== null) {
                $normalized['text'] = $message->getCaption();
            }

            $normalized['photo'] = $this->largestPhotoFileId($photoSizes);
        }

        return $normalized;
    }

    /**
     * Return the file_id of the PhotoSize with the greatest width x height.
     *
     * @param  array<int, PhotoSize>  $photoSizes
     */
    private function largestPhotoFileId(array $photoSizes): string
    {
        $largest = null;

        foreach ($photoSizes as $photoSize) {
            $area = $photoSize->getWidth() * $photoSize->getHeight();

            if ($largest === null || $area > $largest['area']) {
                $largest = ['area' => $area, 'file_id' => $photoSize->getFileId()];
            }
        }

        return $largest['file_id'];
    }
}
