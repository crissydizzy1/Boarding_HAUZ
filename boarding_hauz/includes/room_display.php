<?php
function roomDisplayTitle(string $roomNumber): string
{
    $roomNumber = trim($roomNumber);
    return preg_match('/^room\b/i', $roomNumber) ? $roomNumber : 'Room ' . $roomNumber;
}
