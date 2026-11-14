/**
 * Live "= … Oman time" helper under <x-ui.datetime-input> fields.
 * Converts a wall-clock time in the country's zone to the admin zone.
 */
function zonedParts(date, timeZone) {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
    }).formatToParts(date);

    return Object.fromEntries(parts.filter((part) => part.type !== 'literal').map((part) => [part.type, Number(part.value)]));
}

function localWallTimeToInstant(value, timeZone) {
    const [datePart, timePart] = value.split('T');
    const [year, month, day] = datePart.split('-').map(Number);
    const [hour, minute] = timePart.split(':').map(Number);
    const guess = Date.UTC(year, month - 1, day, hour, minute);
    const seen = zonedParts(new Date(guess), timeZone);
    const offset = Date.UTC(seen.year, seen.month - 1, seen.day, seen.hour, seen.minute) - guess;

    return new Date(guess - offset);
}

export function adminTimeLabel(value, countryZone, adminZone) {
    const instant = localWallTimeToInstant(value, countryZone);
    const admin = zonedParts(instant, adminZone);
    const local = value.slice(0, 10);
    const adminDate = `${admin.year}-${String(admin.month).padStart(2, '0')}-${String(admin.day).padStart(2, '0')}`;
    const dayDifference = Math.round((Date.parse(adminDate) - Date.parse(local)) / 86400000);
    const hour12 = admin.hour % 12 || 12;
    const suffix = dayDifference === 0 ? '' : ` (${dayDifference > 0 ? '+' : ''}${dayDifference})`;

    return `= ${hour12}:${String(admin.minute).padStart(2, '0')} ${admin.hour < 12 ? 'AM' : 'PM'}${suffix} Oman time`;
}

export function initCountryDatetimeFields(root = document) {
    root.querySelectorAll('[data-country-datetime]').forEach((field) => {
        const input = field.querySelector('input[type="datetime-local"]');
        const label = field.querySelector('[data-admin-time-label]');

        input.addEventListener('input', () => {
            if (input.value) {
                label.textContent = adminTimeLabel(input.value, field.dataset.timezone, field.dataset.adminTimezone);
            }
        });
    });
}
