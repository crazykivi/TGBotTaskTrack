<?php
require 'vendor/autoload.php';

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Update;
use TelegramBot\Api\Types\Message;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;

$mysqli = new mysqli('localhost', 'root', '', 'tgbot');
if ($mysqli->connect_error) {
    die('Ошибка подключения (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
}

$bot = new BotApi('');

$lastUpdateId = 0;

while (true) {
    try {
        $updates = $bot->getUpdates($lastUpdateId + 1, 10, 30);

        foreach ($updates as $update) {
            if ($update->getCallbackQuery()) {
                $callbackQuery = $update->getCallbackQuery();
                $chatId = $callbackQuery->getMessage()->getChat()->getId();
                $callbackData = $callbackQuery->getData();

                switch ($callbackData) {
                    case 'back_to_menu':
                        $stmt = $mysqli->prepare("UPDATE users SET state = 'in_records_menu' WHERE telegram_id = ?");
                        $stmt->bind_param("i", $chatId);
                        $stmt->execute();
                        $stmt->close();

                        $stmt = $mysqli->prepare("SELECT content, created_at FROM records WHERE user_id = (SELECT id FROM users WHERE telegram_id = ?) ORDER BY created_at DESC LIMIT 3");
                        $stmt->bind_param("i", $chatId);
                        $stmt->execute();
                        $stmt->store_result();
                        $stmt->bind_result($content, $createdAt);

                        if ($stmt->num_rows === 0) {
                            $keyboard = new ReplyKeyboardMarkup([
                                [['text' => 'Добавить запись'], ['text' => 'Назад']]
                            ], true, true);
                            $bot->sendMessage($chatId, "Записей нет.", null, false, null, $keyboard);
                        } else {
                            $response = "Ваши последние записи:\n\n";
                            while ($stmt->fetch()) {
                                $response .= "Запись от " . date("d.m.Y H:i", strtotime($createdAt)) . ":\n";
                                $response .= $content . "\n\n";
                            }

                            $keyboard = new ReplyKeyboardMarkup([
                                [['text' => 'Добавить запись'], ['text' => 'Назад']]
                            ], true, true);
                            $bot->sendMessage($chatId, $response, null, false, null, $keyboard);
                        }

                        break;
                }
            }

            $message = $update->getMessage();
            if ($message instanceof Message) {
                $chatId = $message->getChat()->getId();
                $text = mb_strtolower($message->getText(), 'UTF-8');

                $mysqli->begin_transaction();
                try {

                    $stmt = $mysqli->prepare("SELECT telegram_id, state FROM users WHERE telegram_id = ?");
                    $stmt->bind_param("i", $chatId);
                    $stmt->execute();
                    $stmt->bind_result($telegram_id, $state);
                    $stmt->fetch();
                    $stmt->close();

                    if (!$telegram_id) {
                        $stmt = $mysqli->prepare("INSERT INTO users (telegram_id, username, first_name, last_name) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("isss", $chatId, $message->getFrom()->getUsername(), $message->getFrom()->getFirstName(), $message->getFrom()->getLastName());
                        $stmt->execute();
                        $stmt->close();

                        $bot->sendMessage($chatId, "Добро пожаловать! Вы зарегистрированы.");
                    }

                    if ($state === 'awaiting_record') {
                        if ($text === 'назад') {
                            $stmt = $mysqli->prepare("UPDATE users SET state = 'in_records_menu' WHERE telegram_id = ?");
                            $stmt->bind_param("i", $chatId);
                            $stmt->execute();
                            $stmt->close();

                            $keyboard = new ReplyKeyboardMarkup([
                                [['text' => 'Добавить запись'], ['text' => 'Назад']]
                            ], true, true);

                            $bot->sendMessage($chatId, "Вы вернулись в меню записей. Выберите действие:", null, false, null, $keyboard);
                        } else {
                            $stmt = $mysqli->prepare("INSERT INTO records (user_id, content) VALUES ((SELECT id FROM users WHERE telegram_id = ?), ?)");
                            $stmt->bind_param("is", $chatId, $text);
                            $stmt->execute();
                            $stmt->close();

                            $stmt = $mysqli->prepare("UPDATE users SET state = 'in_records_menu' WHERE telegram_id = ?");
                            $stmt->bind_param("i", $chatId);
                            $stmt->execute();
                            $stmt->close();

                            $bot->sendMessage($chatId, "Запись успешно добавлена!");

                            $keyboard = new ReplyKeyboardMarkup([
                                [['text' => 'Добавить запись'], ['text' => 'Назад']]
                            ], true, true);
                            $bot->sendMessage($chatId, "Выберите следующее действие:", null, false, null, $keyboard);
                        }

                        continue;
                    }

                    switch ($text) {
                        case '/start':
                            $keyboard = new ReplyKeyboardMarkup([
                                [['text' => 'Записи'], ['text' => 'Задачи']]
                            ], true, true);
                            $bot->sendMessage($chatId, "Добро пожаловать! Выберите действие:", null, false, null, $keyboard);
                            break;

                        case 'записи':
                            $stmt = $mysqli->prepare("UPDATE users SET state = 'in_records_menu' WHERE telegram_id = ?");
                            $stmt->bind_param("i", $chatId);
                            $stmt->execute();
                            $stmt->close();

                            $stmt = $mysqli->prepare("SELECT content, created_at FROM records WHERE user_id = (SELECT id FROM users WHERE telegram_id = ?) ORDER BY created_at DESC LIMIT 3");
                            $stmt->bind_param("i", $chatId);
                            $stmt->execute();
                            $stmt->store_result();
                            $stmt->bind_result($content, $createdAt);

                            if ($stmt->num_rows === 0) {
                                $keyboard = new ReplyKeyboardMarkup([
                                    [['text' => 'Добавить запись'], ['text' => 'Назад']]
                                ], true, true);
                                $bot->sendMessage($chatId, "Записей нет.", null, false, null, $keyboard);
                            } else {
                                $response = "Ваши последние записи:\n\n";
                                while ($stmt->fetch()) {
                                    $response .= "Запись от " . date("d.m.Y H:i", strtotime($createdAt)) . ":\n";
                                    $response .= $content . "\n\n";
                                }

                                $keyboard = new ReplyKeyboardMarkup([
                                    [['text' => 'Добавить запись'], ['text' => 'Назад']]
                                ], true, true);
                                $bot->sendMessage($chatId, $response, null, false, null, $keyboard);
                            }

                            $stmt->close();
                            break;

                        case 'добавить запись':
                            $stmt = $mysqli->prepare("UPDATE users SET state = 'awaiting_record' WHERE telegram_id = ?");
                            $stmt->bind_param("i", $chatId);
                            $stmt->execute();
                            $stmt->close();

                            $inlineKeyboard = new InlineKeyboardMarkup([
                                [
                                    ['text' => 'Назад', 'callback_data' => 'back_to_menu']
                                ]
                            ]);

                            $bot->sendMessage($chatId, "Введите текст для новой записи или нажмите 'Назад', чтобы отменить.", null, false, null, $inlineKeyboard);
                            break;

                        case 'назад':
                            if ($state === 'in_records_menu') {
                                $keyboard = new ReplyKeyboardMarkup([
                                    [['text' => 'Записи'], ['text' => 'Задачи']]
                                ], true, true);
                                $bot->sendMessage($chatId, "Выберите действие:", null, false, null, $keyboard);
                            }
                            break;

                        default:
                            $keyboard = new ReplyKeyboardMarkup([
                                [['text' => 'Записи'], ['text' => 'Задачи']]
                            ], true, true);
                            $bot->sendMessage($chatId, "Неизвестная команда.", null, false, null, $keyboard);
                            break;
                    }

                    $mysqli->commit();
                } catch (Exception $e) {
                    $mysqli->rollback();
                    $bot->sendMessage($chatId, "Произошла ошибка. Попробуйте еще раз.");
                }
            }

            $lastUpdateId = $update->getUpdateId();
        }
    } catch (\TelegramBot\Api\HttpException $e) {
        error_log("Telegram API Error: " . $e->getMessage());
        sleep(5);
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());
        sleep(5);
    }

    sleep(1);
}
