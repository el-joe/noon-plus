<?php

namespace App\Shared\Validation;

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Validation messages name fields in words, in the user's language
 * (browser test BT-ADM-005): "The name (English) field is required." rather
 * than "The name en field is required.", "street" for `values.street`,
 * "category" for `category_id`, "quantity (row 2)" for `lines.1.quantity`.
 *
 * Inline attribute names and `validation.attributes` lines still win; this
 * only replaces Laravel's last resort (the raw key with spaces). Base words
 * are looked up in `validation.attributes` so Arabic messages read Arabic.
 */
class FriendlyValidator extends Validator
{
    public function getDisplayableAttribute($attribute)
    {
        $primaryAttribute = $this->getPrimaryAttribute($attribute);

        foreach (array_unique([$attribute, $primaryAttribute]) as $name) {
            if ($inlineAttribute = $this->getAttributeFromLocalArray($name)) {
                return $inlineAttribute;
            }

            if ($translatedAttribute = $this->getAttributeFromTranslations($name)) {
                return $translatedAttribute;
            }
        }

        if (isset($this->implicitAttributes[$primaryAttribute]) && $this->implicitAttributesFormatter) {
            return ($this->implicitAttributesFormatter)($attribute);
        }

        return $this->friendlyName((string) $attribute);
    }

    private function friendlyName(string $attribute): string
    {
        $row = null;
        $segments = [];

        foreach (explode('.', $attribute) as $segment) {
            if (ctype_digit($segment)) {
                $row = (int) $segment + 1;
            } elseif ($segment !== '*' && $segment !== '') {
                $segments[] = $segment;
            }
        }

        $leaf = end($segments) ?: $attribute;
        $locale = null;

        if (in_array($leaf, ['en', 'ar'], true) && count($segments) > 1) {
            $locale = $leaf;
            $leaf = $segments[count($segments) - 2];
        } elseif (preg_match('/^(.+)_(en|ar)$/', $leaf, $match) === 1) {
            [, $leaf, $locale] = $match;
        }

        $name = $this->getAttributeFromTranslations($leaf)
            ?: str_replace('_', ' ', Str::snake((string) preg_replace('/_id$/', '', $leaf)));

        if ($locale !== null) {
            $name = $this->translator->get('validation.attribute_locales.'.$locale, ['attribute' => $name]);
        }

        if ($row !== null) {
            $name = $this->translator->get('validation.attribute_row', ['attribute' => $name, 'row' => $row]);
        }

        return $name;
    }
}
