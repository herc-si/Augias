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

interface Action {
    id: string;
    title: string;
    details: { label: string; value: string }[];
}

interface Notice {
    text: string;
    button: { label: string; action?: string; href?: string } | null;
}

interface State {
    messages: Message[];
    pending: boolean;
    action: Action | null;
    notice?: Notice | null;
    usage?: string | null;
    canDeactivate?: boolean;
    error: string | null;
}

/** Whether the panel was left open, so that the next page opens it again. */
const OPEN_KEY = 'augias.assistant.open';
/** What was being typed, kept from one page to the next. */
const DRAFT_KEY = 'augias.assistant.draft';
/** How often a question asked from another page is looked at again. */
const POLL_MS = 2000;
/** A recording is cut there: a question, not a dictation. */
const MAX_RECORDING_MS = 60000;

/**
 * The assistant's side panel, on every page: the conversation is the
 * server's (AssistantMessagesAction), the panel only shows it. A change the
 * assistant proposes comes as a card to confirm or cancel; the microphone
 * records a question and has it written out (AssistantTranscribeAction).
 */
export default class extends Controller<HTMLElement> {
    static targets = ['messages', 'empty', 'thinking', 'error', 'input', 'submit', 'scroller', 'reset', 'action', 'mic', 'status', 'notice', 'usage', 'deactivate'];

    static values = {
        url: String,
        token: String,
        error: String,
        confirmLabel: String,
        cancelLabel: String,
        transcribeUrl: String,
        listeningLabel: String,
        transcribingLabel: String,
        deactivateConfirm: String,
    };

    declare readonly messagesTarget: HTMLElement;
    declare readonly emptyTarget: HTMLElement;
    declare readonly thinkingTarget: HTMLElement;
    declare readonly errorTarget: HTMLElement;
    declare readonly inputTarget: HTMLTextAreaElement;
    declare readonly submitTarget: HTMLButtonElement;
    declare readonly scrollerTarget: HTMLElement;
    declare readonly resetTarget: HTMLButtonElement;
    declare readonly actionTarget: HTMLElement;
    declare readonly micTarget: HTMLButtonElement;
    declare readonly statusTarget: HTMLElement;
    declare readonly noticeTarget: HTMLElement;
    declare readonly usageTarget: HTMLElement;
    declare readonly deactivateTarget: HTMLButtonElement;

    declare readonly urlValue: string;
    declare readonly tokenValue: string;
    declare readonly errorValue: string;
    declare readonly confirmLabelValue: string;
    declare readonly cancelLabelValue: string;
    declare readonly transcribeUrlValue: string;
    declare readonly listeningLabelValue: string;
    declare readonly transcribingLabelValue: string;
    declare readonly deactivateConfirmValue: string;

    private busy = false;
    private poll: number | null = null;
    private shown: Message[] = [];
    /** Bumped by each send: a read that left before it is out of date. */
    private generation = 0;
    private recorder: MediaRecorder | null = null;
    private recordingTimer: number | null = null;
    private hint = '';
    private blocked = false;
    private lastNotice: Notice | null = null;

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
        this.hint = this.statusTarget.textContent ?? '';
        this.micTarget.hidden = !this.voiceAvailable();

