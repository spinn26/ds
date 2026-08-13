<?php

namespace App\Support;

/**
 * Хелперы для контента, введённого через RichTextEditor (новости и т.п.).
 *
 * В БД такой контент лежит как HTML (innerHTML редактора). Для каналов,
 * которые HTML не понимают — Telegram с Markdown-разметкой, письма в
 * plain-text, превью в списках — нужен обычный текст с сохранёнными
 * переносами строк.
 */
class Html
{
    /**
     * HTML → plain text: блочные теги превращаем в переносы строк,
     * остальные вырезаем, HTML-сущности декодируем.
     */
    public static function toPlainText(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $text = preg_replace('~<br\s*/?>~i', "\n", $html) ?? $html;
        // Закрывающие блочные теги = конец абзаца/строки.
        $text = preg_replace('~</(p|div|li|h[1-6]|blockquote|tr)\s*>~i', "\n", $text) ?? $text;
        $text = preg_replace('~<li[^>]*>~i', '• ', $text) ?? $text;
        $text = preg_replace('~<hr\s*/?>~i', "\n—\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // NBSP из редактора → обычный пробел; хвостовые пробелы в строках убираем.
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('~[ \t]+\n~', "\n", $text) ?? $text;
        // Больше двух пустых строк подряд не нужно.
        $text = preg_replace('~\n{3,}~', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
