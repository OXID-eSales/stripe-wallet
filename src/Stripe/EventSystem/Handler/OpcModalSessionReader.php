<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use OxidEsales\Eshop\Core\Registry;

/**
 * Centralises the `oe_opc_modal_session` session-key and the modal-ID resolution
 * logic shared by OpcModalSuccessUrlHandler and OpcModalCancelUrlHandler (D8).
 *
 * Resolution order for getModalId():
 * 1. Request parameter `opcModalId` (set by the footer widget JS with the request).
 * 2. PHP session variable `oe_opc_modal_session.modalId` (set by registerModalOpen).
 *
 * Registry access is isolated in the two protected seams (getRequestParam /
 * readSessionVariable) so unit tests can inject fixtures without OXID bootstrap.
 */
class OpcModalSessionReader
{
    public const SESSION_KEY = 'oe_opc_modal_session';

    /**
     * Resolve the OPC modal ID.
     *
     * Returns null when neither the request param nor the session contains a
     * valid modal ID (or when this is not an OPC context at all).
     */
    public function getModalId(): ?string
    {
        $fromRequest = $this->getRequestParam();
        if (is_string($fromRequest) && $fromRequest !== '') {
            return $fromRequest;
        }

        $modalSession = $this->loadModalSession();
        if ($modalSession === null) {
            return null;
        }

        $modalId = $modalSession['modalId'] ?? null;
        return is_string($modalId) && $modalId !== '' ? $modalId : null;
    }

    /**
     * Resolve the OPC origin URL (page where the Buy Now modal was opened).
     *
     * Returns null when the session does not contain an originUrl.
     */
    public function getOriginUrl(): ?string
    {
        $modalSession = $this->loadModalSession();
        if ($modalSession === null) {
            return null;
        }

        $url = $modalSession['originUrl'] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadModalSession(): ?array
    {
        try {
            $data = $this->readSessionVariable();
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($data) || empty($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Testability seam: read the `opcModalId` request parameter.
     */
    protected function getRequestParam(): ?string
    {
        $value = Registry::getRequest()->getRequestParameter('opcModalId');
        return is_string($value) ? $value : null;
    }

    /**
     * Testability seam: read the OPC modal session variable.
     */
    protected function readSessionVariable(): mixed
    {
        return Registry::getSession()->getVariable(self::SESSION_KEY);
    }
}