        if (localStorage.getItem(OPEN_KEY) === '1') {
            this.open(false);
        }
    }

    disconnect(): void {
        document.removeEventListener('click', this.onToggleClick);
        document.removeEventListener('keydown', this.onKeydown);
        this.stopPolling();
        this.recorder?.stop();
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

        if (question === '' || this.busy || this.blocked) {
            return;
        }

        this.inputTarget.value = '';
        sessionStorage.removeItem(DRAFT_KEY);

        // Shown at once; the server's answer replaces the whole list.
        ++this.generation;
        this.render({ messages: [...this.shown, { role: 'user', content: question }], pending: true, action: null, error: null });

        await this.send(new URLSearchParams({ question, _token: this.tokenValue }), question);
    }

    async reset(): Promise<void> {
        if (this.busy) {
            return;
        }

        await this.send(new URLSearchParams({ reset: '1', _token: this.tokenValue }));
        this.inputTarget.focus();
    }

    async activate(): Promise<void> {
        await this.send(new URLSearchParams({ activate: '1', _token: this.tokenValue }));
        this.inputTarget.focus();
    }

    async deactivate(): Promise<void> {
        if (window.confirm(this.deactivateConfirmValue)) {
            await this.send(new URLSearchParams({ deactivate: '1', _token: this.tokenValue }));
        }
    }

    async confirm(event: Event): Promise<void> {
        await this.settle('confirm', (event.currentTarget as HTMLElement).dataset.actionId ?? '');
    }

    async cancel(event: Event): Promise<void> {
        await this.settle('cancel', (event.currentTarget as HTMLElement).dataset.actionId ?? '');
    }

    async voice(): Promise<void> {
        if (this.recorder !== null) {
            this.recorder.stop();

            return;
        }

        let stream: MediaStream;

        try {
            stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch {
            this.render({ messages: this.shown, pending: false, action: null, error: this.errorValue });

            return;
        }

        const type = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus', 'audio/webm']
            .find((candidate) => MediaRecorder.isTypeSupported(candidate));
        const recorder = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
        const chunks: Blob[] = [];

        recorder.addEventListener('dataavailable', (e) => chunks.push(e.data));
        recorder.addEventListener('stop', () => {
            stream.getTracks().forEach((track) => track.stop());
            this.recorder = null;

            if (this.recordingTimer !== null) {
                window.clearTimeout(this.recordingTimer);
                this.recordingTimer = null;
            }

            this.setListening(false);
            void this.transcribe(new Blob(chunks, { type: recorder.mimeType || 'audio/webm' }));
        });

        this.recorder = recorder;
        recorder.start();
        this.setListening(true);
        this.recordingTimer = window.setTimeout(() => recorder.stop(), MAX_RECORDING_MS);
    }

    private async transcribe(audio: Blob): Promise<void> {
        if (audio.size === 0) {
            return;
        }

        this.setStatus(this.transcribingLabelValue);
        this.setBusy(true);

        const body = new FormData();
        body.append('_token', this.tokenValue);
        body.append('audio', audio, 'voice');

        let text = '';

        try {
            const response = await fetch(this.transcribeUrlValue, { method: 'POST', credentials: 'same-origin', body });
            const result = await response.json() as { text?: string; error?: string };

            if (!response.ok || !result.text) {
                this.render({ messages: this.shown, pending: false, action: null, error: result.error ?? this.errorValue });
            } else {
                text = result.text;
            }
        } catch {
            this.render({ messages: this.shown, pending: false, action: null, error: this.errorValue });
        }

        this.setBusy(false);
        this.setStatus(this.hint);

        if (text !== '') {
            // Sent as said; a change it leads to is still shown before it is made.
            this.inputTarget.value = text;
            this.element.querySelector('form')?.requestSubmit();
        }
    }

    private voiceAvailable(): boolean {
        return this.transcribeUrlValue !== ''
            && typeof MediaRecorder !== 'undefined'
            && navigator.mediaDevices?.getUserMedia !== undefined;
    }

    private setListening(listening: boolean): void {
        this.micTarget.classList.toggle('is-listening', listening);
        this.micTarget.setAttribute('aria-pressed', listening ? 'true' : 'false');
        this.submitTarget.disabled = listening;
        this.setStatus(listening ? this.listeningLabelValue : this.hint);
    }

    private setStatus(text: string): void {
        this.statusTarget.textContent = text;
    }

    private async settle(kind: 'confirm' | 'cancel', id: string): Promise<void> {
        if (this.busy || id === '') {
            return;
        }

        this.actionTarget.querySelectorAll('button').forEach((button) => { button.disabled = true; });

        if (kind === 'confirm') {
            this.thinkingTarget.hidden = false;
        }

        await this.send(new URLSearchParams({ [kind]: id, _token: this.tokenValue }));
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
            this.render({ messages: this.shown, pending: false, action: null, error: this.errorValue });

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
        this.resetTarget.disabled = state.messages.length === 0 && state.action === null;
        this.renderAction(state.action);
        this.renderAccess(state);

        // A question asked from another page: its answer is looked for again.
        if (state.pending && !this.busy) {
            this.startPolling();
        } else if (!state.pending) {
            this.stopPolling();
        }

        this.scrollerTarget.scrollTop = this.scrollerTarget.scrollHeight;
    }

    private renderAccess(state: State): void {
        const notice = state.notice === undefined ? this.lastNotice : state.notice;
        this.lastNotice = notice;
        this.blocked = notice !== null;
        this.noticeTarget.hidden = notice === null;
        this.noticeTarget.replaceChildren();
        this.inputTarget.disabled = this.blocked;
        this.submitTarget.disabled = this.blocked || this.busy;
        this.micTarget.disabled = this.blocked;
        if (state.usage !== undefined) {
            this.usageTarget.textContent = state.usage ?? '';
        }

        if (state.canDeactivate !== undefined) {
            this.deactivateTarget.hidden = !state.canDeactivate;
        }

        if (notice === null) {
            return;
        }

        // The examples invite a question that cannot be asked yet.
        this.emptyTarget.hidden = true;

        const text = document.createElement('p');
        text.textContent = notice.text;
        this.noticeTarget.append(text);

        if (notice.button?.href) {
            const link = document.createElement('a');
            link.className = 'btn btn-primary';
            link.href = notice.button.href;
            link.textContent = notice.button.label;
            this.noticeTarget.append(link);
        } else if (notice.button?.action === 'activate') {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-primary';
            button.textContent = notice.button.label;
            button.dataset.action = 'assistant-panel#activate';
            this.noticeTarget.append(button);
        }
    }

    private renderAction(action: Action | null): void {
        this.actionTarget.hidden = action === null;
        this.actionTarget.replaceChildren();

        if (action === null) {
            return;
        }

        const title = document.createElement('div');
        title.className = 'assistant-action-title';
        title.textContent = action.title;

        const details = document.createElement('dl');
        details.className = 'assistant-action-details';
        for (const detail of action.details) {
            const term = document.createElement('dt');
            term.textContent = detail.label;
            const value = document.createElement('dd');
            value.textContent = detail.value;
            details.append(term, value);
        }

        const buttons = document.createElement('div');
        buttons.className = 'assistant-action-buttons';
        buttons.append(
            this.actionButton(this.cancelLabelValue, 'btn btn-ghost', 'cancel', action.id),
            this.actionButton(this.confirmLabelValue, 'btn btn-primary', 'confirm', action.id),
        );

        this.actionTarget.append(title, details, buttons);
    }

    private actionButton(label: string, className: string, method: 'confirm' | 'cancel', id: string): HTMLButtonElement {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.textContent = label;
        button.dataset.action = `assistant-panel#${method}`;
        button.dataset.actionId = id;

        return button;
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
        this.submitTarget.disabled = busy || this.blocked;
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
