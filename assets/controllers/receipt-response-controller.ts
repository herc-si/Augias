import { Controller } from '@hotwired/stimulus';

/**
 * Answer to a received invoice: offers the reasons the chosen answer can
 * carry. A refusal (fr:210) takes only a refusal reason, a dispute (fr:207)
 * any, an acceptance (fr:205) none — the form checks it again on the server.
 *
 * @stimulusFetch lazy
 */
export default class ReceiptResponseController extends Controller<HTMLElement> {
    static targets: string[] = ['reason'];

    declare reasonTarget: HTMLElement;

    connect(): void {
        this.update();
    }

    update(): void {
        const answer = this.element.querySelector<HTMLInputElement>('input[type="radio"]:checked')?.value ?? null;
        const select = this.reasonTarget.querySelector<HTMLSelectElement>('select');

        if (!select) {
            return;
        }

        // Accepted: nothing to say why.
        this.reasonTarget.classList.toggle('d-none', answer === 'fr:205');

        if (answer === 'fr:205') {
            select.value = '';

            return;
        }

        const refusal = answer === 'fr:210';

        select.querySelectorAll<HTMLOptionElement>('option[data-refusal]').forEach((option) => {
            const offered = !refusal || option.dataset.refusal === '1';

            option.hidden = !offered;
            option.disabled = !offered;

            if (!offered && option.selected) {
                select.value = '';
            }
        });

        // A group left with nothing to choose goes too.
        select.querySelectorAll<HTMLOptGroupElement>('optgroup').forEach((group) => {
            group.hidden = Array.from(group.querySelectorAll('option')).every((option) => option.hidden);
        });
    }
}
