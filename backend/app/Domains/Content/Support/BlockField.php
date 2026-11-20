<?php

namespace App\Domains\Content\Support;

use JsonSerializable;

/**
 * One field of a page-builder block settings schema ({@see BlockSchemas}).
 * The admin editor renders it as a real input and {@see BlockSettings}
 * validates and stores it. Types: text, textarea, rich_text, number, select,
 * toggle, checkbox_group, color, datetime, datetime_range, image, link,
 * tags, product_picker, category_picker, brand_picker, coupon_picker,
 * flash_sale_picker, ad_slot_picker, stream_picker, vendor_picker, group
 * (an object of sub-fields), repeater (a list of sub-field objects),
 * block (one child block {type, settings}) and blocks (a list of them).
 * Content fields are `translatable` and stored as `{"en": …, "ar": …}`:
 * every text, rich text, keyword list, image (with its alt text) and link.
 * Images and links default to "same for Arabic" (only `en` set; the
 * storefront falls back to it). Counts, colours, sources, layout and
 * schedule are shared by both languages.
 */
class BlockField implements JsonSerializable
{
    public const GROUPS = ['content', 'products', 'design', 'visibility', 'advanced'];

    /**
     * @var array<string, mixed>
     */
    private array $definition;

    private function __construct(string $type, string $key, string $label)
    {
        $this->definition = ['type' => $type, 'key' => $key, 'label' => $label, 'group' => 'content'];
    }

    public static function make(string $type, string $key, string $label): self
    {
        return new self($type, $key, $label);
    }

    public static function text(string $key, string $label): self
    {
        return new self('text', $key, $label);
    }

    public static function textarea(string $key, string $label): self
    {
        return new self('textarea', $key, $label);
    }

    public static function richText(string $key, string $label): self
    {
        return new self('rich_text', $key, $label);
    }

    public static function number(string $key, string $label, ?int $min = null, ?int $max = null): self
    {
        $field = new self('number', $key, $label);
        $field->definition['min'] = $min;
        $field->definition['max'] = $max;

        return $field;
    }

    /**
     * @param  array<string, string>  $options  value => label
     */
    public static function select(string $key, string $label, array $options): self
    {
        $field = new self('select', $key, $label);
        $field->definition['options'] = $options;

        return $field;
    }

    public static function toggle(string $key, string $label): self
    {
        return new self('toggle', $key, $label);
    }

    /**
     * @param  array<string, string>  $options
     */
    public static function checkboxGroup(string $key, string $label, array $options): self
    {
        $field = new self('checkbox_group', $key, $label);
        $field->definition['options'] = $options;

        return $field;
    }

    public static function color(string $key, string $label): self
    {
        return new self('color', $key, $label);
    }

    public static function datetime(string $key, string $label): self
    {
        return new self('datetime', $key, $label);
    }

    /**
     * Images and links are content: EN and AR values, the Arabic one
     * falling back to the English one ("Use the same … for Arabic").
     */
    public static function image(string $key, string $label): self
    {
        return (new self('image', $key, $label))->translatable();
    }

    public static function link(string $key, string $label): self
    {
        return (new self('link', $key, $label))->translatable();
    }

    public static function tags(string $key, string $label): self
    {
        return new self('tags', $key, $label);
    }

    /**
     * Picker of a catalogue / marketing record (live search endpoint `kind`).
     */
    public static function picker(string $kind, string $key, string $label): self
    {
        return new self($kind.'_picker', $key, $label);
    }

    /**
     * @param  array<int, BlockField>  $fields
     */
    public static function group(string $key, string $label, array $fields): self
    {
        $field = new self('group', $key, $label);
        $field->definition['fields'] = $fields;

        return $field;
    }

    /**
     * @param  array<int, BlockField>  $fields
     */
    public static function repeater(string $key, string $label, array $fields, int $min = 0, ?int $max = null): self
    {
        $field = new self('repeater', $key, $label);
        $field->definition['fields'] = $fields;
        $field->definition['min'] = $min;
        $field->definition['max'] = $max;

        return $field;
    }

