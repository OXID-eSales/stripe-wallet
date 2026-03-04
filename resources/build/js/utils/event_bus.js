/**
 * EventBus - Centralna szyna eventowa dla aplikacji One-Page Checkout
 *
 * Problem:
 * Kontrolery dispatchują eventy na różnych targetach (document, this.element, window),
 * co powoduje problemy z timing'iem i trudności w testowaniu.
 *
 * Rozwiązanie:
 * Singleton EventBus zapewnia jeden centralny punkt komunikacji.
 * Wszystkie eventy przechodzą przez tę szynę, co ułatwia:
 * - Debugowanie (wszystkie eventy w jednym miejscu)
 * - Testowanie (można mockować EventBus)
 * - Kontrolę (można logować, filtrować, transformować eventy)
 *
 * Użycie w kontrolerach:
 *
 * import { eventBus } from '../utils/event_bus.js'
 *
 * // Nasłuchiwanie
 * eventBus.on('oe:basket:updated', (data) => {
 *   console.log('Basket updated:', data)
 * })
 *
 * // Emisja
 * eventBus.emit('oe:basket:updated', { items: [...], total: 100 })
 *
 * // Jednorazowe nasłuchiwanie
 * eventBus.once('oe:checkout:complete', (data) => {
 *   console.log('Checkout complete:', data)
 * })
 *
 * // Usunięcie listenera (ważne dla cleanup!)
 * const handler = (data) => console.log(data)
 * eventBus.on('event', handler)
 * eventBus.off('event', handler)
 */

class EventBus {
  constructor() {
    // Singleton pattern
    if (EventBus.instance) {
      return EventBus.instance
    }

    this.listeners = new Map() // eventName -> Set of handlers
    this.debug = false
    this.eventHistory = [] // For debugging
    this.maxHistorySize = 100

    EventBus.instance = this
  }

  /**
   * Włącz/wyłącz tryb debug
   */
  setDebug(enabled) {
    this.debug = enabled
  }

  /**
   * Zarejestruj listener dla eventu
   * @param {string} eventName - Nazwa eventu (np. 'oe:basket:updated')
   * @param {function} handler - Funkcja handlera (data) => void
   * @returns {function} Funkcja do usunięcia listenera
   */
  on(eventName, handler) {
    if (!this.listeners.has(eventName)) {
      this.listeners.set(eventName, new Set())
    }

    this.listeners.get(eventName).add(handler)

    if (this.debug) {
      console.log(`[EventBus] Registered listener for "${eventName}"`, {
        listenersCount: this.listeners.get(eventName).size
      })
    }

    // Zwróć funkcję do usunięcia listenera
    return () => this.off(eventName, handler)
  }

  /**
   * Zarejestruj listener, który wykona się tylko raz
   * @param {string} eventName - Nazwa eventu
   * @param {function} handler - Funkcja handlera
   * @returns {function} Funkcja do usunięcia listenera
   */
  once(eventName, handler) {
    const onceHandler = (data) => {
      handler(data)
      this.off(eventName, onceHandler)
    }

    return this.on(eventName, onceHandler)
  }

  /**
   * Usuń listener
   * @param {string} eventName - Nazwa eventu
   * @param {function} handler - Funkcja handlera do usunięcia
   */
  off(eventName, handler) {
    if (!this.listeners.has(eventName)) {
      return
    }

    const handlers = this.listeners.get(eventName)
    handlers.delete(handler)

    // Usuń event z mapy jeśli nie ma już listenerów
    if (handlers.size === 0) {
      this.listeners.delete(eventName)
    }

    if (this.debug) {
      console.log(`[EventBus] Removed listener for "${eventName}"`, {
        listenersCount: handlers.size
      })
    }
  }

  /**
   * Usuń wszystkie listenery dla danego eventu
   * @param {string} eventName - Nazwa eventu
   */
  offAll(eventName) {
    if (this.listeners.has(eventName)) {
      this.listeners.delete(eventName)

      if (this.debug) {
        console.log(`[EventBus] Removed all listeners for "${eventName}"`)
      }
    }
  }

  /**
   * Wyemituj event
   * @param {string} eventName - Nazwa eventu
   * @param {*} data - Dane do przekazania
   */
  emit(eventName, data = {}) {
    const timestamp = Date.now()

    // Zapisz do historii
    this.eventHistory.push({
      eventName,
      data,
      timestamp,
      listenersCount: this.listeners.has(eventName) ? this.listeners.get(eventName).size : 0
    })

    // Ogranicz rozmiar historii
    if (this.eventHistory.length > this.maxHistorySize) {
      this.eventHistory.shift()
    }

    if (this.debug) {
      console.log(`[EventBus] Event emitted: "${eventName}"`, {
        data,
        listenersCount: this.listeners.has(eventName) ? this.listeners.get(eventName).size : 0,
        timestamp: new Date(timestamp).toISOString()
      })
    }

    // Wywołaj wszystkie listenery
    if (this.listeners.has(eventName)) {
      const handlers = Array.from(this.listeners.get(eventName))

      handlers.forEach(handler => {
        try {
          handler(data)
        } catch (error) {
          console.error(`[EventBus] Error in handler for "${eventName}":`, error)
          // Nie przerywaj wykonywania innych handlerów
        }
      })
    } else if (this.debug) {
      console.warn(`[EventBus] No listeners for "${eventName}"`)
    }
  }

