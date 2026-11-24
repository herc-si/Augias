/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

import { Controller } from '@hotwired/stimulus';

interface Message {
    role: 'user' | 'assistant';
    content: string;
}

interface State {
    messages: Message[];
    pending: boolean;
    error: string | null;
}

/** Whether the panel was left open, so that the next page opens it again. */
const OPEN_KEY = 'augias.assistant.open';
/** What was being typed, kept from one page to the next. */
const DRAFT_KEY = 'augias.assistant.draft';
/** How often a question asked from another page is looked at again. */
const POLL_MS = 2000;

/**
 * The assistant's side panel, on every page: the conversation is the
 * server's (AssistantMessagesAction), the panel only shows it.
 */
export default class extends Controller<HTMLElement> {
    static targets = ['messages', 'empty', 'thinking', 'error', 'input', 'submit', 'scroller', 'reset'];

    static values = {
        url: String,
        token: String,
        error: String,
    };

    declare readonly messagesTarget: HTMLElement;
    declare readonly emptyTarget: HTMLElement;
    declare readonly thinkingTarget: HTMLElement;
    declare readonly errorTarget: HTMLElement;
    declare readonly inputTarget: HTMLTextAreaElement;
    declare readonly submitTarget: HTMLButtonElement;
    declare readonly scrollerTarget: HTMLElement;
    declare readonly resetTarget: HTMLButtonElement;

    declare readonly urlValue: string;
    declare readonly tokenValue: string;
    declare readonly errorValue: string;

    private busy = false;
    private poll: number | null = null;
    private shown: Message[] = [];
    /** Bumped by each send: a read that left before it is out of date. */
    private generation = 0;

    private readonly onToggleClick = (event: Event): void => {
        const toggle = (event.target as Element | null)?.closest('[data-assistant-toggle]');

        if (toggle) {
            event.preventDefault();
            this.toggle();
        }
    };

    private readonly onKeydown = (event: KeyboardEvent): void => {
        if (event.key === 'Escape' && this.isOpen() && this.element.contains(document.activeElement)) {
            this.close();
        }
    };

    connect(): void {
        document.addEventListener('click', this.onToggleClick);
        document.addEventListener('keydown', this.onKeydown);

        this.inputTarget.value = sessionStorage.getItem(DRAFT_KEY) ?? '';

        if (localStorage.getItem(OPEN_KEY) === '1') {
            this.open(false);
        }
    }

    disconnect(): void {
        document.removeEventListener('click', this.onToggleClick);
        document.removeEventListener('keydown', this.onKeydown);
        this.stopPolling();
    }

    toggle(): void {
        if (this.isOpen()) {
            this.close();
        } else {
            this.open(true);
        }
    }

    open(focus: boolean): void {
        this.element.hidden = false;
        document.body.classList.add('assistant-open');
        localStorage.setItem(OPEN_KEY, '1');
        this.syncToggles(true);
        void this.load();

        if (focus) {
            this.inputTarget.focus();
        }
    }

    close(): void {
        this.element.hidden = true;
        document.body.classList.remove('assistant-open');
        localStorage.removeItem(OPEN_KEY);
        this.syncToggles(false);
        this.stopPolling();
    }

