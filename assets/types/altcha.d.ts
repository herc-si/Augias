/*
 * This file is part of Augias project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

// The ALTCHA widget registers the <altcha-widget> element when imported; its
// package declares its types through "exports", which this project's
// "node" module resolution does not read. Webpack resolves them fine.
declare module 'altcha';
declare module 'altcha/i18n/fr-fr';
