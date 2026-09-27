import Encore from '@solidworx/platform/webpack.config.js';

import { codecovWebpackPlugin } from '@codecov/webpack-plugin';

export default Encore
    .addStyleEntry('login', './assets/scss/login.scss')
    .addStyleEntry('register', './assets/scss/register.scss')
    .addStyleEntry('installation', './assets/scss/installation.scss')
    .addStyleEntry('pdf', './assets/scss/pdf.scss')
    .addStyleEntry('email-colors', './assets/scss/email/email-colors.scss')
    .addStyleEntry('email-modern', './assets/scss/email/modern.scss')
    .addEntry('app', './assets/app.ts')
    .enableStimulusBridge('./assets/controllers.json')
    // Hashed file names in dev too: a browser (a phone on the VPN) otherwise
    // keeps the previous app.css after a rebuild, the name being the same.
    .enableVersioning()
    .addPlugin(codecovWebpackPlugin({
        enableBundleAnalysis: Encore.isProduction() && process.env.CODECOV_TOKEN !== undefined,
        bundleName: 'solidinvoice-webpack-bundle',
        uploadToken: process.env.CODECOV_TOKEN,
    }))
    .getWebpackConfig();