    keydown(event: KeyboardEvent): void {
        // Enter sends, Shift+Enter goes to the next line.
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            this.element.querySelector('form')?.requestSubmit();
        }
    }

    keepDraft(): void {
        sessionStorage.setItem(DRAFT_KEY, this.inputTarget.value);
    }

    example(event: Event): void {
        this.inputTarget.value = (event.currentTarget as HTMLElement).textContent?.trim() ?? '';
        this.element.querySelector('form')?.requestSubmit();
    }

    async ask(event: Event): Promise<void> {
        event.preventDefault();

        const question = this.inputTarget.value.trim();

        if (question === '' || this.busy) {
            return;
        }

        this.inputTarget.value = '';
        sessionStorage.removeItem(DRAFT_KEY);

        // Shown at once; the server's answer replaces the whole list.
        ++this.generation;
        this.render({ messages: [...this.shown, { role: 'user', content: question }], pending: true, error: null });

        await this.send(new URLSearchParams({ question, _token: this.tokenValue }), question);
    }

    async reset(): Promise<void> {
        if (this.busy) {
            return;
        }

        await this.send(new URLSearchParams({ reset: '1', _token: this.tokenValue }));
        this.inputTarget.focus();
    }

    private async load(): Promise<void> {
        const generation = this.generation;

        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const state = await response.json() as State;

            if (generation === this.generation && !this.busy) {
                this.render(state);
            }
        } catch {
            // The panel stays as it was; the next action tries again.
        }
    }

    private async send(body: URLSearchParams, question?: string): Promise<void> {
        ++this.generation;
        this.setBusy(true);

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                body,
            });
            const state = await response.json() as State;
            this.render(state);

            if (!response.ok && question !== undefined && !state.pending) {
                // Not asked: given back to be sent again.
                this.inputTarget.value = question;
            }
        } catch {
            this.render({ messages: this.shown, pending: false, error: this.errorValue });

            if (question !== undefined) {
                this.inputTarget.value = question;
            }
        } finally {
            this.setBusy(false);
        }
    }

    private render(state: State): void {
        this.shown = state.messages;
        this.messagesTarget.replaceChildren(...state.messages.map((message) => this.bubble(message)));
        this.emptyTarget.hidden = state.messages.length > 0;
        this.thinkingTarget.hidden = !state.pending;
        this.errorTarget.hidden = state.error === null;
        this.errorTarget.textContent = state.error ?? '';
        this.resetTarget.disabled = state.messages.length === 0;

        // A question asked from another page: its answer is looked for again.
        if (state.pending && !this.busy) {
            this.startPolling();
        } else if (!state.pending) {
            this.stopPolling();
        }

        this.scrollerTarget.scrollTop = this.scrollerTarget.scrollHeight;
    }

    private bubble(message: Message): HTMLElement {
        const element = document.createElement('div');
        element.className = `assistant-message assistant-message-${message.role}`;

        if (message.role === 'assistant') {
            element.append(...formatted(message.content));
        } else {
            element.textContent = message.content;
        }

        return element;
    }

    private setBusy(busy: boolean): void {
        this.busy = busy;
        this.submitTarget.disabled = busy;
    }

    private startPolling(): void {
        if (this.poll === null) {
            this.poll = window.setInterval(() => void this.load(), POLL_MS);
        }
    }

    private stopPolling(): void {
        if (this.poll !== null) {
            window.clearInterval(this.poll);
            this.poll = null;
        }
    }

    private isOpen(): boolean {
        return !this.element.hidden;
    }

    private syncToggles(open: boolean): void {
        document.querySelectorAll('[data-assistant-toggle]').forEach((toggle) => {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
}

/**
 * The little Markdown models write — **bold**, `code`, "- " lists — as
 * nodes, never as HTML: the answer is built from the company's data.
 */
function formatted(text: string): Node[] {
    const nodes: Node[] = [];
    let list: HTMLUListElement | null = null;

    for (const line of text.split('\n')) {
        const item = /^\s*[-*•]\s+(.*)$/.exec(line);

        if (item) {
            if (list === null) {
                list = document.createElement('ul');
                nodes.push(list);
            }

            const li = document.createElement('li');
            li.append(...inline(item[1]));
            list.append(li);
            continue;
        }

        list = null;

        if (line.trim() === '') {
            continue;
        }

        const p = document.createElement('p');
        p.append(...inline(line.replace(/^#+\s*/, '')));
        nodes.push(p);
    }

    return nodes;
}

function inline(text: string): Node[] {
    const nodes: Node[] = [];
    const pattern = /\*\*(.+?)\*\*|`([^`]+)`/g;
    let last = 0;

    for (const match of text.matchAll(pattern)) {
        const index = match.index ?? 0;
        nodes.push(document.createTextNode(text.slice(last, index)));

        const element = document.createElement(match[1] !== undefined ? 'strong' : 'code');
        element.textContent = match[1] ?? match[2];
        nodes.push(element);
        last = index + match[0].length;
    }

    nodes.push(document.createTextNode(text.slice(last)));

    return nodes;
}
