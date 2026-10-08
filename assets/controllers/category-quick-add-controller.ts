import { Controller } from '@hotwired/stimulus';

interface TomSelectLike {
    setValue(value: string): void;
}

/**
 * The "+" beside a category dropdown: names a new category and adds it to the
 * dropdown, selected, without leaving the form being filled in. The server
 * side is CoreBundle\Action\Category\QuickAdd.
 *
 * @stimulusFetch lazy
 */
export default class CategoryQuickAddController extends Controller<HTMLElement> {
    static targets: string[] = ['panel', 'name', 'error'];

    static values = {
        url: String,
        usage: String,
        token: String,
    };

    declare panelTarget: HTMLElement;
    declare nameTarget: HTMLInputElement;
    declare errorTarget: HTMLElement;
    declare urlValue: string;
    declare usageValue: string;
    declare tokenValue: string;

    toggle(): void {
        const hidden = this.panelTarget.classList.toggle('d-none');

        if (!hidden) {
            this.nameTarget.focus();
        }
    }

    // Enter would otherwise submit the whole form around the panel.
    keydown(event: KeyboardEvent): void {
        if (event.key === 'Enter') {
            event.preventDefault();
            void this.add();
        }

        if (event.key === 'Escape') {
            this.panelTarget.classList.add('d-none');
        }
    }

    async add(): Promise<void> {
        const name = this.nameTarget.value.trim();

        if (name === '') {
            this.nameTarget.focus();

            return;
        }

        const body = new FormData();
        body.append('name', name);
        body.append('usage', this.usageValue);
        body.append('_token', this.tokenValue);

        const response = await fetch(this.urlValue, { method: 'POST', body, headers: { Accept: 'application/json' } });
        const data = await response.json().catch(() => ({})) as { id?: string; name?: string; error?: string };

        if (!response.ok || !data.id) {
            this.errorTarget.textContent = data.error ?? '';
            this.errorTarget.classList.remove('d-none');

            return;
        }

        this.select(data.id, data.name ?? name);

        this.nameTarget.value = '';
        this.errorTarget.classList.add('d-none');
        this.panelTarget.classList.add('d-none');
    }

    /**
     * Placed in alphabetical order among the others, as the server sorts them.
     * The autocomplete widget watches the <select> and rebuilds itself from it.
     */
    private select(id: string, name: string): void {
        const select = this.element.querySelector<HTMLSelectElement>('select');

        if (!select) {
            return;
        }

        let option = Array.from(select.options).find((candidate) => candidate.value === id);

        if (!option) {
            option = new Option(name, id);
            const next = Array.from(select.options).find(
                (candidate) => candidate.value !== '' && candidate.text.localeCompare(name, undefined, { sensitivity: 'base' }) > 0,
            );
            select.insertBefore(option, next ?? null);
        }

        select.value = id;
        select.dispatchEvent(new Event('change', { bubbles: true }));

        // The widget rebuilds on the next frame; select the entry there too.
        requestAnimationFrame(() => {
            (select as HTMLSelectElement & { tomselect?: TomSelectLike }).tomselect?.setValue(id);
        });
    }
}
