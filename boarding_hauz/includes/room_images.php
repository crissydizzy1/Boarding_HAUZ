<?php

function getRoomImageUrlsForDisplay(int $roomId, ?string $imagePath = null): array
{
    $images = [];
    $projectRoot = dirname(__DIR__);

    $isUploadedRoomImage = $roomId > 0 && is_string($imagePath) && preg_match(
        '#^uploads/rooms/room-' . $roomId . '-[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)$#i',
        $imagePath
    );
    $isLibraryImage = is_string($imagePath) && preg_match(
        '#^assets/images/rooms/room-\d{3}\.jpg$#',
        $imagePath
    );
    if ($isUploadedRoomImage || $isLibraryImage) {
        $absolutePath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath);
        if (is_file($absolutePath)) {
            $images[] = '/boarding_hauz/' . implode('/', array_map('rawurlencode', explode('/', $imagePath)));
        }
    }

    if ($roomId > 0) {
        $directory = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'rooms';
        $files = glob($directory . DIRECTORY_SEPARATOR . 'room-' . $roomId . '-*') ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        usort($files, static function (string $first, string $second): int {
            return strcmp(basename($second), basename($first));
        });

        foreach ($files as $file) {
            $fileName = basename($file);
            $url = '/boarding_hauz/uploads/rooms/' . rawurlencode($fileName);
            if (!in_array($url, $images, true)) {
                $images[] = $url;
            }
        }
    }

    return $images ?: ['/boarding_hauz/assets/images/room-placeholder.svg'];
}

function getRoomImageUrlForDisplay(int $roomId, ?string $imagePath = null): string
{
    return getRoomImageUrlsForDisplay($roomId, $imagePath)[0];
}
