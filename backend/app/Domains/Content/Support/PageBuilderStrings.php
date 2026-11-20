<?php

namespace App\Domains\Content\Support;

/**
 * Interface strings of the page-builder editor script
 * (resources/js/page-builder), translated server-side (lang/ar.json) and
 * embedded in the editor page.
 */
class PageBuilderStrings
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $keys = [
            'Add',
            'Add a block',
            'Add at least :min.',
            'Add section',
            'All changes saved',
            'All sections',
            'Alt text',
            'Always show',
            'Block type',
            'Bold',
            'Bullet list',
            'Choose a block…',
            'Clear',
            'Clear formatting',
            'Copied',
            'Copy',
            'Current draft',
            'Default',
            'Delete',
            'Delete this section? You can restore it from the revision history after saving.',
            'Discard all unsaved changes?',
            'Drag to reorder',
            'Duplicate',
            'Ends',
            'Expand / collapse all',
            'Filled by ad bookings',
            'Has errors',
            'Heading',
            'Hidden',
            'Hide',
            'Italic',
            'Item',
            'Link',
            'Link address (https://… or /path)',
            'Move down',
            'Moved to position :n',
            'Move up',
            'Needed before publishing: :list',
            'Needed to publish',
            'Needs: :list',
            'None',
            'No section matches.',
            'No sections yet. Drop one here or add one.',
            'Not available in the :zone zone',
            'Not available in this zone',
            'Not found',
            'Nothing found.',
            'Numbered list',
            'Open',
            'page language',
            'Page settings & publishing',
            'Paragraph',
            '/path, https://… or search',
            'Pick a colour',
            'Raw saved settings (read-only)',
            'Reference image coming from the design',
            'Reference image of :type',
            'Remove',
            'Remove this block?',
            'Remove this item?',
            'saved value',
            'Saving…',
            'Saving failed. Try again.',
            'Scheduled',
            'Search…',
            'Sections in :zone',
            'Show',
            'Shows the last saved version. Super admins only.',
            'Some fields need attention. Nothing was saved.',
            'Starts',
            ':type added',
            'Type and press Enter',
            'Unsaved changes',
            'Upload',
            'Uploaded.',
            'Upload failed.',
            'Uploading…',
            'Visible on the page',
            'You have unsaved changes. Continue without saving them?',
            'Zone: :zone',
        ];

        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => __($key)])->all();
    }
}
