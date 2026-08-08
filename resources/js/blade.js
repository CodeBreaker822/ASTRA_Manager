import focus from '@alpinejs/focus';
import Alpine from 'alpinejs';

// Theme is applied inline in the layout head to avoid a flash of the wrong
// palette; this keeps it in sync when the OS preference changes afterwards.
const applyTheme = (value) => {
    const dark =
        value === 'dark' ||
        (value === 'system' &&
            window.matchMedia('(prefers-color-scheme: dark)').matches);

    document.documentElement.classList.toggle('dark', dark);
};

const storedTheme = () => localStorage.getItem('appearance') || 'system';

export const setTheme = (value) => {
    localStorage.setItem('appearance', value);
    document.cookie = `appearance=${value};path=/;max-age=${365 * 24 * 60 * 60};SameSite=Lax`;
    applyTheme(value);
};

applyTheme(storedTheme());

window
    .matchMedia('(prefers-color-scheme: dark)')
    .addEventListener('change', () => applyTheme(storedTheme()));

window.setTheme = setTheme;
window.storedTheme = storedTheme;

/**
 * Repeatable form list: add, remove, and reorder items while keeping the
 * `name="a[0][b]"` indices contiguous so PHP parses them back as a list.
 *
 * Inputs carry `data-name` holding the template (with `__i__` for the index);
 * re-indexing rewrites `name` from it after every mutation.
 */
Alpine.data('repeater', () => ({
    init() {
        this.reindex();
    },

    get items() {
        return [...this.$el.querySelectorAll(':scope > [data-repeater-item]')];
    },

    reindex() {
        this.items.forEach((item, index) => {
            item.querySelectorAll('[data-name]').forEach((field) => {
                field.setAttribute(
                    'name',
                    field.dataset.name.replace('__i__', index),
                );
            });

            const position = item.querySelector('[data-repeater-position]');

            if (position) {
                position.textContent = index + 1;
            }
        });
    },

    add() {
        const template = this.$el.querySelector(
            ':scope > template[data-repeater-template]',
        );

        if (!template) {
            return;
        }

        const list = this.$el.querySelector('[data-repeater-list]') ?? this.$el;
        list.appendChild(template.content.cloneNode(true));
        this.reindex();
    },

    remove(event) {
        // Keep at least one row; the server rules require a non-empty list.
        if (this.items.length <= 1) {
            return;
        }

        event.target.closest('[data-repeater-item]').remove();
        this.reindex();
    },

    move(event, offset) {
        const item = event.target.closest('[data-repeater-item]');
        const target =
            offset < 0 ? item.previousElementSibling : item.nextElementSibling;

        if (!target?.matches('[data-repeater-item]')) {
            return;
        }

        if (offset < 0) {
            target.before(item);
        } else {
            target.after(item);
        }

        this.reindex();
    },
}));

Alpine.plugin(focus);
window.Alpine = Alpine;
Alpine.start();
