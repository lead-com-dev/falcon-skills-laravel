<?php

namespace FalconSkills;

final class ToolResult
{
    /**
     * @param  array<string, mixed>|null  $structured
     */
    private function __construct(
        public readonly string $text,
        public readonly ?array $structured,
        public readonly bool $isError,
    ) {
    }

    /**
     * The card the Falcon Skills widget renders: a one-line summary, label and
     * value pairs, and links. Sent as structured content and as JSON text.
     *
     * @param  list<array{label: string, value: string}>  $fields
     * @param  list<array{label: string, url: string}>  $links
     */
    public static function card(string $summary, array $fields = [], array $links = []): self
    {
        $card = [
            'summary' => $summary,
            'fields' => array_values($fields),
            'links' => array_values($links),
        ];

        return new self(
            json_encode($card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $card,
            false,
        );
    }

    public static function text(string $text): self
    {
        return new self($text, null, false);
    }

    /**
     * A message the visitor sees as the tool's answer, such as "Enter a valid
     * domain". Keep internals out of it.
     */
    public static function error(string $message): self
    {
        return new self($message, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'content' => [['type' => 'text', 'text' => $this->text]],
            'isError' => $this->isError,
        ];

        if ($this->structured !== null) {
            $result['structuredContent'] = $this->structured;
        }

        return $result;
    }
}