    public static function block(string $key, string $label): self
    {
        return new self('block', $key, $label);
    }

    public static function blocks(string $key, string $label, int $min = 0, ?int $max = null): self
    {
        $field = new self('blocks', $key, $label);
        $field->definition['min'] = $min;
        $field->definition['max'] = $max;

        return $field;
    }

    public function help(string $help): self
    {
        $this->definition['help'] = $help;

        return $this;
    }

    public function translatable(bool $translatable = true): self
    {
        $this->definition['translatable'] = $translatable;

        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->definition['required'] = $required;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->definition['default'] = $value;

        return $this;
    }

    public function in(string $group): self
    {
        $this->definition['group'] = $group;

        return $this;
    }

    /**
     * Several values (pickers: an ordered list instead of one value).
     */
    public function multiple(?int $max = null): self
    {
        $this->definition['multiple'] = true;
        $this->definition['max'] = $max;

        return $this;
    }

    /**
     * Show only when sibling fields match: `['source' => 'manual']`,
     * `['source' => ['category', 'brand']]`, `['ad_slot_key' => '']` (empty)
     * or `['variant' => '!cards']` (anything but).
     *
     * @param  array<string, mixed>  $conditions
     */
    public function showIf(array $conditions): self
    {
        $this->definition['show_if'] = $conditions;

        return $this;
    }

    /**
     * Picker side effect: copy fields of the picked record into siblings
     * (`['label' => 'name', 'image' => 'image', 'link' => 'link']`).
     *
     * @param  array<string, string>  $map
     */
    public function fills(array $map): self
    {
        $this->definition['fills'] = $map;

        return $this;
    }

    /**
     * Picker side effect: forget these sibling keys when a value is picked.
     *
     * @param  array<int, string>  $keys
     */
    public function clears(array $keys): self
    {
        $this->definition['clears'] = $keys;

        return $this;
    }

    /**
     * Image: also edit the alt text (`alt` key, EN/AR).
     */
    public function withAlt(string $altKey = 'alt'): self
    {
        $this->definition['alt'] = $altKey;

        return $this;
    }

    /**
     * Label template of a repeater item (first non-empty sub-field key).
     *
     * @param  array<int, string>  $keys
     */
    public function itemTitle(array $keys): self
    {
        $this->definition['item_title'] = $keys;

        return $this;
    }

    /**
     * Unit shown after a number (`s`, `px`, `items`, `currency`…).
     */
    public function unit(string $unit): self
    {
        $this->definition['unit'] = $unit;

        return $this;
    }

    public function placeholder(string $placeholder): self
    {
        $this->definition['placeholder'] = $placeholder;

        return $this;
    }

    public function get(string $attribute, mixed $default = null): mixed
    {
        return $this->definition[$attribute] ?? $default;
    }

    public function key(): string
    {
        return $this->definition['key'];
    }

    public function type(): string
    {
        return $this->definition['type'];
    }

    /**
     * @return array<int, BlockField>
     */
    public function fields(): array
    {
        return $this->definition['fields'] ?? [];
    }

    /**
     * The definition for the editor, with labels, help and options in the admin's language.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $definition = $this->definition;
        $definition['label'] = __($definition['label']);

        if (isset($definition['help'])) {
            $definition['help'] = __($definition['help']);
        }

        if (isset($definition['unit']) && $definition['unit'] !== 'currency') {
            $definition['unit'] = __($definition['unit']);
        }

        if (isset($definition['placeholder'])) {
            $definition['placeholder'] = __($definition['placeholder']);
        }

        if (isset($definition['options'])) {
            $definition['options'] = collect($definition['options'])->map(fn (string $label, string|int $value): array => ['value' => (string) $value, 'label' => __($label)])->values()->all();
        }

        if (isset($definition['fields'])) {
            $definition['fields'] = array_map(fn (BlockField $field): array => $field->jsonSerialize(), $definition['fields']);
        }

        return $definition;
    }
}
