/**
 * Annigems front-end entry point.
 * Loads shared helpers used across auth and SuperAdmin screens.
 */
import './bootstrap';
import './modules/i18n';
import './modules/alerts';
import './modules/button-loading';
import './modules/form-validation';
import { bindPermissionGrids } from './modules/permission-grid';
import { bindBarcodeScanners } from './modules/barcode-scanner';
import { bindStoneForms } from './modules/stone-form';
import { bindStoneImport } from './modules/stone-import';
import { bindStoneFolders } from './modules/stone-folders';
import { bindSelectAll } from './modules/select-all';
import { bindPerPage } from './modules/per-page';
import { bindStoneLabels } from './modules/stone-labels';
import { bindStoneIntake } from './modules/stone-intake';
import { bindStoneImages } from './modules/stone-images';
import { bindCategories } from './modules/categories';
import { bindMetalPurities } from './modules/metal-purities';
import { bindJewelleryItemForm } from './modules/jewellery-item-form';
import { bindQuotationForm } from './modules/quotation-form';
import { bindPos } from './modules/pos';
import { bindPerformaForm } from './modules/performa-form';
import { bindPerformaSettle } from './modules/performa-settle';
import { bindPerformaReturn } from './modules/performa-return';
import { bindModals } from './modules/modal';
import { bindSettingsForms } from './modules/settings';
import { bindSelectSearch } from './modules/select-search';
import { bindShell } from './modules/shell';

/**
 * Bind page widgets. Called on first load and after a kiosk body swap
 * (in-document navigation that keeps native fullscreen alive).
 */
export function bootPage() {
    bindShell();
    window.AnnigemsButton?.bindLoadingForms();
    window.AnnigemsButton?.bindLoadingButtons();
    window.AnnigemsForm?.bindFormValidation();
    bindSelectSearch();
    bindPermissionGrids();
    bindBarcodeScanners();
    bindStoneForms();
    bindStoneImport();
    bindStoneFolders();
    bindSelectAll();
    bindPerPage();
    bindStoneLabels();
    bindStoneIntake();
    bindStoneImages();
    bindCategories();
    bindMetalPurities();
    bindJewelleryItemForm();
    bindQuotationForm();
    bindPos();
    bindPerformaForm();
    bindPerformaSettle();
    bindPerformaReturn();
    bindModals();
    bindSettingsForms();

    // Flash success / error from session (set via Blade data attributes on body).
    const flashSuccess = document.body.dataset.flashSuccess;
    const flashError = document.body.dataset.flashError;
    if (flashSuccess) {
        window.AnnigemsAlert?.notifySuccess(flashSuccess);
    }
    if (flashError) {
        window.AnnigemsAlert?.notifyError(flashError);
    }

    // Credentials payload after Branch Admin creation (one-time display).
    const credEmail = document.body.dataset.credEmail;
    const credPassword = document.body.dataset.credPassword;
    const credName = document.body.dataset.credName;
    if (credEmail && credPassword) {
        window.AnnigemsAlert?.showCredentialsModal({
            email: credEmail,
            password: credPassword,
            name: credName || '',
        });
    }
}

window.AnnigemsBoot = bootPage;

document.addEventListener('DOMContentLoaded', () => {
    bootPage();
});
