/**
 * EventBus Mixin dla Stimulus Controllers
 *
 * Dodaje metody do łatwego korzystania z EventBus w kontrolerach Stimulus.
 *
 * Użycie:
 *
 * import { Controller } from "@hotwired/stimulus"
 * import { withEventBus } from "../mixins/event_bus_mixin.js"
 *
 * export default class extends withEventBus(Controller) {
 *   connect() {
 *     // Nasłuchuj eventu
 *     this.listen('oe:basket:updated', this.handleBasketUpdate)
 *
 *     // lub z auto-cleanup:
 *     this.listen('oe:basket:updated', (data) => {
 *       console.log('Basket updated:', data)
 *     })
 *   }
 *
 *   handleBasketUpdate(data) {
 *     console.log('Basket updated:', data)
 *   }
 *
 *   someAction() {
 *     // Wyemituj event
 *     this.broadcast('oe:basket:item-added', { itemId: 123 })
 *   }
 *
 *   // disconnect() automatycznie czyści wszystkie listenery!
 * }
 *
 * Korzyści:
 * - Automatyczne czyszczenie listenerów w disconnect()
 * - Krótsze API: listen(), broadcast()
 * - Zachowanie kontekstu (this) w handlerach
 * - Debug info z nazwą kontrolera
 */

import { eventBus } from '../utils/event_bus.js'

export function withEventBus(BaseController) {
  return class extends BaseController {
    constructor(...args) {
      super(...args)

      // Przechowuj referencje do listenerów dla cleanup
      this._eventBusListeners = []
    }

    /**
     * Nasłuchuj eventu przez EventBus
     * Automatycznie usuwa listenera w disconnect()
     *
     * @param {string} eventName - Nazwa eventu
     * @param {function} handler - Handler function
     * @param {object} options - Opcje
     * @param {boolean} options.once - Czy wykonać tylko raz (default: false)
     * @returns {function} Funkcja do manualnego usunięcia listenera
     */
    listen(eventName, handler, options = {}) {
      const { once = false } = options

      // Bind handler do this kontrolera
      const boundHandler = handler.bind(this)

      // Dodaj prefix z nazwą kontrolera dla debugowania
      const controllerName = this.identifier || this.constructor.name
      const debugHandler = (data) => {
        if (eventBus.debug) {
          console.log(`[${controllerName}] Received event "${eventName}"`, data)
        }
        boundHandler(data)
      }

      // Zarejestruj listener
      const removeListener = once
        ? eventBus.once(eventName, debugHandler)
        : eventBus.on(eventName, debugHandler)

      // Zachowaj referencję do cleanup
      this._eventBusListeners.push({ eventName, handler: debugHandler, removeListener })

      // Zwróć funkcję do manualnego usunięcia
      return removeListener
    }

    /**
     * Nasłuchuj eventu tylko raz
     * Shorthand dla listen(eventName, handler, { once: true })
     *
     * @param {string} eventName - Nazwa eventu
     * @param {function} handler - Handler function
     * @returns {function} Funkcja do manualnego usunięcia listenera
     */
    listenOnce(eventName, handler) {
      return this.listen(eventName, handler, { once: true })
    }

    /**
     * Wyemituj event przez EventBus
     *
     * @param {string} eventName - Nazwa eventu
     * @param {*} data - Dane do przekazania
     */
    broadcast(eventName, data = {}) {
      const controllerName = this.identifier || this.constructor.name

      if (eventBus.debug) {
        console.log(`[${controllerName}] Broadcasting event "${eventName}"`, data)
      }

      eventBus.emit(eventName, data)
    }

    /**
     * Wyemituj event asynchronicznie
     *
     * @param {string} eventName - Nazwa eventu
     * @param {*} data - Dane do przekazania
     * @returns {Promise}
     */
    async broadcastAsync(eventName, data = {}) {
      return eventBus.emitAsync(eventName, data)
    }

    /**
     * Poczekaj na event
     * Przydatne w async flows
     *
     * @param {string} eventName - Nazwa eventu
     * @param {number} timeout - Timeout w ms
     * @returns {Promise}
     */
    async waitForEvent(eventName, timeout = 5000) {
      return eventBus.waitFor(eventName, timeout)
    }

    /**
     * Usuń konkretny listener
     *
     * @param {string} eventName - Nazwa eventu
     * @param {function} handler - Handler do usunięcia
     */
    stopListening(eventName, handler) {
      eventBus.off(eventName, handler)

      // Usuń z naszej listy
      this._eventBusListeners = this._eventBusListeners.filter(
        listener => !(listener.eventName === eventName && listener.handler === handler)
      )
    }

    /**
     * Usuń wszystkie listenery tego kontrolera
     * Automatycznie wywoływane w disconnect()
     */
    stopListeningAll() {
      this._eventBusListeners.forEach(({ removeListener }) => {
        removeListener()
      })

      this._eventBusListeners = []

      if (eventBus.debug) {
        const controllerName = this.identifier || this.constructor.name
        console.log(`[${controllerName}] All EventBus listeners removed`)
      }
    }

    /**
     * Override disconnect() żeby automatycznie czyścić listenery
     */
    disconnect() {
      this.stopListeningAll()

      // Wywołaj oryginalny disconnect jeśli istnieje
      if (super.disconnect) {
        super.disconnect()
      }
    }
  }
}

export default withEventBus