  /**
   * Wyemituj event asynchronicznie (następny tick)
   * Przydatne gdy chcemy pozwolić UI się wyrenderować przed handlerem
   * @param {string} eventName - Nazwa eventu
   * @param {*} data - Dane do przekazania
   * @returns {Promise} Promise który resolve'uje się po emisji
   */
  async emitAsync(eventName, data = {}) {
    return new Promise((resolve) => {
      setTimeout(() => {
        this.emit(eventName, data)
        resolve()
      }, 0)
    })
  }

  /**
   * Wyemituj event z opóźnieniem
   * @param {string} eventName - Nazwa eventu
   * @param {*} data - Dane do przekazania
   * @param {number} delay - Opóźnienie w ms
   * @returns {number} Timer ID (do clearTimeout)
   */
  emitDelayed(eventName, data = {}, delay = 0) {
    return setTimeout(() => {
      this.emit(eventName, data)
    }, delay)
  }

  /**
   * Poczekaj na event (zwraca Promise)
   * Przydatne w testach i async flows
   * @param {string} eventName - Nazwa eventu
   * @param {number} timeout - Timeout w ms (opcjonalny)
   * @returns {Promise} Promise który resolve'uje się z danymi eventu
   */
  waitFor(eventName, timeout = 5000) {
    return new Promise((resolve, reject) => {
      const timer = timeout > 0 ? setTimeout(() => {
        this.off(eventName, handler)
        reject(new Error(`[EventBus] Timeout waiting for event "${eventName}"`))
      }, timeout) : null

      const handler = (data) => {
        if (timer) clearTimeout(timer)
        resolve(data)
      }

      this.once(eventName, handler)
    })
  }

  /**
   * Sprawdź czy są listenery dla danego eventu
   * @param {string} eventName - Nazwa eventu
   * @returns {boolean}
   */
  hasListeners(eventName) {
    return this.listeners.has(eventName) && this.listeners.get(eventName).size > 0
  }

  /**
   * Pobierz liczbę listenerów dla eventu
   * @param {string} eventName - Nazwa eventu
   * @returns {number}
   */
  getListenersCount(eventName) {
    return this.listeners.has(eventName) ? this.listeners.get(eventName).size : 0
  }

  /**
   * Pobierz wszystkie zarejestrowane eventy
   * @returns {string[]}
   */
  getRegisteredEvents() {
    return Array.from(this.listeners.keys())
  }

  /**
   * Pobierz historię eventów
   * @param {number} limit - Limit eventów do zwrócenia (opcjonalny)
   * @returns {Array}
   */
  getEventHistory(limit = 50) {
    return this.eventHistory.slice(-limit)
  }

  /**
   * Wyczyść historię eventów
   */
  clearHistory() {
    this.eventHistory = []
  }

  /**
   * Wyczyść wszystkie listenery (użyj ostrożnie!)
   */
  clearAll() {
    this.listeners.clear()

    if (this.debug) {
      console.log('[EventBus] All listeners cleared')
    }
  }

  /**
   * Wypisz statystyki EventBus
   */
  printStats() {
    console.group('[EventBus] Statistics')
    console.log('Registered events:', this.getRegisteredEvents())
    console.log('Total listeners:', Array.from(this.listeners.values()).reduce((sum, set) => sum + set.size, 0))
    console.log('Event history size:', this.eventHistory.length)

    console.group('Listeners per event:')
    this.listeners.forEach((handlers, eventName) => {
      console.log(`  ${eventName}: ${handlers.size}`)
    })
    console.groupEnd()

    console.group('Recent events:')
    this.getEventHistory(10).forEach(event => {
      console.log(`  ${event.eventName} (${event.listenersCount} listeners) - ${new Date(event.timestamp).toLocaleTimeString()}`)
    })
    console.groupEnd()

    console.groupEnd()
  }
}

// Eksportuj singleton instance - używaj globalnego jeśli istnieje!
// WAŻNE: Moduł Stripe ładuje się po onepage-checkout, więc musimy użyć
// istniejącej instancji EventBus z window.eventBus zamiast tworzyć nową.
let eventBus

if (typeof window !== 'undefined' && window.eventBus) {
  // Użyj istniejącej globalnej instancji
  console.log('[Stripe EventBus] Using existing global EventBus from window.eventBus')
  eventBus = window.eventBus
} else {
  // Utwórz nową instancję (fallback)
  console.log('[Stripe EventBus] Creating new EventBus instance')
  eventBus = new EventBus()

  // Opcjonalnie: włącz debug w dev mode
  if (typeof window !== 'undefined' && window.location?.hostname === 'localhost') {
    eventBus.setDebug(true)
  }

  // Udostępnij globalnie dla łatwego debugowania w konsoli
  if (typeof window !== 'undefined') {
    window.eventBus = eventBus
  }
}

export { eventBus }
export default eventBus
