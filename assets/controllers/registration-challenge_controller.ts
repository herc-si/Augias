/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Loads the ALTCHA widget (the <altcha-widget> element inside this controller)
 * on the registration page only: the proof of work that stands in for a
 * captcha, computed in the browser, with nothing loaded from elsewhere.
 */
export default class extends Controller {
    async connect(): Promise<void> {
        await import('altcha');
        await import('altcha/i18n/fr-fr');
    }
}
