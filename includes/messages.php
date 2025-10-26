<?php
require_once __DIR__ . '/../config/database.php';

function fetch_messages(): array
{
    $pdo = get_pdo();
    $stmt = $pdo->query('SELECT id, title, body, font_family, font_size, text_color, background_color, speed FROM ticker_messages ORDER BY position ASC, id ASC');
    return $stmt->fetchAll();
}

function next_position(): int
{
    $pdo = get_pdo();
    $stmt = $pdo->query('SELECT COALESCE(MAX(position), 0) + 1 AS next_pos FROM ticker_messages');
    return (int) $stmt->fetchColumn();
}

function create_message(array $data): void
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare('INSERT INTO ticker_messages (title, body, font_family, font_size, text_color, background_color, speed, position) VALUES (:title, :body, :font_family, :font_size, :text_color, :background_color, :speed, :position)');
    $stmt->execute([
        'title' => $data['title'],
        'body' => $data['body'],
        'font_family' => $data['font_family'],
        'font_size' => $data['font_size'],
        'text_color' => $data['text_color'],
        'background_color' => $data['background_color'],
        'speed' => $data['speed'],
        'position' => $data['position'] ?? next_position(),
    ]);
}

function delete_message(int $id): void
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare('DELETE FROM ticker_messages WHERE id = :id');
    $stmt->execute(['id' => $id]);
}
