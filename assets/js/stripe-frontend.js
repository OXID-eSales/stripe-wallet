(() => {
  var __defProp = Object.defineProperty;
  var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
  var __publicField = (obj, key, value) => {
    __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value);
    return value;
  };

  // node_modules/@hotwired/stimulus/dist/stimulus.js
  var EventListener = class {
    constructor(eventTarget, eventName, eventOptions) {
      this.eventTarget = eventTarget;
      this.eventName = eventName;
      this.eventOptions = eventOptions;
      this.unorderedBindings = /* @__PURE__ */ new Set();
    }
    connect() {
      this.eventTarget.addEventListener(this.eventName, this, this.eventOptions);
    }
    disconnect() {
      this.eventTarget.removeEventListener(this.eventName, this, this.eventOptions);
    }
    bindingConnected(binding) {
      this.unorderedBindings.add(binding);
    }
    bindingDisconnected(binding) {
      this.unorderedBindings.delete(binding);
    }
    handleEvent(event) {
      const extendedEvent = extendEvent(event);
      for (const binding of this.bindings) {
        if (extendedEvent.immediatePropagationStopped) {
          break;
        } else {
          binding.handleEvent(extendedEvent);
        }
      }
    }
    hasBindings() {
      return this.unorderedBindings.size > 0;
    }
    get bindings() {
      return Array.from(this.unorderedBindings).sort((left, right) => {
        const leftIndex = left.index, rightIndex = right.index;
        return leftIndex < rightIndex ? -1 : leftIndex > rightIndex ? 1 : 0;
      });
    }
  };
  function extendEvent(event) {
    if ("immediatePropagationStopped" in event) {
      return event;
    } else {
      const { stopImmediatePropagation } = event;
      return Object.assign(event, {
        immediatePropagationStopped: false,
        stopImmediatePropagation() {
          this.immediatePropagationStopped = true;
          stopImmediatePropagation.call(this);
        }
      });
    }
  }
  var Dispatcher = class {
    constructor(application) {
      this.application = application;
      this.eventListenerMaps = /* @__PURE__ */ new Map();
      this.started = false;
    }
    start() {
      if (!this.started) {
        this.started = true;
        this.eventListeners.forEach((eventListener) => eventListener.connect());
      }
    }
    stop() {
      if (this.started) {
        this.started = false;
        this.eventListeners.forEach((eventListener) => eventListener.disconnect());
      }
    }
    get eventListeners() {
      return Array.from(this.eventListenerMaps.values()).reduce((listeners, map) => listeners.concat(Array.from(map.values())), []);
    }
    bindingConnected(binding) {
      this.fetchEventListenerForBinding(binding).bindingConnected(binding);
    }
    bindingDisconnected(binding, clearEventListeners = false) {
      this.fetchEventListenerForBinding(binding).bindingDisconnected(binding);
      if (clearEventListeners)
        this.clearEventListenersForBinding(binding);
    }
    handleError(error2, message, detail = {}) {
      this.application.handleError(error2, `Error ${message}`, detail);
    }
    clearEventListenersForBinding(binding) {
      const eventListener = this.fetchEventListenerForBinding(binding);
      if (!eventListener.hasBindings()) {
        eventListener.disconnect();
        this.removeMappedEventListenerFor(binding);
      }
    }
    removeMappedEventListenerFor(binding) {
      const { eventTarget, eventName, eventOptions } = binding;
      const eventListenerMap = this.fetchEventListenerMapForEventTarget(eventTarget);
      const cacheKey = this.cacheKey(eventName, eventOptions);
      eventListenerMap.delete(cacheKey);
      if (eventListenerMap.size == 0)
        this.eventListenerMaps.delete(eventTarget);
    }
    fetchEventListenerForBinding(binding) {
      const { eventTarget, eventName, eventOptions } = binding;
      return this.fetchEventListener(eventTarget, eventName, eventOptions);
    }
    fetchEventListener(eventTarget, eventName, eventOptions) {
      const eventListenerMap = this.fetchEventListenerMapForEventTarget(eventTarget);
      const cacheKey = this.cacheKey(eventName, eventOptions);
      let eventListener = eventListenerMap.get(cacheKey);
      if (!eventListener) {
        eventListener = this.createEventListener(eventTarget, eventName, eventOptions);
        eventListenerMap.set(cacheKey, eventListener);
      }
      return eventListener;
    }
    createEventListener(eventTarget, eventName, eventOptions) {
      const eventListener = new EventListener(eventTarget, eventName, eventOptions);
      if (this.started) {
        eventListener.connect();
      }
      return eventListener;
    }
    fetchEventListenerMapForEventTarget(eventTarget) {
      let eventListenerMap = this.eventListenerMaps.get(eventTarget);
      if (!eventListenerMap) {
        eventListenerMap = /* @__PURE__ */ new Map();
        this.eventListenerMaps.set(eventTarget, eventListenerMap);
      }
      return eventListenerMap;
    }
    cacheKey(eventName, eventOptions) {
      const parts = [eventName];
      Object.keys(eventOptions).sort().forEach((key) => {
        parts.push(`${eventOptions[key] ? "" : "!"}${key}`);
      });
      return parts.join(":");
    }
  };
  var defaultActionDescriptorFilters = {
    stop({ event, value }) {
      if (value)
        event.stopPropagation();
      return true;
    },
    prevent({ event, value }) {
      if (value)
        event.preventDefault();
      return true;
    },
    self({ event, value, element }) {
      if (value) {
        return element === event.target;
      } else {
        return true;
      }
    }
  };
  var descriptorPattern = /^(?:(?:([^.]+?)\+)?(.+?)(?:\.(.+?))?(?:@(window|document))?->)?(.+?)(?:#([^:]+?))(?::(.+))?$/;
  function parseActionDescriptorString(descriptorString) {
    const source = descriptorString.trim();
    const matches = source.match(descriptorPattern) || [];
    let eventName = matches[2];
    let keyFilter = matches[3];
    if (keyFilter && !["keydown", "keyup", "keypress"].includes(eventName)) {
      eventName += `.${keyFilter}`;
      keyFilter = "";
    }
    return {
      eventTarget: parseEventTarget(matches[4]),
      eventName,
      eventOptions: matches[7] ? parseEventOptions(matches[7]) : {},
      identifier: matches[5],
      methodName: matches[6],
      keyFilter: matches[1] || keyFilter
    };
  }
  function parseEventTarget(eventTargetName) {
    if (eventTargetName == "window") {
      return window;
    } else if (eventTargetName == "document") {
      return document;
    }
  }
  function parseEventOptions(eventOptions) {
    return eventOptions.split(":").reduce((options, token) => Object.assign(options, { [token.replace(/^!/, "")]: !/^!/.test(token) }), {});
  }
  function stringifyEventTarget(eventTarget) {
    if (eventTarget == window) {
      return "window";
    } else if (eventTarget == document) {
      return "document";
    }
  }
  function camelize(value) {
    return value.replace(/(?:[_-])([a-z0-9])/g, (_, char) => char.toUpperCase());
  }
  function namespaceCamelize(value) {
    return camelize(value.replace(/--/g, "-").replace(/__/g, "_"));
  }
  function capitalize(value) {
    return value.charAt(0).toUpperCase() + value.slice(1);
  }
  function dasherize(value) {
    return value.replace(/([A-Z])/g, (_, char) => `-${char.toLowerCase()}`);
  }
  function tokenize(value) {
    return value.match(/[^\s]+/g) || [];
  }
  function isSomething(object) {
    return object !== null && object !== void 0;
  }
  function hasProperty(object, property) {
    return Object.prototype.hasOwnProperty.call(object, property);
  }
  var allModifiers = ["meta", "ctrl", "alt", "shift"];
  var Action = class {
    constructor(element, index, descriptor, schema) {
      this.element = element;
      this.index = index;
      this.eventTarget = descriptor.eventTarget || element;
      this.eventName = descriptor.eventName || getDefaultEventNameForElement(element) || error("missing event name");
      this.eventOptions = descriptor.eventOptions || {};
      this.identifier = descriptor.identifier || error("missing identifier");
      this.methodName = descriptor.methodName || error("missing method name");
      this.keyFilter = descriptor.keyFilter || "";
      this.schema = schema;
    }
    static forToken(token, schema) {
      return new this(token.element, token.index, parseActionDescriptorString(token.content), schema);
    }
    toString() {
      const eventFilter = this.keyFilter ? `.${this.keyFilter}` : "";
      const eventTarget = this.eventTargetName ? `@${this.eventTargetName}` : "";
      return `${this.eventName}${eventFilter}${eventTarget}->${this.identifier}#${this.methodName}`;
    }
    shouldIgnoreKeyboardEvent(event) {
      if (!this.keyFilter) {
        return false;
      }
      const filters = this.keyFilter.split("+");
      if (this.keyFilterDissatisfied(event, filters)) {
        return true;
      }
      const standardFilter = filters.filter((key) => !allModifiers.includes(key))[0];
      if (!standardFilter) {
        return false;
      }
      if (!hasProperty(this.keyMappings, standardFilter)) {
        error(`contains unknown key filter: ${this.keyFilter}`);
      }
      return this.keyMappings[standardFilter].toLowerCase() !== event.key.toLowerCase();
    }
    shouldIgnoreMouseEvent(event) {
      if (!this.keyFilter) {
        return false;
      }
      const filters = [this.keyFilter];
      if (this.keyFilterDissatisfied(event, filters)) {
        return true;
      }
      return false;
    }
    get params() {
      const params = {};
      const pattern = new RegExp(`^data-${this.identifier}-(.+)-param$`, "i");
      for (const { name, value } of Array.from(this.element.attributes)) {
        const match = name.match(pattern);
        const key = match && match[1];
        if (key) {
          params[camelize(key)] = typecast(value);
        }
      }
      return params;
    }
    get eventTargetName() {
      return stringifyEventTarget(this.eventTarget);
    }
    get keyMappings() {
      return this.schema.keyMappings;
    }
    keyFilterDissatisfied(event, filters) {
      const [meta, ctrl, alt, shift] = allModifiers.map((modifier) => filters.includes(modifier));
      return event.metaKey !== meta || event.ctrlKey !== ctrl || event.altKey !== alt || event.shiftKey !== shift;
    }
  };
  var defaultEventNames = {
    a: () => "click",
    button: () => "click",
    form: () => "submit",
    details: () => "toggle",
    input: (e) => e.getAttribute("type") == "submit" ? "click" : "input",
    select: () => "change",
    textarea: () => "input"
  };
  function getDefaultEventNameForElement(element) {
    const tagName = element.tagName.toLowerCase();
    if (tagName in defaultEventNames) {
      return defaultEventNames[tagName](element);
    }
  }
  function error(message) {
    throw new Error(message);
  }
  function typecast(value) {
    try {
      return JSON.parse(value);
    } catch (o_O) {
      return value;
    }
  }
  var Binding = class {
    constructor(context, action) {
      this.context = context;
      this.action = action;
    }
    get index() {
      return this.action.index;
    }
    get eventTarget() {
      return this.action.eventTarget;
    }
    get eventOptions() {
      return this.action.eventOptions;
    }
    get identifier() {
      return this.context.identifier;
    }
    handleEvent(event) {
      const actionEvent = this.prepareActionEvent(event);
      if (this.willBeInvokedByEvent(event) && this.applyEventModifiers(actionEvent)) {
        this.invokeWithEvent(actionEvent);
      }
    }
    get eventName() {
      return this.action.eventName;
    }
    get method() {
      const method = this.controller[this.methodName];
      if (typeof method == "function") {
        return method;
      }
      throw new Error(`Action "${this.action}" references undefined method "${this.methodName}"`);
    }
    applyEventModifiers(event) {
      const { element } = this.action;
      const { actionDescriptorFilters } = this.context.application;
      const { controller } = this.context;
      let passes = true;
      for (const [name, value] of Object.entries(this.eventOptions)) {
        if (name in actionDescriptorFilters) {
          const filter = actionDescriptorFilters[name];
          passes = passes && filter({ name, value, event, element, controller });
        } else {
          continue;
        }
      }
      return passes;
    }
    prepareActionEvent(event) {
      return Object.assign(event, { params: this.action.params });
    }
    invokeWithEvent(event) {
      const { target, currentTarget } = event;
      try {
        this.method.call(this.controller, event);
        this.context.logDebugActivity(this.methodName, { event, target, currentTarget, action: this.methodName });
      } catch (error2) {
        const { identifier, controller, element, index } = this;
        const detail = { identifier, controller, element, index, event };
        this.context.handleError(error2, `invoking action "${this.action}"`, detail);
      }
    }
    willBeInvokedByEvent(event) {
      const eventTarget = event.target;
      if (event instanceof KeyboardEvent && this.action.shouldIgnoreKeyboardEvent(event)) {
        return false;
      }
      if (event instanceof MouseEvent && this.action.shouldIgnoreMouseEvent(event)) {
        return false;
      }
      if (this.element === eventTarget) {
        return true;
      } else if (eventTarget instanceof Element && this.element.contains(eventTarget)) {
        return this.scope.containsElement(eventTarget);
      } else {
        return this.scope.containsElement(this.action.element);
      }
    }
    get controller() {
      return this.context.controller;
    }
    get methodName() {
      return this.action.methodName;
    }
    get element() {
      return this.scope.element;
    }
    get scope() {
      return this.context.scope;
    }
  };
  var ElementObserver = class {
    constructor(element, delegate) {
      this.mutationObserverInit = { attributes: true, childList: true, subtree: true };
      this.element = element;
      this.started = false;
      this.delegate = delegate;
      this.elements = /* @__PURE__ */ new Set();
      this.mutationObserver = new MutationObserver((mutations) => this.processMutations(mutations));
    }
    start() {
      if (!this.started) {
        this.started = true;
        this.mutationObserver.observe(this.element, this.mutationObserverInit);
        this.refresh();
      }
    }
    pause(callback) {
      if (this.started) {
        this.mutationObserver.disconnect();
        this.started = false;
      }
      callback();
      if (!this.started) {
        this.mutationObserver.observe(this.element, this.mutationObserverInit);
        this.started = true;
      }
    }
    stop() {
      if (this.started) {
        this.mutationObserver.takeRecords();
        this.mutationObserver.disconnect();
        this.started = false;
      }
    }
    refresh() {
      if (this.started) {
        const matches = new Set(this.matchElementsInTree());
        for (const element of Array.from(this.elements)) {
          if (!matches.has(element)) {
            this.removeElement(element);
          }
        }
        for (const element of Array.from(matches)) {
          this.addElement(element);
        }
      }
    }
    processMutations(mutations) {
      if (this.started) {
        for (const mutation of mutations) {
          this.processMutation(mutation);
        }
      }
    }
    processMutation(mutation) {
      if (mutation.type == "attributes") {
        this.processAttributeChange(mutation.target, mutation.attributeName);
      } else if (mutation.type == "childList") {
        this.processRemovedNodes(mutation.removedNodes);
        this.processAddedNodes(mutation.addedNodes);
      }
    }
    processAttributeChange(element, attributeName) {
      if (this.elements.has(element)) {
        if (this.delegate.elementAttributeChanged && this.matchElement(element)) {
          this.delegate.elementAttributeChanged(element, attributeName);
        } else {
          this.removeElement(element);
        }
      } else if (this.matchElement(element)) {
        this.addElement(element);
      }
    }
    processRemovedNodes(nodes) {
      for (const node of Array.from(nodes)) {
        const element = this.elementFromNode(node);
        if (element) {
          this.processTree(element, this.removeElement);
        }
      }
    }
    processAddedNodes(nodes) {
      for (const node of Array.from(nodes)) {
        const element = this.elementFromNode(node);
        if (element && this.elementIsActive(element)) {
          this.processTree(element, this.addElement);
        }
      }
    }
    matchElement(element) {
      return this.delegate.matchElement(element);
    }
    matchElementsInTree(tree = this.element) {
      return this.delegate.matchElementsInTree(tree);
    }
    processTree(tree, processor) {
      for (const element of this.matchElementsInTree(tree)) {
        processor.call(this, element);
      }
    }
    elementFromNode(node) {
      if (node.nodeType == Node.ELEMENT_NODE) {
        return node;
      }
    }
    elementIsActive(element) {
      if (element.isConnected != this.element.isConnected) {
        return false;
      } else {
        return this.element.contains(element);
      }
    }
    addElement(element) {
      if (!this.elements.has(element)) {
        if (this.elementIsActive(element)) {
          this.elements.add(element);
          if (this.delegate.elementMatched) {
            this.delegate.elementMatched(element);
          }
        }
      }
    }
    removeElement(element) {
      if (this.elements.has(element)) {
        this.elements.delete(element);
        if (this.delegate.elementUnmatched) {
          this.delegate.elementUnmatched(element);
        }
      }
    }
  };
  var AttributeObserver = class {
    constructor(element, attributeName, delegate) {
      this.attributeName = attributeName;
      this.delegate = delegate;
      this.elementObserver = new ElementObserver(element, this);
    }
    get element() {
      return this.elementObserver.element;
    }
    get selector() {
      return `[${this.attributeName}]`;
    }
    start() {
      this.elementObserver.start();
    }
    pause(callback) {
      this.elementObserver.pause(callback);
    }
    stop() {
      this.elementObserver.stop();
    }
    refresh() {
      this.elementObserver.refresh();
    }
    get started() {
      return this.elementObserver.started;
    }
    matchElement(element) {
      return element.hasAttribute(this.attributeName);
    }
    matchElementsInTree(tree) {
      const match = this.matchElement(tree) ? [tree] : [];
      const matches = Array.from(tree.querySelectorAll(this.selector));
      return match.concat(matches);
    }
    elementMatched(element) {
      if (this.delegate.elementMatchedAttribute) {
        this.delegate.elementMatchedAttribute(element, this.attributeName);
      }
    }
    elementUnmatched(element) {
      if (this.delegate.elementUnmatchedAttribute) {
        this.delegate.elementUnmatchedAttribute(element, this.attributeName);
      }
    }
    elementAttributeChanged(element, attributeName) {
      if (this.delegate.elementAttributeValueChanged && this.attributeName == attributeName) {
        this.delegate.elementAttributeValueChanged(element, attributeName);
      }
    }
  };
  function add(map, key, value) {
    fetch(map, key).add(value);
  }
  function del(map, key, value) {
    fetch(map, key).delete(value);
    prune(map, key);
  }
  function fetch(map, key) {
    let values = map.get(key);
    if (!values) {
      values = /* @__PURE__ */ new Set();
      map.set(key, values);
    }
    return values;
  }
  function prune(map, key) {
    const values = map.get(key);
    if (values != null && values.size == 0) {
      map.delete(key);
    }
  }
  var Multimap = class {
    constructor() {
      this.valuesByKey = /* @__PURE__ */ new Map();
    }
    get keys() {
      return Array.from(this.valuesByKey.keys());
    }
    get values() {
      const sets = Array.from(this.valuesByKey.values());
      return sets.reduce((values, set) => values.concat(Array.from(set)), []);
    }
    get size() {
      const sets = Array.from(this.valuesByKey.values());
      return sets.reduce((size, set) => size + set.size, 0);
    }
    add(key, value) {
      add(this.valuesByKey, key, value);
    }
    delete(key, value) {
      del(this.valuesByKey, key, value);
    }
    has(key, value) {
      const values = this.valuesByKey.get(key);
      return values != null && values.has(value);
    }
    hasKey(key) {
      return this.valuesByKey.has(key);
    }
    hasValue(value) {
      const sets = Array.from(this.valuesByKey.values());
      return sets.some((set) => set.has(value));
    }
    getValuesForKey(key) {
      const values = this.valuesByKey.get(key);
      return values ? Array.from(values) : [];
    }
    getKeysForValue(value) {
      return Array.from(this.valuesByKey).filter(([_key, values]) => values.has(value)).map(([key, _values]) => key);
    }
  };
  var SelectorObserver = class {
    constructor(element, selector, delegate, details) {
      this._selector = selector;
      this.details = details;
      this.elementObserver = new ElementObserver(element, this);
      this.delegate = delegate;
      this.matchesByElement = new Multimap();
    }
    get started() {
      return this.elementObserver.started;
    }
    get selector() {
      return this._selector;
    }
    set selector(selector) {
      this._selector = selector;
      this.refresh();
    }
    start() {
      this.elementObserver.start();
    }
    pause(callback) {
      this.elementObserver.pause(callback);
    }
    stop() {
      this.elementObserver.stop();
    }
    refresh() {
      this.elementObserver.refresh();
    }
    get element() {
      return this.elementObserver.element;
    }
    matchElement(element) {
      const { selector } = this;
      if (selector) {
        const matches = element.matches(selector);
        if (this.delegate.selectorMatchElement) {
          return matches && this.delegate.selectorMatchElement(element, this.details);
        }
        return matches;
      } else {
        return false;
      }
    }
    matchElementsInTree(tree) {
      const { selector } = this;
      if (selector) {
        const match = this.matchElement(tree) ? [tree] : [];
        const matches = Array.from(tree.querySelectorAll(selector)).filter((match2) => this.matchElement(match2));
        return match.concat(matches);
      } else {
        return [];
      }
    }
    elementMatched(element) {
      const { selector } = this;
      if (selector) {
        this.selectorMatched(element, selector);
      }
    }
    elementUnmatched(element) {
      const selectors = this.matchesByElement.getKeysForValue(element);
      for (const selector of selectors) {
        this.selectorUnmatched(element, selector);
      }
    }
    elementAttributeChanged(element, _attributeName) {
      const { selector } = this;
      if (selector) {
        const matches = this.matchElement(element);
        const matchedBefore = this.matchesByElement.has(selector, element);
        if (matches && !matchedBefore) {
          this.selectorMatched(element, selector);
        } else if (!matches && matchedBefore) {
          this.selectorUnmatched(element, selector);
        }
      }
    }
    selectorMatched(element, selector) {
      this.delegate.selectorMatched(element, selector, this.details);
      this.matchesByElement.add(selector, element);
    }
    selectorUnmatched(element, selector) {
      this.delegate.selectorUnmatched(element, selector, this.details);
      this.matchesByElement.delete(selector, element);
    }
  };
  var StringMapObserver = class {
    constructor(element, delegate) {
      this.element = element;
      this.delegate = delegate;
      this.started = false;
      this.stringMap = /* @__PURE__ */ new Map();
      this.mutationObserver = new MutationObserver((mutations) => this.processMutations(mutations));
    }
    start() {
      if (!this.started) {
        this.started = true;
        this.mutationObserver.observe(this.element, { attributes: true, attributeOldValue: true });
        this.refresh();
      }
    }
    stop() {
      if (this.started) {
        this.mutationObserver.takeRecords();
        this.mutationObserver.disconnect();
        this.started = false;
      }
    }
    refresh() {
      if (this.started) {
        for (const attributeName of this.knownAttributeNames) {
          this.refreshAttribute(attributeName, null);
        }
      }
    }
    processMutations(mutations) {
      if (this.started) {
        for (const mutation of mutations) {
          this.processMutation(mutation);
        }
      }
    }
    processMutation(mutation) {
      const attributeName = mutation.attributeName;
      if (attributeName) {
        this.refreshAttribute(attributeName, mutation.oldValue);
      }
    }
    refreshAttribute(attributeName, oldValue) {
      const key = this.delegate.getStringMapKeyForAttribute(attributeName);
      if (key != null) {
        if (!this.stringMap.has(attributeName)) {
          this.stringMapKeyAdded(key, attributeName);
        }
        const value = this.element.getAttribute(attributeName);
        if (this.stringMap.get(attributeName) != value) {
          this.stringMapValueChanged(value, key, oldValue);
        }
        if (value == null) {
          const oldValue2 = this.stringMap.get(attributeName);
          this.stringMap.delete(attributeName);
          if (oldValue2)
            this.stringMapKeyRemoved(key, attributeName, oldValue2);
        } else {
          this.stringMap.set(attributeName, value);
        }
      }
    }
    stringMapKeyAdded(key, attributeName) {
      if (this.delegate.stringMapKeyAdded) {
        this.delegate.stringMapKeyAdded(key, attributeName);
      }
    }
    stringMapValueChanged(value, key, oldValue) {
      if (this.delegate.stringMapValueChanged) {
        this.delegate.stringMapValueChanged(value, key, oldValue);
      }
    }
    stringMapKeyRemoved(key, attributeName, oldValue) {
      if (this.delegate.stringMapKeyRemoved) {
        this.delegate.stringMapKeyRemoved(key, attributeName, oldValue);
      }
    }
    get knownAttributeNames() {
      return Array.from(new Set(this.currentAttributeNames.concat(this.recordedAttributeNames)));
    }
    get currentAttributeNames() {
      return Array.from(this.element.attributes).map((attribute) => attribute.name);
    }
    get recordedAttributeNames() {
      return Array.from(this.stringMap.keys());
    }
  };
  var TokenListObserver = class {
    constructor(element, attributeName, delegate) {
      this.attributeObserver = new AttributeObserver(element, attributeName, this);
      this.delegate = delegate;
      this.tokensByElement = new Multimap();
    }
    get started() {
      return this.attributeObserver.started;
    }
    start() {
      this.attributeObserver.start();
    }
    pause(callback) {
      this.attributeObserver.pause(callback);
    }
    stop() {
      this.attributeObserver.stop();
    }
    refresh() {
      this.attributeObserver.refresh();
    }
    get element() {
      return this.attributeObserver.element;
    }
    get attributeName() {
      return this.attributeObserver.attributeName;
    }
    elementMatchedAttribute(element) {
      this.tokensMatched(this.readTokensForElement(element));
    }
    elementAttributeValueChanged(element) {
      const [unmatchedTokens, matchedTokens] = this.refreshTokensForElement(element);
      this.tokensUnmatched(unmatchedTokens);
      this.tokensMatched(matchedTokens);
    }
    elementUnmatchedAttribute(element) {
      this.tokensUnmatched(this.tokensByElement.getValuesForKey(element));
    }
    tokensMatched(tokens) {
      tokens.forEach((token) => this.tokenMatched(token));
    }
    tokensUnmatched(tokens) {
      tokens.forEach((token) => this.tokenUnmatched(token));
    }
    tokenMatched(token) {
      this.delegate.tokenMatched(token);
      this.tokensByElement.add(token.element, token);
    }
    tokenUnmatched(token) {
      this.delegate.tokenUnmatched(token);
      this.tokensByElement.delete(token.element, token);
    }
    refreshTokensForElement(element) {
      const previousTokens = this.tokensByElement.getValuesForKey(element);
      const currentTokens = this.readTokensForElement(element);
      const firstDifferingIndex = zip(previousTokens, currentTokens).findIndex(([previousToken, currentToken]) => !tokensAreEqual(previousToken, currentToken));
      if (firstDifferingIndex == -1) {
        return [[], []];
      } else {
        return [previousTokens.slice(firstDifferingIndex), currentTokens.slice(firstDifferingIndex)];
      }
    }
    readTokensForElement(element) {
      const attributeName = this.attributeName;
      const tokenString = element.getAttribute(attributeName) || "";
      return parseTokenString(tokenString, element, attributeName);
    }
  };
  function parseTokenString(tokenString, element, attributeName) {
    return tokenString.trim().split(/\s+/).filter((content) => content.length).map((content, index) => ({ element, attributeName, content, index }));
  }
  function zip(left, right) {
    const length = Math.max(left.length, right.length);
    return Array.from({ length }, (_, index) => [left[index], right[index]]);
  }
  function tokensAreEqual(left, right) {
    return left && right && left.index == right.index && left.content == right.content;
  }
  var ValueListObserver = class {
    constructor(element, attributeName, delegate) {
      this.tokenListObserver = new TokenListObserver(element, attributeName, this);
      this.delegate = delegate;
      this.parseResultsByToken = /* @__PURE__ */ new WeakMap();
      this.valuesByTokenByElement = /* @__PURE__ */ new WeakMap();
    }
    get started() {
      return this.tokenListObserver.started;
    }
    start() {
      this.tokenListObserver.start();
    }
    stop() {
      this.tokenListObserver.stop();
    }
    refresh() {
      this.tokenListObserver.refresh();
    }
    get element() {
      return this.tokenListObserver.element;
    }
    get attributeName() {
      return this.tokenListObserver.attributeName;
    }
    tokenMatched(token) {
      const { element } = token;
      const { value } = this.fetchParseResultForToken(token);
      if (value) {
        this.fetchValuesByTokenForElement(element).set(token, value);
        this.delegate.elementMatchedValue(element, value);
      }
    }
    tokenUnmatched(token) {
      const { element } = token;
      const { value } = this.fetchParseResultForToken(token);
      if (value) {
        this.fetchValuesByTokenForElement(element).delete(token);
        this.delegate.elementUnmatchedValue(element, value);
      }
    }
    fetchParseResultForToken(token) {
      let parseResult = this.parseResultsByToken.get(token);
      if (!parseResult) {
        parseResult = this.parseToken(token);
        this.parseResultsByToken.set(token, parseResult);
      }
      return parseResult;
    }
    fetchValuesByTokenForElement(element) {
      let valuesByToken = this.valuesByTokenByElement.get(element);
      if (!valuesByToken) {
        valuesByToken = /* @__PURE__ */ new Map();
        this.valuesByTokenByElement.set(element, valuesByToken);
      }
      return valuesByToken;
    }
    parseToken(token) {
      try {
        const value = this.delegate.parseValueForToken(token);
        return { value };
      } catch (error2) {
        return { error: error2 };
      }
    }
  };
  var BindingObserver = class {
    constructor(context, delegate) {
      this.context = context;
      this.delegate = delegate;
      this.bindingsByAction = /* @__PURE__ */ new Map();
    }
    start() {
      if (!this.valueListObserver) {
        this.valueListObserver = new ValueListObserver(this.element, this.actionAttribute, this);
        this.valueListObserver.start();
      }
    }
    stop() {
      if (this.valueListObserver) {
        this.valueListObserver.stop();
        delete this.valueListObserver;
        this.disconnectAllActions();
      }
    }
    get element() {
      return this.context.element;
    }
    get identifier() {
      return this.context.identifier;
    }
    get actionAttribute() {
      return this.schema.actionAttribute;
    }
    get schema() {
      return this.context.schema;
    }
    get bindings() {
      return Array.from(this.bindingsByAction.values());
    }
    connectAction(action) {
      const binding = new Binding(this.context, action);
      this.bindingsByAction.set(action, binding);
      this.delegate.bindingConnected(binding);
    }
    disconnectAction(action) {
      const binding = this.bindingsByAction.get(action);
      if (binding) {
        this.bindingsByAction.delete(action);
        this.delegate.bindingDisconnected(binding);
      }
    }
    disconnectAllActions() {
      this.bindings.forEach((binding) => this.delegate.bindingDisconnected(binding, true));
      this.bindingsByAction.clear();
    }
    parseValueForToken(token) {
      const action = Action.forToken(token, this.schema);
      if (action.identifier == this.identifier) {
        return action;
      }
    }
    elementMatchedValue(element, action) {
      this.connectAction(action);
    }
    elementUnmatchedValue(element, action) {
      this.disconnectAction(action);
    }
  };
  var ValueObserver = class {
    constructor(context, receiver) {
      this.context = context;
      this.receiver = receiver;
      this.stringMapObserver = new StringMapObserver(this.element, this);
      this.valueDescriptorMap = this.controller.valueDescriptorMap;
    }
    start() {
      this.stringMapObserver.start();
      this.invokeChangedCallbacksForDefaultValues();
    }
    stop() {
      this.stringMapObserver.stop();
    }
    get element() {
      return this.context.element;
    }
    get controller() {
      return this.context.controller;
    }
    getStringMapKeyForAttribute(attributeName) {
      if (attributeName in this.valueDescriptorMap) {
        return this.valueDescriptorMap[attributeName].name;
      }
    }
    stringMapKeyAdded(key, attributeName) {
      const descriptor = this.valueDescriptorMap[attributeName];
      if (!this.hasValue(key)) {
        this.invokeChangedCallback(key, descriptor.writer(this.receiver[key]), descriptor.writer(descriptor.defaultValue));
      }
    }
    stringMapValueChanged(value, name, oldValue) {
      const descriptor = this.valueDescriptorNameMap[name];
      if (value === null)
        return;
      if (oldValue === null) {
        oldValue = descriptor.writer(descriptor.defaultValue);
      }
      this.invokeChangedCallback(name, value, oldValue);
    }
    stringMapKeyRemoved(key, attributeName, oldValue) {
      const descriptor = this.valueDescriptorNameMap[key];
      if (this.hasValue(key)) {
        this.invokeChangedCallback(key, descriptor.writer(this.receiver[key]), oldValue);
      } else {
        this.invokeChangedCallback(key, descriptor.writer(descriptor.defaultValue), oldValue);
      }
    }
    invokeChangedCallbacksForDefaultValues() {
      for (const { key, name, defaultValue, writer } of this.valueDescriptors) {
        if (defaultValue != void 0 && !this.controller.data.has(key)) {
          this.invokeChangedCallback(name, writer(defaultValue), void 0);
        }
      }
    }
    invokeChangedCallback(name, rawValue, rawOldValue) {
      const changedMethodName = `${name}Changed`;
      const changedMethod = this.receiver[changedMethodName];
      if (typeof changedMethod == "function") {
        const descriptor = this.valueDescriptorNameMap[name];
        try {
          const value = descriptor.reader(rawValue);
          let oldValue = rawOldValue;
          if (rawOldValue) {
            oldValue = descriptor.reader(rawOldValue);
          }
          changedMethod.call(this.receiver, value, oldValue);
        } catch (error2) {
          if (error2 instanceof TypeError) {
            error2.message = `Stimulus Value "${this.context.identifier}.${descriptor.name}" - ${error2.message}`;
          }
          throw error2;
        }
      }
    }
    get valueDescriptors() {
      const { valueDescriptorMap } = this;
      return Object.keys(valueDescriptorMap).map((key) => valueDescriptorMap[key]);
    }
    get valueDescriptorNameMap() {
      const descriptors = {};
      Object.keys(this.valueDescriptorMap).forEach((key) => {
        const descriptor = this.valueDescriptorMap[key];
        descriptors[descriptor.name] = descriptor;
      });
      return descriptors;
    }
    hasValue(attributeName) {
      const descriptor = this.valueDescriptorNameMap[attributeName];
      const hasMethodName = `has${capitalize(descriptor.name)}`;
      return this.receiver[hasMethodName];
    }
  };
  var TargetObserver = class {
    constructor(context, delegate) {
      this.context = context;
      this.delegate = delegate;
      this.targetsByName = new Multimap();
    }
    start() {
      if (!this.tokenListObserver) {
        this.tokenListObserver = new TokenListObserver(this.element, this.attributeName, this);
        this.tokenListObserver.start();
      }
    }
    stop() {
      if (this.tokenListObserver) {
        this.disconnectAllTargets();
        this.tokenListObserver.stop();
        delete this.tokenListObserver;
      }
    }
    tokenMatched({ element, content: name }) {
      if (this.scope.containsElement(element)) {
        this.connectTarget(element, name);
      }
    }
    tokenUnmatched({ element, content: name }) {
      this.disconnectTarget(element, name);
    }
    connectTarget(element, name) {
      var _a;
      if (!this.targetsByName.has(name, element)) {
        this.targetsByName.add(name, element);
        (_a = this.tokenListObserver) === null || _a === void 0 ? void 0 : _a.pause(() => this.delegate.targetConnected(element, name));
      }
    }
    disconnectTarget(element, name) {
      var _a;
      if (this.targetsByName.has(name, element)) {
        this.targetsByName.delete(name, element);
        (_a = this.tokenListObserver) === null || _a === void 0 ? void 0 : _a.pause(() => this.delegate.targetDisconnected(element, name));
      }
    }
    disconnectAllTargets() {
      for (const name of this.targetsByName.keys) {
        for (const element of this.targetsByName.getValuesForKey(name)) {
          this.disconnectTarget(element, name);
        }
      }
    }
    get attributeName() {
      return `data-${this.context.identifier}-target`;
    }
    get element() {
      return this.context.element;
    }
    get scope() {
      return this.context.scope;
    }
  };
  function readInheritableStaticArrayValues(constructor, propertyName) {
    const ancestors = getAncestorsForConstructor(constructor);
    return Array.from(ancestors.reduce((values, constructor2) => {
      getOwnStaticArrayValues(constructor2, propertyName).forEach((name) => values.add(name));
      return values;
    }, /* @__PURE__ */ new Set()));
  }
  function readInheritableStaticObjectPairs(constructor, propertyName) {
    const ancestors = getAncestorsForConstructor(constructor);
    return ancestors.reduce((pairs, constructor2) => {
      pairs.push(...getOwnStaticObjectPairs(constructor2, propertyName));
      return pairs;
    }, []);
  }
  function getAncestorsForConstructor(constructor) {
    const ancestors = [];
    while (constructor) {
      ancestors.push(constructor);
      constructor = Object.getPrototypeOf(constructor);
    }
    return ancestors.reverse();
  }
  function getOwnStaticArrayValues(constructor, propertyName) {
    const definition = constructor[propertyName];
    return Array.isArray(definition) ? definition : [];
  }
  function getOwnStaticObjectPairs(constructor, propertyName) {
    const definition = constructor[propertyName];
    return definition ? Object.keys(definition).map((key) => [key, definition[key]]) : [];
  }
  var OutletObserver = class {
    constructor(context, delegate) {
      this.started = false;
      this.context = context;
      this.delegate = delegate;
      this.outletsByName = new Multimap();
      this.outletElementsByName = new Multimap();
      this.selectorObserverMap = /* @__PURE__ */ new Map();
      this.attributeObserverMap = /* @__PURE__ */ new Map();
    }
    start() {
      if (!this.started) {
        this.outletDefinitions.forEach((outletName) => {
          this.setupSelectorObserverForOutlet(outletName);
          this.setupAttributeObserverForOutlet(outletName);
        });
        this.started = true;
        this.dependentContexts.forEach((context) => context.refresh());
      }
    }
    refresh() {
      this.selectorObserverMap.forEach((observer) => observer.refresh());
      this.attributeObserverMap.forEach((observer) => observer.refresh());
    }
    stop() {
      if (this.started) {
        this.started = false;
        this.disconnectAllOutlets();
        this.stopSelectorObservers();
        this.stopAttributeObservers();
      }
    }
    stopSelectorObservers() {
      if (this.selectorObserverMap.size > 0) {
        this.selectorObserverMap.forEach((observer) => observer.stop());
        this.selectorObserverMap.clear();
      }
    }
    stopAttributeObservers() {
      if (this.attributeObserverMap.size > 0) {
        this.attributeObserverMap.forEach((observer) => observer.stop());
        this.attributeObserverMap.clear();
      }
    }
    selectorMatched(element, _selector, { outletName }) {
      const outlet = this.getOutlet(element, outletName);
      if (outlet) {
        this.connectOutlet(outlet, element, outletName);
      }
    }
    selectorUnmatched(element, _selector, { outletName }) {
      const outlet = this.getOutletFromMap(element, outletName);
      if (outlet) {
        this.disconnectOutlet(outlet, element, outletName);
      }
    }
    selectorMatchElement(element, { outletName }) {
      const selector = this.selector(outletName);
      const hasOutlet = this.hasOutlet(element, outletName);
      const hasOutletController = element.matches(`[${this.schema.controllerAttribute}~=${outletName}]`);
      if (selector) {
        return hasOutlet && hasOutletController && element.matches(selector);
      } else {
        return false;
      }
    }
    elementMatchedAttribute(_element, attributeName) {
      const outletName = this.getOutletNameFromOutletAttributeName(attributeName);
      if (outletName) {
        this.updateSelectorObserverForOutlet(outletName);
      }
    }
    elementAttributeValueChanged(_element, attributeName) {
      const outletName = this.getOutletNameFromOutletAttributeName(attributeName);
      if (outletName) {
        this.updateSelectorObserverForOutlet(outletName);
      }
    }
    elementUnmatchedAttribute(_element, attributeName) {
      const outletName = this.getOutletNameFromOutletAttributeName(attributeName);
      if (outletName) {
        this.updateSelectorObserverForOutlet(outletName);
      }
    }
    connectOutlet(outlet, element, outletName) {
      var _a;
      if (!this.outletElementsByName.has(outletName, element)) {
        this.outletsByName.add(outletName, outlet);
        this.outletElementsByName.add(outletName, element);
        (_a = this.selectorObserverMap.get(outletName)) === null || _a === void 0 ? void 0 : _a.pause(() => this.delegate.outletConnected(outlet, element, outletName));
      }
    }
    disconnectOutlet(outlet, element, outletName) {
      var _a;
      if (this.outletElementsByName.has(outletName, element)) {
        this.outletsByName.delete(outletName, outlet);
        this.outletElementsByName.delete(outletName, element);
        (_a = this.selectorObserverMap.get(outletName)) === null || _a === void 0 ? void 0 : _a.pause(() => this.delegate.outletDisconnected(outlet, element, outletName));
      }
    }
    disconnectAllOutlets() {
      for (const outletName of this.outletElementsByName.keys) {
        for (const element of this.outletElementsByName.getValuesForKey(outletName)) {
          for (const outlet of this.outletsByName.getValuesForKey(outletName)) {
            this.disconnectOutlet(outlet, element, outletName);
          }
        }
      }
    }
    updateSelectorObserverForOutlet(outletName) {
      const observer = this.selectorObserverMap.get(outletName);
      if (observer) {
        observer.selector = this.selector(outletName);
      }
    }
    setupSelectorObserverForOutlet(outletName) {
      const selector = this.selector(outletName);
      const selectorObserver = new SelectorObserver(document.body, selector, this, { outletName });
      this.selectorObserverMap.set(outletName, selectorObserver);
      selectorObserver.start();
    }
    setupAttributeObserverForOutlet(outletName) {
      const attributeName = this.attributeNameForOutletName(outletName);
      const attributeObserver = new AttributeObserver(this.scope.element, attributeName, this);
      this.attributeObserverMap.set(outletName, attributeObserver);
      attributeObserver.start();
    }
    selector(outletName) {
      return this.scope.outlets.getSelectorForOutletName(outletName);
    }
    attributeNameForOutletName(outletName) {
      return this.scope.schema.outletAttributeForScope(this.identifier, outletName);
    }
    getOutletNameFromOutletAttributeName(attributeName) {
      return this.outletDefinitions.find((outletName) => this.attributeNameForOutletName(outletName) === attributeName);
    }
    get outletDependencies() {
      const dependencies = new Multimap();
      this.router.modules.forEach((module) => {
        const constructor = module.definition.controllerConstructor;
        const outlets = readInheritableStaticArrayValues(constructor, "outlets");
        outlets.forEach((outlet) => dependencies.add(outlet, module.identifier));
      });
      return dependencies;
    }
    get outletDefinitions() {
      return this.outletDependencies.getKeysForValue(this.identifier);
    }
    get dependentControllerIdentifiers() {
      return this.outletDependencies.getValuesForKey(this.identifier);
    }
    get dependentContexts() {
      const identifiers = this.dependentControllerIdentifiers;
      return this.router.contexts.filter((context) => identifiers.includes(context.identifier));
    }
    hasOutlet(element, outletName) {
      return !!this.getOutlet(element, outletName) || !!this.getOutletFromMap(element, outletName);
    }
    getOutlet(element, outletName) {
      return this.application.getControllerForElementAndIdentifier(element, outletName);
    }
    getOutletFromMap(element, outletName) {
      return this.outletsByName.getValuesForKey(outletName).find((outlet) => outlet.element === element);
    }
    get scope() {
      return this.context.scope;
    }
    get schema() {
      return this.context.schema;
    }
    get identifier() {
      return this.context.identifier;
    }
    get application() {
      return this.context.application;
    }
    get router() {
      return this.application.router;
    }
  };
  var Context = class {
    constructor(module, scope) {
      this.logDebugActivity = (functionName, detail = {}) => {
        const { identifier, controller, element } = this;
        detail = Object.assign({ identifier, controller, element }, detail);
        this.application.logDebugActivity(this.identifier, functionName, detail);
      };
      this.module = module;
      this.scope = scope;
      this.controller = new module.controllerConstructor(this);
      this.bindingObserver = new BindingObserver(this, this.dispatcher);
      this.valueObserver = new ValueObserver(this, this.controller);
      this.targetObserver = new TargetObserver(this, this);
      this.outletObserver = new OutletObserver(this, this);
      try {
        this.controller.initialize();
        this.logDebugActivity("initialize");
      } catch (error2) {
        this.handleError(error2, "initializing controller");
      }
    }
    connect() {
      this.bindingObserver.start();
      this.valueObserver.start();
      this.targetObserver.start();
      this.outletObserver.start();
      try {
        this.controller.connect();
        this.logDebugActivity("connect");
      } catch (error2) {
        this.handleError(error2, "connecting controller");
      }
    }
    refresh() {
      this.outletObserver.refresh();
    }
    disconnect() {
      try {
        this.controller.disconnect();
        this.logDebugActivity("disconnect");
      } catch (error2) {
        this.handleError(error2, "disconnecting controller");
      }
      this.outletObserver.stop();
      this.targetObserver.stop();
      this.valueObserver.stop();
      this.bindingObserver.stop();
    }
    get application() {
      return this.module.application;
    }
    get identifier() {
      return this.module.identifier;
    }
    get schema() {
      return this.application.schema;
    }
    get dispatcher() {
      return this.application.dispatcher;
    }
    get element() {
      return this.scope.element;
    }
    get parentElement() {
      return this.element.parentElement;
    }
    handleError(error2, message, detail = {}) {
      const { identifier, controller, element } = this;
      detail = Object.assign({ identifier, controller, element }, detail);
      this.application.handleError(error2, `Error ${message}`, detail);
    }
    targetConnected(element, name) {
      this.invokeControllerMethod(`${name}TargetConnected`, element);
    }
    targetDisconnected(element, name) {
      this.invokeControllerMethod(`${name}TargetDisconnected`, element);
    }
    outletConnected(outlet, element, name) {
      this.invokeControllerMethod(`${namespaceCamelize(name)}OutletConnected`, outlet, element);
    }
    outletDisconnected(outlet, element, name) {
      this.invokeControllerMethod(`${namespaceCamelize(name)}OutletDisconnected`, outlet, element);
    }
    invokeControllerMethod(methodName, ...args) {
      const controller = this.controller;
      if (typeof controller[methodName] == "function") {
        controller[methodName](...args);
      }
    }
  };
  function bless(constructor) {
    return shadow(constructor, getBlessedProperties(constructor));
  }
  function shadow(constructor, properties) {
    const shadowConstructor = extend(constructor);
    const shadowProperties = getShadowProperties(constructor.prototype, properties);
    Object.defineProperties(shadowConstructor.prototype, shadowProperties);
    return shadowConstructor;
  }
  function getBlessedProperties(constructor) {
    const blessings = readInheritableStaticArrayValues(constructor, "blessings");
    return blessings.reduce((blessedProperties, blessing) => {
      const properties = blessing(constructor);
      for (const key in properties) {
        const descriptor = blessedProperties[key] || {};
        blessedProperties[key] = Object.assign(descriptor, properties[key]);
      }
      return blessedProperties;
    }, {});
  }
  function getShadowProperties(prototype, properties) {
    return getOwnKeys(properties).reduce((shadowProperties, key) => {
      const descriptor = getShadowedDescriptor(prototype, properties, key);
      if (descriptor) {
        Object.assign(shadowProperties, { [key]: descriptor });
      }
      return shadowProperties;
    }, {});
  }
  function getShadowedDescriptor(prototype, properties, key) {
    const shadowingDescriptor = Object.getOwnPropertyDescriptor(prototype, key);
    const shadowedByValue = shadowingDescriptor && "value" in shadowingDescriptor;
    if (!shadowedByValue) {
      const descriptor = Object.getOwnPropertyDescriptor(properties, key).value;
      if (shadowingDescriptor) {
        descriptor.get = shadowingDescriptor.get || descriptor.get;
        descriptor.set = shadowingDescriptor.set || descriptor.set;
      }
      return descriptor;
    }
  }
  var getOwnKeys = (() => {
    if (typeof Object.getOwnPropertySymbols == "function") {
      return (object) => [...Object.getOwnPropertyNames(object), ...Object.getOwnPropertySymbols(object)];
    } else {
      return Object.getOwnPropertyNames;
    }
  })();
  var extend = (() => {
    function extendWithReflect(constructor) {
      function extended() {
        return Reflect.construct(constructor, arguments, new.target);
      }
      extended.prototype = Object.create(constructor.prototype, {
        constructor: { value: extended }
      });
      Reflect.setPrototypeOf(extended, constructor);
      return extended;
    }
    function testReflectExtension() {
      const a = function() {
        this.a.call(this);
      };
      const b = extendWithReflect(a);
      b.prototype.a = function() {
      };
      return new b();
    }
    try {
      testReflectExtension();
      return extendWithReflect;
    } catch (error2) {
      return (constructor) => class extended extends constructor {
      };
    }
  })();
  function blessDefinition(definition) {
    return {
      identifier: definition.identifier,
      controllerConstructor: bless(definition.controllerConstructor)
    };
  }
  var Module = class {
    constructor(application, definition) {
      this.application = application;
      this.definition = blessDefinition(definition);
      this.contextsByScope = /* @__PURE__ */ new WeakMap();
      this.connectedContexts = /* @__PURE__ */ new Set();
    }
    get identifier() {
      return this.definition.identifier;
    }
    get controllerConstructor() {
      return this.definition.controllerConstructor;
    }
    get contexts() {
      return Array.from(this.connectedContexts);
    }
    connectContextForScope(scope) {
      const context = this.fetchContextForScope(scope);
      this.connectedContexts.add(context);
      context.connect();
    }
    disconnectContextForScope(scope) {
      const context = this.contextsByScope.get(scope);
      if (context) {
        this.connectedContexts.delete(context);
        context.disconnect();
      }
    }
    fetchContextForScope(scope) {
      let context = this.contextsByScope.get(scope);
      if (!context) {
        context = new Context(this, scope);
        this.contextsByScope.set(scope, context);
      }
      return context;
    }
  };
  var ClassMap = class {
    constructor(scope) {
      this.scope = scope;
    }
    has(name) {
      return this.data.has(this.getDataKey(name));
    }
    get(name) {
      return this.getAll(name)[0];
    }
    getAll(name) {
      const tokenString = this.data.get(this.getDataKey(name)) || "";
      return tokenize(tokenString);
    }
    getAttributeName(name) {
      return this.data.getAttributeNameForKey(this.getDataKey(name));
    }
    getDataKey(name) {
      return `${name}-class`;
    }
    get data() {
      return this.scope.data;
    }
  };
  var DataMap = class {
    constructor(scope) {
      this.scope = scope;
    }
    get element() {
      return this.scope.element;
    }
    get identifier() {
      return this.scope.identifier;
    }
    get(key) {
      const name = this.getAttributeNameForKey(key);
      return this.element.getAttribute(name);
    }
    set(key, value) {
      const name = this.getAttributeNameForKey(key);
      this.element.setAttribute(name, value);
      return this.get(key);
    }
    has(key) {
      const name = this.getAttributeNameForKey(key);
      return this.element.hasAttribute(name);
    }
    delete(key) {
      if (this.has(key)) {
        const name = this.getAttributeNameForKey(key);
        this.element.removeAttribute(name);
        return true;
      } else {
        return false;
      }
    }
    getAttributeNameForKey(key) {
      return `data-${this.identifier}-${dasherize(key)}`;
    }
  };
  var Guide = class {
    constructor(logger) {
      this.warnedKeysByObject = /* @__PURE__ */ new WeakMap();
      this.logger = logger;
    }
    warn(object, key, message) {
      let warnedKeys = this.warnedKeysByObject.get(object);
      if (!warnedKeys) {
        warnedKeys = /* @__PURE__ */ new Set();
        this.warnedKeysByObject.set(object, warnedKeys);
      }
      if (!warnedKeys.has(key)) {
        warnedKeys.add(key);
        this.logger.warn(message, object);
      }
    }
  };
  function attributeValueContainsToken(attributeName, token) {
    return `[${attributeName}~="${token}"]`;
  }
  var TargetSet = class {
    constructor(scope) {
      this.scope = scope;
    }
    get element() {
      return this.scope.element;
    }
    get identifier() {
      return this.scope.identifier;
    }
    get schema() {
      return this.scope.schema;
    }
    has(targetName) {
      return this.find(targetName) != null;
    }
    find(...targetNames) {
      return targetNames.reduce((target, targetName) => target || this.findTarget(targetName) || this.findLegacyTarget(targetName), void 0);
    }
    findAll(...targetNames) {
      return targetNames.reduce((targets, targetName) => [
        ...targets,
        ...this.findAllTargets(targetName),
        ...this.findAllLegacyTargets(targetName)
      ], []);
    }
    findTarget(targetName) {
      const selector = this.getSelectorForTargetName(targetName);
      return this.scope.findElement(selector);
    }
    findAllTargets(targetName) {
      const selector = this.getSelectorForTargetName(targetName);
      return this.scope.findAllElements(selector);
    }
    getSelectorForTargetName(targetName) {
      const attributeName = this.schema.targetAttributeForScope(this.identifier);
      return attributeValueContainsToken(attributeName, targetName);
    }
    findLegacyTarget(targetName) {
      const selector = this.getLegacySelectorForTargetName(targetName);
      return this.deprecate(this.scope.findElement(selector), targetName);
    }
    findAllLegacyTargets(targetName) {
      const selector = this.getLegacySelectorForTargetName(targetName);
      return this.scope.findAllElements(selector).map((element) => this.deprecate(element, targetName));
    }
    getLegacySelectorForTargetName(targetName) {
      const targetDescriptor = `${this.identifier}.${targetName}`;
      return attributeValueContainsToken(this.schema.targetAttribute, targetDescriptor);
    }
    deprecate(element, targetName) {
      if (element) {
        const { identifier } = this;
        const attributeName = this.schema.targetAttribute;
        const revisedAttributeName = this.schema.targetAttributeForScope(identifier);
        this.guide.warn(element, `target:${targetName}`, `Please replace ${attributeName}="${identifier}.${targetName}" with ${revisedAttributeName}="${targetName}". The ${attributeName} attribute is deprecated and will be removed in a future version of Stimulus.`);
      }
      return element;
    }
    get guide() {
      return this.scope.guide;
    }
  };
  var OutletSet = class {
    constructor(scope, controllerElement) {
      this.scope = scope;
      this.controllerElement = controllerElement;
    }
    get element() {
      return this.scope.element;
    }
    get identifier() {
      return this.scope.identifier;
    }
    get schema() {
      return this.scope.schema;
    }
    has(outletName) {
      return this.find(outletName) != null;
    }
    find(...outletNames) {
      return outletNames.reduce((outlet, outletName) => outlet || this.findOutlet(outletName), void 0);
    }
    findAll(...outletNames) {
      return outletNames.reduce((outlets, outletName) => [...outlets, ...this.findAllOutlets(outletName)], []);
    }
    getSelectorForOutletName(outletName) {
      const attributeName = this.schema.outletAttributeForScope(this.identifier, outletName);
      return this.controllerElement.getAttribute(attributeName);
    }
    findOutlet(outletName) {
      const selector = this.getSelectorForOutletName(outletName);
      if (selector)
        return this.findElement(selector, outletName);
    }
    findAllOutlets(outletName) {
      const selector = this.getSelectorForOutletName(outletName);
      return selector ? this.findAllElements(selector, outletName) : [];
    }
    findElement(selector, outletName) {
      const elements = this.scope.queryElements(selector);
      return elements.filter((element) => this.matchesElement(element, selector, outletName))[0];
    }
    findAllElements(selector, outletName) {
      const elements = this.scope.queryElements(selector);
      return elements.filter((element) => this.matchesElement(element, selector, outletName));
    }
    matchesElement(element, selector, outletName) {
      const controllerAttribute = element.getAttribute(this.scope.schema.controllerAttribute) || "";
      return element.matches(selector) && controllerAttribute.split(" ").includes(outletName);
    }
  };
  var Scope = class _Scope {
    constructor(schema, element, identifier, logger) {
      this.targets = new TargetSet(this);
      this.classes = new ClassMap(this);
      this.data = new DataMap(this);
      this.containsElement = (element2) => {
        return element2.closest(this.controllerSelector) === this.element;
      };
      this.schema = schema;
      this.element = element;
      this.identifier = identifier;
      this.guide = new Guide(logger);
      this.outlets = new OutletSet(this.documentScope, element);
    }
    findElement(selector) {
      return this.element.matches(selector) ? this.element : this.queryElements(selector).find(this.containsElement);
    }
    findAllElements(selector) {
      return [
        ...this.element.matches(selector) ? [this.element] : [],
        ...this.queryElements(selector).filter(this.containsElement)
      ];
    }
    queryElements(selector) {
      return Array.from(this.element.querySelectorAll(selector));
    }
    get controllerSelector() {
      return attributeValueContainsToken(this.schema.controllerAttribute, this.identifier);
    }
    get isDocumentScope() {
      return this.element === document.documentElement;
    }
    get documentScope() {
      return this.isDocumentScope ? this : new _Scope(this.schema, document.documentElement, this.identifier, this.guide.logger);
    }
  };
  var ScopeObserver = class {
    constructor(element, schema, delegate) {
      this.element = element;
      this.schema = schema;
      this.delegate = delegate;
      this.valueListObserver = new ValueListObserver(this.element, this.controllerAttribute, this);
      this.scopesByIdentifierByElement = /* @__PURE__ */ new WeakMap();
      this.scopeReferenceCounts = /* @__PURE__ */ new WeakMap();
    }
    start() {
      this.valueListObserver.start();
    }
    stop() {
      this.valueListObserver.stop();
    }
    get controllerAttribute() {
      return this.schema.controllerAttribute;
    }
    parseValueForToken(token) {
      const { element, content: identifier } = token;
      return this.parseValueForElementAndIdentifier(element, identifier);
    }
    parseValueForElementAndIdentifier(element, identifier) {
      const scopesByIdentifier = this.fetchScopesByIdentifierForElement(element);
      let scope = scopesByIdentifier.get(identifier);
      if (!scope) {
        scope = this.delegate.createScopeForElementAndIdentifier(element, identifier);
        scopesByIdentifier.set(identifier, scope);
      }
      return scope;
    }
    elementMatchedValue(element, value) {
      const referenceCount = (this.scopeReferenceCounts.get(value) || 0) + 1;
      this.scopeReferenceCounts.set(value, referenceCount);
      if (referenceCount == 1) {
        this.delegate.scopeConnected(value);
      }
    }
    elementUnmatchedValue(element, value) {
      const referenceCount = this.scopeReferenceCounts.get(value);
      if (referenceCount) {
        this.scopeReferenceCounts.set(value, referenceCount - 1);
        if (referenceCount == 1) {
          this.delegate.scopeDisconnected(value);
        }
      }
    }
    fetchScopesByIdentifierForElement(element) {
      let scopesByIdentifier = this.scopesByIdentifierByElement.get(element);
      if (!scopesByIdentifier) {
        scopesByIdentifier = /* @__PURE__ */ new Map();
        this.scopesByIdentifierByElement.set(element, scopesByIdentifier);
      }
      return scopesByIdentifier;
    }
  };
  var Router = class {
    constructor(application) {
      this.application = application;
      this.scopeObserver = new ScopeObserver(this.element, this.schema, this);
      this.scopesByIdentifier = new Multimap();
      this.modulesByIdentifier = /* @__PURE__ */ new Map();
    }
    get element() {
      return this.application.element;
    }
    get schema() {
      return this.application.schema;
    }
    get logger() {
      return this.application.logger;
    }
    get controllerAttribute() {
      return this.schema.controllerAttribute;
    }
    get modules() {
      return Array.from(this.modulesByIdentifier.values());
    }
    get contexts() {
      return this.modules.reduce((contexts, module) => contexts.concat(module.contexts), []);
    }
    start() {
      this.scopeObserver.start();
    }
    stop() {
      this.scopeObserver.stop();
    }
    loadDefinition(definition) {
      this.unloadIdentifier(definition.identifier);
      const module = new Module(this.application, definition);
      this.connectModule(module);
      const afterLoad = definition.controllerConstructor.afterLoad;
      if (afterLoad) {
        afterLoad.call(definition.controllerConstructor, definition.identifier, this.application);
      }
    }
    unloadIdentifier(identifier) {
      const module = this.modulesByIdentifier.get(identifier);
      if (module) {
        this.disconnectModule(module);
      }
    }
    getContextForElementAndIdentifier(element, identifier) {
      const module = this.modulesByIdentifier.get(identifier);
      if (module) {
        return module.contexts.find((context) => context.element == element);
      }
    }
    proposeToConnectScopeForElementAndIdentifier(element, identifier) {
      const scope = this.scopeObserver.parseValueForElementAndIdentifier(element, identifier);
      if (scope) {
        this.scopeObserver.elementMatchedValue(scope.element, scope);
      } else {
        console.error(`Couldn't find or create scope for identifier: "${identifier}" and element:`, element);
      }
    }
    handleError(error2, message, detail) {
      this.application.handleError(error2, message, detail);
    }
    createScopeForElementAndIdentifier(element, identifier) {
      return new Scope(this.schema, element, identifier, this.logger);
    }
    scopeConnected(scope) {
      this.scopesByIdentifier.add(scope.identifier, scope);
      const module = this.modulesByIdentifier.get(scope.identifier);
      if (module) {
        module.connectContextForScope(scope);
      }
    }
    scopeDisconnected(scope) {
      this.scopesByIdentifier.delete(scope.identifier, scope);
      const module = this.modulesByIdentifier.get(scope.identifier);
      if (module) {
        module.disconnectContextForScope(scope);
      }
    }
    connectModule(module) {
      this.modulesByIdentifier.set(module.identifier, module);
      const scopes = this.scopesByIdentifier.getValuesForKey(module.identifier);
      scopes.forEach((scope) => module.connectContextForScope(scope));
    }
    disconnectModule(module) {
      this.modulesByIdentifier.delete(module.identifier);
      const scopes = this.scopesByIdentifier.getValuesForKey(module.identifier);
      scopes.forEach((scope) => module.disconnectContextForScope(scope));
    }
  };
  var defaultSchema = {
    controllerAttribute: "data-controller",
    actionAttribute: "data-action",
    targetAttribute: "data-target",
    targetAttributeForScope: (identifier) => `data-${identifier}-target`,
    outletAttributeForScope: (identifier, outlet) => `data-${identifier}-${outlet}-outlet`,
    keyMappings: Object.assign(Object.assign({ enter: "Enter", tab: "Tab", esc: "Escape", space: " ", up: "ArrowUp", down: "ArrowDown", left: "ArrowLeft", right: "ArrowRight", home: "Home", end: "End", page_up: "PageUp", page_down: "PageDown" }, objectFromEntries("abcdefghijklmnopqrstuvwxyz".split("").map((c) => [c, c]))), objectFromEntries("0123456789".split("").map((n) => [n, n])))
  };
  function objectFromEntries(array) {
    return array.reduce((memo, [k, v]) => Object.assign(Object.assign({}, memo), { [k]: v }), {});
  }
  var Application = class {
    constructor(element = document.documentElement, schema = defaultSchema) {
      this.logger = console;
      this.debug = false;
      this.logDebugActivity = (identifier, functionName, detail = {}) => {
        if (this.debug) {
          this.logFormattedMessage(identifier, functionName, detail);
        }
      };
      this.element = element;
      this.schema = schema;
      this.dispatcher = new Dispatcher(this);
      this.router = new Router(this);
      this.actionDescriptorFilters = Object.assign({}, defaultActionDescriptorFilters);
    }
    static start(element, schema) {
      const application = new this(element, schema);
      application.start();
      return application;
    }
    async start() {
      await domReady();
      this.logDebugActivity("application", "starting");
      this.dispatcher.start();
      this.router.start();
      this.logDebugActivity("application", "start");
    }
    stop() {
      this.logDebugActivity("application", "stopping");
      this.dispatcher.stop();
      this.router.stop();
      this.logDebugActivity("application", "stop");
    }
    register(identifier, controllerConstructor) {
      this.load({ identifier, controllerConstructor });
    }
    registerActionOption(name, filter) {
      this.actionDescriptorFilters[name] = filter;
    }
    load(head, ...rest) {
      const definitions = Array.isArray(head) ? head : [head, ...rest];
      definitions.forEach((definition) => {
        if (definition.controllerConstructor.shouldLoad) {
          this.router.loadDefinition(definition);
        }
      });
    }
    unload(head, ...rest) {
      const identifiers = Array.isArray(head) ? head : [head, ...rest];
      identifiers.forEach((identifier) => this.router.unloadIdentifier(identifier));
    }
    get controllers() {
      return this.router.contexts.map((context) => context.controller);
    }
    getControllerForElementAndIdentifier(element, identifier) {
      const context = this.router.getContextForElementAndIdentifier(element, identifier);
      return context ? context.controller : null;
    }
    handleError(error2, message, detail) {
      var _a;
      this.logger.error(`%s

%o

%o`, message, error2, detail);
      (_a = window.onerror) === null || _a === void 0 ? void 0 : _a.call(window, message, "", 0, 0, error2);
    }
    logFormattedMessage(identifier, functionName, detail = {}) {
      detail = Object.assign({ application: this }, detail);
      this.logger.groupCollapsed(`${identifier} #${functionName}`);
      this.logger.log("details:", Object.assign({}, detail));
      this.logger.groupEnd();
    }
  };
  function domReady() {
    return new Promise((resolve) => {
      if (document.readyState == "loading") {
        document.addEventListener("DOMContentLoaded", () => resolve());
      } else {
        resolve();
      }
    });
  }
  function ClassPropertiesBlessing(constructor) {
    const classes = readInheritableStaticArrayValues(constructor, "classes");
    return classes.reduce((properties, classDefinition) => {
      return Object.assign(properties, propertiesForClassDefinition(classDefinition));
    }, {});
  }
  function propertiesForClassDefinition(key) {
    return {
      [`${key}Class`]: {
        get() {
          const { classes } = this;
          if (classes.has(key)) {
            return classes.get(key);
          } else {
            const attribute = classes.getAttributeName(key);
            throw new Error(`Missing attribute "${attribute}"`);
          }
        }
      },
      [`${key}Classes`]: {
        get() {
          return this.classes.getAll(key);
        }
      },
      [`has${capitalize(key)}Class`]: {
        get() {
          return this.classes.has(key);
        }
      }
    };
  }
  function OutletPropertiesBlessing(constructor) {
    const outlets = readInheritableStaticArrayValues(constructor, "outlets");
    return outlets.reduce((properties, outletDefinition) => {
      return Object.assign(properties, propertiesForOutletDefinition(outletDefinition));
    }, {});
  }
  function getOutletController(controller, element, identifier) {
    return controller.application.getControllerForElementAndIdentifier(element, identifier);
  }
  function getControllerAndEnsureConnectedScope(controller, element, outletName) {
    let outletController = getOutletController(controller, element, outletName);
    if (outletController)
      return outletController;
    controller.application.router.proposeToConnectScopeForElementAndIdentifier(element, outletName);
    outletController = getOutletController(controller, element, outletName);
    if (outletController)
      return outletController;
  }
  function propertiesForOutletDefinition(name) {
    const camelizedName = namespaceCamelize(name);
    return {
      [`${camelizedName}Outlet`]: {
        get() {
          const outletElement = this.outlets.find(name);
          const selector = this.outlets.getSelectorForOutletName(name);
          if (outletElement) {
            const outletController = getControllerAndEnsureConnectedScope(this, outletElement, name);
            if (outletController)
              return outletController;
            throw new Error(`The provided outlet element is missing an outlet controller "${name}" instance for host controller "${this.identifier}"`);
          }
          throw new Error(`Missing outlet element "${name}" for host controller "${this.identifier}". Stimulus couldn't find a matching outlet element using selector "${selector}".`);
        }
      },
      [`${camelizedName}Outlets`]: {
        get() {
          const outlets = this.outlets.findAll(name);
          if (outlets.length > 0) {
            return outlets.map((outletElement) => {
              const outletController = getControllerAndEnsureConnectedScope(this, outletElement, name);
              if (outletController)
                return outletController;
              console.warn(`The provided outlet element is missing an outlet controller "${name}" instance for host controller "${this.identifier}"`, outletElement);
            }).filter((controller) => controller);
          }
          return [];
        }
      },
      [`${camelizedName}OutletElement`]: {
        get() {
          const outletElement = this.outlets.find(name);
          const selector = this.outlets.getSelectorForOutletName(name);
          if (outletElement) {
            return outletElement;
          } else {
            throw new Error(`Missing outlet element "${name}" for host controller "${this.identifier}". Stimulus couldn't find a matching outlet element using selector "${selector}".`);
          }
        }
      },
      [`${camelizedName}OutletElements`]: {
        get() {
          return this.outlets.findAll(name);
        }
      },
      [`has${capitalize(camelizedName)}Outlet`]: {
        get() {
          return this.outlets.has(name);
        }
      }
    };
  }
  function TargetPropertiesBlessing(constructor) {
    const targets = readInheritableStaticArrayValues(constructor, "targets");
    return targets.reduce((properties, targetDefinition) => {
      return Object.assign(properties, propertiesForTargetDefinition(targetDefinition));
    }, {});
  }
  function propertiesForTargetDefinition(name) {
    return {
      [`${name}Target`]: {
        get() {
          const target = this.targets.find(name);
          if (target) {
            return target;
          } else {
            throw new Error(`Missing target element "${name}" for "${this.identifier}" controller`);
          }
        }
      },
      [`${name}Targets`]: {
        get() {
          return this.targets.findAll(name);
        }
      },
      [`has${capitalize(name)}Target`]: {
        get() {
          return this.targets.has(name);
        }
      }
    };
  }
  function ValuePropertiesBlessing(constructor) {
    const valueDefinitionPairs = readInheritableStaticObjectPairs(constructor, "values");
    const propertyDescriptorMap = {
      valueDescriptorMap: {
        get() {
          return valueDefinitionPairs.reduce((result, valueDefinitionPair) => {
            const valueDescriptor = parseValueDefinitionPair(valueDefinitionPair, this.identifier);
            const attributeName = this.data.getAttributeNameForKey(valueDescriptor.key);
            return Object.assign(result, { [attributeName]: valueDescriptor });
          }, {});
        }
      }
    };
    return valueDefinitionPairs.reduce((properties, valueDefinitionPair) => {
      return Object.assign(properties, propertiesForValueDefinitionPair(valueDefinitionPair));
    }, propertyDescriptorMap);
  }
  function propertiesForValueDefinitionPair(valueDefinitionPair, controller) {
    const definition = parseValueDefinitionPair(valueDefinitionPair, controller);
    const { key, name, reader: read, writer: write } = definition;
    return {
      [name]: {
        get() {
          const value = this.data.get(key);
          if (value !== null) {
            return read(value);
          } else {
            return definition.defaultValue;
          }
        },
        set(value) {
          if (value === void 0) {
            this.data.delete(key);
          } else {
            this.data.set(key, write(value));
          }
        }
      },
      [`has${capitalize(name)}`]: {
        get() {
          return this.data.has(key) || definition.hasCustomDefaultValue;
        }
      }
    };
  }
  function parseValueDefinitionPair([token, typeDefinition], controller) {
    return valueDescriptorForTokenAndTypeDefinition({
      controller,
      token,
      typeDefinition
    });
  }
  function parseValueTypeConstant(constant) {
    switch (constant) {
      case Array:
        return "array";
      case Boolean:
        return "boolean";
      case Number:
        return "number";
      case Object:
        return "object";
      case String:
        return "string";
    }
  }
  function parseValueTypeDefault(defaultValue) {
    switch (typeof defaultValue) {
      case "boolean":
        return "boolean";
      case "number":
        return "number";
      case "string":
        return "string";
    }
    if (Array.isArray(defaultValue))
      return "array";
    if (Object.prototype.toString.call(defaultValue) === "[object Object]")
      return "object";
  }
  function parseValueTypeObject(payload) {
    const { controller, token, typeObject } = payload;
    const hasType = isSomething(typeObject.type);
    const hasDefault = isSomething(typeObject.default);
    const fullObject = hasType && hasDefault;
    const onlyType = hasType && !hasDefault;
    const onlyDefault = !hasType && hasDefault;
    const typeFromObject = parseValueTypeConstant(typeObject.type);
    const typeFromDefaultValue = parseValueTypeDefault(payload.typeObject.default);
    if (onlyType)
      return typeFromObject;
    if (onlyDefault)
      return typeFromDefaultValue;
    if (typeFromObject !== typeFromDefaultValue) {
      const propertyPath = controller ? `${controller}.${token}` : token;
      throw new Error(`The specified default value for the Stimulus Value "${propertyPath}" must match the defined type "${typeFromObject}". The provided default value of "${typeObject.default}" is of type "${typeFromDefaultValue}".`);
    }
    if (fullObject)
      return typeFromObject;
  }
  function parseValueTypeDefinition(payload) {
    const { controller, token, typeDefinition } = payload;
    const typeObject = { controller, token, typeObject: typeDefinition };
    const typeFromObject = parseValueTypeObject(typeObject);
    const typeFromDefaultValue = parseValueTypeDefault(typeDefinition);
    const typeFromConstant = parseValueTypeConstant(typeDefinition);
    const type = typeFromObject || typeFromDefaultValue || typeFromConstant;
    if (type)
      return type;
    const propertyPath = controller ? `${controller}.${typeDefinition}` : token;
    throw new Error(`Unknown value type "${propertyPath}" for "${token}" value`);
  }
  function defaultValueForDefinition(typeDefinition) {
    const constant = parseValueTypeConstant(typeDefinition);
    if (constant)
      return defaultValuesByType[constant];
    const hasDefault = hasProperty(typeDefinition, "default");
    const hasType = hasProperty(typeDefinition, "type");
    const typeObject = typeDefinition;
    if (hasDefault)
      return typeObject.default;
    if (hasType) {
      const { type } = typeObject;
      const constantFromType = parseValueTypeConstant(type);
      if (constantFromType)
        return defaultValuesByType[constantFromType];
    }
    return typeDefinition;
  }
  function valueDescriptorForTokenAndTypeDefinition(payload) {
    const { token, typeDefinition } = payload;
    const key = `${dasherize(token)}-value`;
    const type = parseValueTypeDefinition(payload);
    return {
      type,
      key,
      name: camelize(key),
      get defaultValue() {
        return defaultValueForDefinition(typeDefinition);
      },
      get hasCustomDefaultValue() {
        return parseValueTypeDefault(typeDefinition) !== void 0;
      },
      reader: readers[type],
      writer: writers[type] || writers.default
    };
  }
  var defaultValuesByType = {
    get array() {
      return [];
    },
    boolean: false,
    number: 0,
    get object() {
      return {};
    },
    string: ""
  };
  var readers = {
    array(value) {
      const array = JSON.parse(value);
      if (!Array.isArray(array)) {
        throw new TypeError(`expected value of type "array" but instead got value "${value}" of type "${parseValueTypeDefault(array)}"`);
      }
      return array;
    },
    boolean(value) {
      return !(value == "0" || String(value).toLowerCase() == "false");
    },
    number(value) {
      return Number(value.replace(/_/g, ""));
    },
    object(value) {
      const object = JSON.parse(value);
      if (object === null || typeof object != "object" || Array.isArray(object)) {
        throw new TypeError(`expected value of type "object" but instead got value "${value}" of type "${parseValueTypeDefault(object)}"`);
      }
      return object;
    },
    string(value) {
      return value;
    }
  };
  var writers = {
    default: writeString,
    array: writeJSON,
    object: writeJSON
  };
  function writeJSON(value) {
    return JSON.stringify(value);
  }
  function writeString(value) {
    return `${value}`;
  }
  var Controller = class {
    constructor(context) {
      this.context = context;
    }
    static get shouldLoad() {
      return true;
    }
    static afterLoad(_identifier, _application) {
      return;
    }
    get application() {
      return this.context.application;
    }
    get scope() {
      return this.context.scope;
    }
    get element() {
      return this.scope.element;
    }
    get identifier() {
      return this.scope.identifier;
    }
    get targets() {
      return this.scope.targets;
    }
    get outlets() {
      return this.scope.outlets;
    }
    get classes() {
      return this.scope.classes;
    }
    get data() {
      return this.scope.data;
    }
    initialize() {
    }
    connect() {
    }
    disconnect() {
    }
    dispatch(eventName, { target = this.element, detail = {}, prefix = this.identifier, bubbles = true, cancelable = true } = {}) {
      const type = prefix ? `${prefix}:${eventName}` : eventName;
      const event = new CustomEvent(type, { detail, bubbles, cancelable });
      target.dispatchEvent(event);
      return event;
    }
  };
  Controller.blessings = [
    ClassPropertiesBlessing,
    TargetPropertiesBlessing,
    ValuePropertiesBlessing,
    OutletPropertiesBlessing
  ];
  Controller.targets = [];
  Controller.outlets = [];
  Controller.values = {};

  // resources/build/js/controllers/buy_now_controller.js
  var buy_now_controller_default = class extends Controller {
    connect() {
      console.log("Stripe Buy Now controller connected", {
        productId: this.productIdValue,
        productNid: this.productNidValue
      });
    }
    /**
     * Handle Buy Now button click
     * @param {Event} event
     */
    submit(event) {
      event.preventDefault();
      console.log("Buy Now clicked");
      const button = event.currentTarget;
      this.setLoadingState(button, true);
      const amountInput = document.getElementById("amountToBasket");
      const amount = amountInput ? amountInput.value : 1;
      const productForm = document.querySelector(".js-oxProductForm");
      const formData = productForm ? new FormData(productForm) : new FormData();
      const fields = {
        "cl": "stripe_checkout_onepage",
        "fnc": "addProductAndCheckout",
        "aid": this.productIdValue,
        "anid": this.productNidValue,
        "parentid": this.parentIdValue,
        "am": amount,
        "stoken": this.csrfTokenValue
      };
      for (let [key, value] of formData.entries()) {
        if (!fields[key] && key !== "fnc" && key !== "cl") {
          fields[key] = value;
        }
      }
      console.log("Submitting Buy Now form:", fields);
      this.submitForm(fields);
    }
    /**
     * Create hidden form and submit
     * @param {Object} fields
     */
    submitForm(fields) {
      const form = document.createElement("form");
      form.method = "POST";
      form.action = this.actionUrlValue;
      form.style.display = "none";
      Object.entries(fields).forEach(([name, value]) => {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = value;
        form.appendChild(input);
      });
      document.body.appendChild(form);
      setTimeout(() => {
        form.submit();
      }, 100);
    }
    /**
     * Set button loading state
     * @param {HTMLElement} button
     * @param {Boolean} isLoading
     */
    setLoadingState(button, isLoading) {
      if (isLoading) {
        button.dataset.originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = `
        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
        Processing...
      `;
      } else {
        button.disabled = false;
        if (button.dataset.originalHtml) {
          button.innerHTML = button.dataset.originalHtml;
        }
      }
    }
    /**
     * Handle errors
     * @param {Error} error
     */
    handleError(error2) {
      console.error("Buy Now error:", error2);
      alert("Sorry, there was an error processing your request. Please try again.");
      if (this.hasButtonTarget) {
        this.setLoadingState(this.buttonTarget, false);
      }
    }
  };
  __publicField(buy_now_controller_default, "values", {
    productId: String,
    productNid: String,
    parentId: String,
    actionUrl: String,
    csrfToken: String
  });
  __publicField(buy_now_controller_default, "targets", ["button"]);

  // resources/build/js/controllers/stripe_order_controller.js
  var stripe_order_controller_default = class extends Controller {
    connect() {
      console.log("Stripe Order controller connected", {
        hasPublishableKey: !!this.publishableKeyValue,
        hasClientSecret: !!this.clientSecretValue,
        publishableKey: this.publishableKeyValue ? this.publishableKeyValue.substring(0, 10) + "..." : "missing",
        clientSecretLength: this.clientSecretValue ? this.clientSecretValue.length : 0
      });
      const debugInfo = this.element.getAttribute("data-debug-info");
      if (debugInfo) {
        console.log("Debug info:", debugInfo);
      }
      if (!this.publishableKeyValue) {
        console.error("Stripe publishable key not configured");
        this.showError("Stripe configuration error. Please contact support.");
        return;
      }
      this.initializeStripe();
    }
    disconnect() {
      if (this.paymentElement) {
        this.paymentElement.unmount();
      }
    }
    /**
     * Initialize Stripe and mount Payment Element
     */
    async initializeStripe() {
      if (typeof Stripe === "undefined") {
        console.log("Waiting for Stripe.js to load...");
        await this.waitForStripe();
      }
      try {
        this.stripe = Stripe(this.publishableKeyValue);
        const appearance = {
          theme: "stripe",
          variables: {
            colorPrimary: "#0570de",
            colorBackground: "#ffffff",
            colorText: "#30313d",
            fontFamily: "system-ui, sans-serif",
            borderRadius: "4px"
          }
        };
        this.elements = this.stripe.elements({
          appearance
        });
        const card = this.elements.create("card");
        card.mount("#card-element");
        console.log("Stripe Payment Element initialized successfully");
      } catch (error2) {
        console.error("Failed to initialize Stripe:", error2);
        this.showError("Failed to initialize payment form. Please refresh the page.");
      }
    }
    /**
     * Wait for Stripe.js library to load
     * @returns {Promise}
     */
    waitForStripe() {
      return new Promise((resolve) => {
        const checkStripe = () => {
          if (typeof Stripe !== "undefined") {
            resolve();
          } else {
            setTimeout(checkStripe, 100);
          }
        };
        checkStripe();
      });
    }
    /**
     * Show loading indicator
     */
    showLoading() {
      if (this.hasLoadingTarget) {
        this.loadingTarget.style.display = "block";
      }
    }
    /**
     * Show error message
     * @param {String} message
     */
    showError(message) {
      const errorDiv = document.getElementById("payment-errors");
      if (errorDiv && this.hasErrorMessageTarget) {
        errorDiv.style.display = "block";
        this.errorMessageTarget.textContent = message;
      }
    }
    /**
     * Hide error message
     */
    hideError() {
      const errorDiv = document.getElementById("payment-errors");
      if (errorDiv) {
        errorDiv.style.display = "none";
        if (this.hasErrorMessageTarget) {
          this.errorMessageTarget.textContent = "";
        }
      }
    }
    /**
     * Hide loading indicator
     */
    hideLoading() {
      if (this.hasLoadingTarget) {
        this.loadingTarget.style.display = "none";
      }
    }
    /**
     * Get Stripe instance (for form submission handler)
     * @returns {Object} Stripe instance
     */
    getStripe() {
      return this.stripe;
    }
    /**
     * Get Elements instance (for form submission handler)
     * @returns {Object} Elements instance
     */
    getElements() {
      return this.elements;
    }
    /**
     * Handle order form submission
     * This method should be called when the order confirmation button is clicked
     * @param {Event} event - Form submission event
     */
    async handlePayment(event) {
      event.preventDefault();
      if (!this.stripe || !this.elements) {
        this.showError("Payment form not initialized. Please refresh the page.");
        return;
      }
      this.showLoading();
      this.hideError();
      try {
        const shopUrl = window.location.origin + window.location.pathname.split("/index.php")[0];
        const returnUrl = shopUrl + "/index.php?cl=order&fnc=stripeReturn";
        console.log("Confirming payment with return URL:", returnUrl);
        const { error: error2 } = await this.stripe.confirmPayment({
          elements: this.elements,
          confirmParams: {
            return_url: returnUrl
          }
        });
        if (error2) {
          console.error("Payment confirmation error:", error2);
          if (error2.type === "card_error" || error2.type === "validation_error") {
            this.showError(error2.message);
          } else {
            this.showError("An unexpected error occurred. Please try again.");
          }
        }
      } catch (error2) {
        console.error("Payment processing error:", error2);
        this.showError("Payment processing failed. Please try again.");
      } finally {
        this.hideLoading();
      }
    }
  };
  __publicField(stripe_order_controller_default, "values", {
    publishableKey: String,
    clientSecret: String
  });
  __publicField(stripe_order_controller_default, "targets", ["errorMessage", "loading"]);

  // resources/build/js/app.js
  window.Stimulus = Application.start();
  Stimulus.register("buy-now", buy_now_controller_default);
  Stimulus.register("stripe-order", stripe_order_controller_default);
  if (true) {
    Stimulus.debug = true;
    console.log("Stripe Module: Stimulus initialized with controllers:", Stimulus.router.modulesByIdentifier);
  }
  console.log("Stripe Module: JavaScript loaded and ready");
})();
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsiLi4vLi4vbm9kZV9tb2R1bGVzL0Bob3R3aXJlZC9zdGltdWx1cy9kaXN0L3N0aW11bHVzLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9jb250cm9sbGVycy9idXlfbm93X2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL2NvbnRyb2xsZXJzL3N0cmlwZV9vcmRlcl9jb250cm9sbGVyLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9hcHAuanMiXSwKICAic291cmNlc0NvbnRlbnQiOiBbIi8qXG5TdGltdWx1cyAzLjIuMVxuQ29weXJpZ2h0IFx1MDBBOSAyMDIzIEJhc2VjYW1wLCBMTENcbiAqL1xuY2xhc3MgRXZlbnRMaXN0ZW5lciB7XG4gICAgY29uc3RydWN0b3IoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKSB7XG4gICAgICAgIHRoaXMuZXZlbnRUYXJnZXQgPSBldmVudFRhcmdldDtcbiAgICAgICAgdGhpcy5ldmVudE5hbWUgPSBldmVudE5hbWU7XG4gICAgICAgIHRoaXMuZXZlbnRPcHRpb25zID0gZXZlbnRPcHRpb25zO1xuICAgICAgICB0aGlzLnVub3JkZXJlZEJpbmRpbmdzID0gbmV3IFNldCgpO1xuICAgIH1cbiAgICBjb25uZWN0KCkge1xuICAgICAgICB0aGlzLmV2ZW50VGFyZ2V0LmFkZEV2ZW50TGlzdGVuZXIodGhpcy5ldmVudE5hbWUsIHRoaXMsIHRoaXMuZXZlbnRPcHRpb25zKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldC5yZW1vdmVFdmVudExpc3RlbmVyKHRoaXMuZXZlbnROYW1lLCB0aGlzLCB0aGlzLmV2ZW50T3B0aW9ucyk7XG4gICAgfVxuICAgIGJpbmRpbmdDb25uZWN0ZWQoYmluZGluZykge1xuICAgICAgICB0aGlzLnVub3JkZXJlZEJpbmRpbmdzLmFkZChiaW5kaW5nKTtcbiAgICB9XG4gICAgYmluZGluZ0Rpc2Nvbm5lY3RlZChiaW5kaW5nKSB7XG4gICAgICAgIHRoaXMudW5vcmRlcmVkQmluZGluZ3MuZGVsZXRlKGJpbmRpbmcpO1xuICAgIH1cbiAgICBoYW5kbGVFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBleHRlbmRlZEV2ZW50ID0gZXh0ZW5kRXZlbnQoZXZlbnQpO1xuICAgICAgICBmb3IgKGNvbnN0IGJpbmRpbmcgb2YgdGhpcy5iaW5kaW5ncykge1xuICAgICAgICAgICAgaWYgKGV4dGVuZGVkRXZlbnQuaW1tZWRpYXRlUHJvcGFnYXRpb25TdG9wcGVkKSB7XG4gICAgICAgICAgICAgICAgYnJlYWs7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICBiaW5kaW5nLmhhbmRsZUV2ZW50KGV4dGVuZGVkRXZlbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGhhc0JpbmRpbmdzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy51bm9yZGVyZWRCaW5kaW5ncy5zaXplID4gMDtcbiAgICB9XG4gICAgZ2V0IGJpbmRpbmdzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnVub3JkZXJlZEJpbmRpbmdzKS5zb3J0KChsZWZ0LCByaWdodCkgPT4ge1xuICAgICAgICAgICAgY29uc3QgbGVmdEluZGV4ID0gbGVmdC5pbmRleCwgcmlnaHRJbmRleCA9IHJpZ2h0LmluZGV4O1xuICAgICAgICAgICAgcmV0dXJuIGxlZnRJbmRleCA8IHJpZ2h0SW5kZXggPyAtMSA6IGxlZnRJbmRleCA+IHJpZ2h0SW5kZXggPyAxIDogMDtcbiAgICAgICAgfSk7XG4gICAgfVxufVxuZnVuY3Rpb24gZXh0ZW5kRXZlbnQoZXZlbnQpIHtcbiAgICBpZiAoXCJpbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWRcIiBpbiBldmVudCkge1xuICAgICAgICByZXR1cm4gZXZlbnQ7XG4gICAgfVxuICAgIGVsc2Uge1xuICAgICAgICBjb25zdCB7IHN0b3BJbW1lZGlhdGVQcm9wYWdhdGlvbiB9ID0gZXZlbnQ7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKGV2ZW50LCB7XG4gICAgICAgICAgICBpbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWQ6IGZhbHNlLFxuICAgICAgICAgICAgc3RvcEltbWVkaWF0ZVByb3BhZ2F0aW9uKCkge1xuICAgICAgICAgICAgICAgIHRoaXMuaW1tZWRpYXRlUHJvcGFnYXRpb25TdG9wcGVkID0gdHJ1ZTtcbiAgICAgICAgICAgICAgICBzdG9wSW1tZWRpYXRlUHJvcGFnYXRpb24uY2FsbCh0aGlzKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0pO1xuICAgIH1cbn1cblxuY2xhc3MgRGlzcGF0Y2hlciB7XG4gICAgY29uc3RydWN0b3IoYXBwbGljYXRpb24pIHtcbiAgICAgICAgdGhpcy5hcHBsaWNhdGlvbiA9IGFwcGxpY2F0aW9uO1xuICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJNYXBzID0gbmV3IE1hcCgpO1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVycy5mb3JFYWNoKChldmVudExpc3RlbmVyKSA9PiBldmVudExpc3RlbmVyLmNvbm5lY3QoKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJzLmZvckVhY2goKGV2ZW50TGlzdGVuZXIpID0+IGV2ZW50TGlzdGVuZXIuZGlzY29ubmVjdCgpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgZXZlbnRMaXN0ZW5lcnMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMudmFsdWVzKCkpLnJlZHVjZSgobGlzdGVuZXJzLCBtYXApID0+IGxpc3RlbmVycy5jb25jYXQoQXJyYXkuZnJvbShtYXAudmFsdWVzKCkpKSwgW10pO1xuICAgIH1cbiAgICBiaW5kaW5nQ29ubmVjdGVkKGJpbmRpbmcpIHtcbiAgICAgICAgdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJGb3JCaW5kaW5nKGJpbmRpbmcpLmJpbmRpbmdDb25uZWN0ZWQoYmluZGluZyk7XG4gICAgfVxuICAgIGJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZywgY2xlYXJFdmVudExpc3RlbmVycyA9IGZhbHNlKSB7XG4gICAgICAgIHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKS5iaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcpO1xuICAgICAgICBpZiAoY2xlYXJFdmVudExpc3RlbmVycylcbiAgICAgICAgICAgIHRoaXMuY2xlYXJFdmVudExpc3RlbmVyc0ZvckJpbmRpbmcoYmluZGluZyk7XG4gICAgfVxuICAgIGhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwgPSB7fSkge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBgRXJyb3IgJHttZXNzYWdlfWAsIGRldGFpbCk7XG4gICAgfVxuICAgIGNsZWFyRXZlbnRMaXN0ZW5lcnNGb3JCaW5kaW5nKGJpbmRpbmcpIHtcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lciA9IHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKTtcbiAgICAgICAgaWYgKCFldmVudExpc3RlbmVyLmhhc0JpbmRpbmdzKCkpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5yZW1vdmVNYXBwZWRFdmVudExpc3RlbmVyRm9yKGJpbmRpbmcpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlbW92ZU1hcHBlZEV2ZW50TGlzdGVuZXJGb3IoYmluZGluZykge1xuICAgICAgICBjb25zdCB7IGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyB9ID0gYmluZGluZztcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lck1hcCA9IHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyTWFwRm9yRXZlbnRUYXJnZXQoZXZlbnRUYXJnZXQpO1xuICAgICAgICBjb25zdCBjYWNoZUtleSA9IHRoaXMuY2FjaGVLZXkoZXZlbnROYW1lLCBldmVudE9wdGlvbnMpO1xuICAgICAgICBldmVudExpc3RlbmVyTWFwLmRlbGV0ZShjYWNoZUtleSk7XG4gICAgICAgIGlmIChldmVudExpc3RlbmVyTWFwLnNpemUgPT0gMClcbiAgICAgICAgICAgIHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMuZGVsZXRlKGV2ZW50VGFyZ2V0KTtcbiAgICB9XG4gICAgZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKSB7XG4gICAgICAgIGNvbnN0IHsgZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zIH0gPSBiaW5kaW5nO1xuICAgICAgICByZXR1cm4gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICB9XG4gICAgZmV0Y2hFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICBjb25zdCBldmVudExpc3RlbmVyTWFwID0gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJNYXBGb3JFdmVudFRhcmdldChldmVudFRhcmdldCk7XG4gICAgICAgIGNvbnN0IGNhY2hlS2V5ID0gdGhpcy5jYWNoZUtleShldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgIGxldCBldmVudExpc3RlbmVyID0gZXZlbnRMaXN0ZW5lck1hcC5nZXQoY2FjaGVLZXkpO1xuICAgICAgICBpZiAoIWV2ZW50TGlzdGVuZXIpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIgPSB0aGlzLmNyZWF0ZUV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXJNYXAuc2V0KGNhY2hlS2V5LCBldmVudExpc3RlbmVyKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZXZlbnRMaXN0ZW5lcjtcbiAgICB9XG4gICAgY3JlYXRlRXZlbnRMaXN0ZW5lcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpIHtcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lciA9IG5ldyBFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIuY29ubmVjdCgpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudExpc3RlbmVyO1xuICAgIH1cbiAgICBmZXRjaEV2ZW50TGlzdGVuZXJNYXBGb3JFdmVudFRhcmdldChldmVudFRhcmdldCkge1xuICAgICAgICBsZXQgZXZlbnRMaXN0ZW5lck1hcCA9IHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMuZ2V0KGV2ZW50VGFyZ2V0KTtcbiAgICAgICAgaWYgKCFldmVudExpc3RlbmVyTWFwKSB7XG4gICAgICAgICAgICBldmVudExpc3RlbmVyTWFwID0gbmV3IE1hcCgpO1xuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVyTWFwcy5zZXQoZXZlbnRUYXJnZXQsIGV2ZW50TGlzdGVuZXJNYXApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudExpc3RlbmVyTWFwO1xuICAgIH1cbiAgICBjYWNoZUtleShldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICBjb25zdCBwYXJ0cyA9IFtldmVudE5hbWVdO1xuICAgICAgICBPYmplY3Qua2V5cyhldmVudE9wdGlvbnMpXG4gICAgICAgICAgICAuc29ydCgpXG4gICAgICAgICAgICAuZm9yRWFjaCgoa2V5KSA9PiB7XG4gICAgICAgICAgICBwYXJ0cy5wdXNoKGAke2V2ZW50T3B0aW9uc1trZXldID8gXCJcIiA6IFwiIVwifSR7a2V5fWApO1xuICAgICAgICB9KTtcbiAgICAgICAgcmV0dXJuIHBhcnRzLmpvaW4oXCI6XCIpO1xuICAgIH1cbn1cblxuY29uc3QgZGVmYXVsdEFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzID0ge1xuICAgIHN0b3AoeyBldmVudCwgdmFsdWUgfSkge1xuICAgICAgICBpZiAodmFsdWUpXG4gICAgICAgICAgICBldmVudC5zdG9wUHJvcGFnYXRpb24oKTtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfSxcbiAgICBwcmV2ZW50KHsgZXZlbnQsIHZhbHVlIH0pIHtcbiAgICAgICAgaWYgKHZhbHVlKVxuICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfSxcbiAgICBzZWxmKHsgZXZlbnQsIHZhbHVlLCBlbGVtZW50IH0pIHtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICByZXR1cm4gZWxlbWVudCA9PT0gZXZlbnQudGFyZ2V0O1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICB9LFxufTtcbmNvbnN0IGRlc2NyaXB0b3JQYXR0ZXJuID0gL14oPzooPzooW14uXSs/KVxcKyk/KC4rPykoPzpcXC4oLis/KSk/KD86QCh3aW5kb3d8ZG9jdW1lbnQpKT8tPik/KC4rPykoPzojKFteOl0rPykpKD86OiguKykpPyQvO1xuZnVuY3Rpb24gcGFyc2VBY3Rpb25EZXNjcmlwdG9yU3RyaW5nKGRlc2NyaXB0b3JTdHJpbmcpIHtcbiAgICBjb25zdCBzb3VyY2UgPSBkZXNjcmlwdG9yU3RyaW5nLnRyaW0oKTtcbiAgICBjb25zdCBtYXRjaGVzID0gc291cmNlLm1hdGNoKGRlc2NyaXB0b3JQYXR0ZXJuKSB8fCBbXTtcbiAgICBsZXQgZXZlbnROYW1lID0gbWF0Y2hlc1syXTtcbiAgICBsZXQga2V5RmlsdGVyID0gbWF0Y2hlc1szXTtcbiAgICBpZiAoa2V5RmlsdGVyICYmICFbXCJrZXlkb3duXCIsIFwia2V5dXBcIiwgXCJrZXlwcmVzc1wiXS5pbmNsdWRlcyhldmVudE5hbWUpKSB7XG4gICAgICAgIGV2ZW50TmFtZSArPSBgLiR7a2V5RmlsdGVyfWA7XG4gICAgICAgIGtleUZpbHRlciA9IFwiXCI7XG4gICAgfVxuICAgIHJldHVybiB7XG4gICAgICAgIGV2ZW50VGFyZ2V0OiBwYXJzZUV2ZW50VGFyZ2V0KG1hdGNoZXNbNF0pLFxuICAgICAgICBldmVudE5hbWUsXG4gICAgICAgIGV2ZW50T3B0aW9uczogbWF0Y2hlc1s3XSA/IHBhcnNlRXZlbnRPcHRpb25zKG1hdGNoZXNbN10pIDoge30sXG4gICAgICAgIGlkZW50aWZpZXI6IG1hdGNoZXNbNV0sXG4gICAgICAgIG1ldGhvZE5hbWU6IG1hdGNoZXNbNl0sXG4gICAgICAgIGtleUZpbHRlcjogbWF0Y2hlc1sxXSB8fCBrZXlGaWx0ZXIsXG4gICAgfTtcbn1cbmZ1bmN0aW9uIHBhcnNlRXZlbnRUYXJnZXQoZXZlbnRUYXJnZXROYW1lKSB7XG4gICAgaWYgKGV2ZW50VGFyZ2V0TmFtZSA9PSBcIndpbmRvd1wiKSB7XG4gICAgICAgIHJldHVybiB3aW5kb3c7XG4gICAgfVxuICAgIGVsc2UgaWYgKGV2ZW50VGFyZ2V0TmFtZSA9PSBcImRvY3VtZW50XCIpIHtcbiAgICAgICAgcmV0dXJuIGRvY3VtZW50O1xuICAgIH1cbn1cbmZ1bmN0aW9uIHBhcnNlRXZlbnRPcHRpb25zKGV2ZW50T3B0aW9ucykge1xuICAgIHJldHVybiBldmVudE9wdGlvbnNcbiAgICAgICAgLnNwbGl0KFwiOlwiKVxuICAgICAgICAucmVkdWNlKChvcHRpb25zLCB0b2tlbikgPT4gT2JqZWN0LmFzc2lnbihvcHRpb25zLCB7IFt0b2tlbi5yZXBsYWNlKC9eIS8sIFwiXCIpXTogIS9eIS8udGVzdCh0b2tlbikgfSksIHt9KTtcbn1cbmZ1bmN0aW9uIHN0cmluZ2lmeUV2ZW50VGFyZ2V0KGV2ZW50VGFyZ2V0KSB7XG4gICAgaWYgKGV2ZW50VGFyZ2V0ID09IHdpbmRvdykge1xuICAgICAgICByZXR1cm4gXCJ3aW5kb3dcIjtcbiAgICB9XG4gICAgZWxzZSBpZiAoZXZlbnRUYXJnZXQgPT0gZG9jdW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIFwiZG9jdW1lbnRcIjtcbiAgICB9XG59XG5cbmZ1bmN0aW9uIGNhbWVsaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLnJlcGxhY2UoLyg/OltfLV0pKFthLXowLTldKS9nLCAoXywgY2hhcikgPT4gY2hhci50b1VwcGVyQ2FzZSgpKTtcbn1cbmZ1bmN0aW9uIG5hbWVzcGFjZUNhbWVsaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIGNhbWVsaXplKHZhbHVlLnJlcGxhY2UoLy0tL2csIFwiLVwiKS5yZXBsYWNlKC9fXy9nLCBcIl9cIikpO1xufVxuZnVuY3Rpb24gY2FwaXRhbGl6ZSh2YWx1ZSkge1xuICAgIHJldHVybiB2YWx1ZS5jaGFyQXQoMCkudG9VcHBlckNhc2UoKSArIHZhbHVlLnNsaWNlKDEpO1xufVxuZnVuY3Rpb24gZGFzaGVyaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLnJlcGxhY2UoLyhbQS1aXSkvZywgKF8sIGNoYXIpID0+IGAtJHtjaGFyLnRvTG93ZXJDYXNlKCl9YCk7XG59XG5mdW5jdGlvbiB0b2tlbml6ZSh2YWx1ZSkge1xuICAgIHJldHVybiB2YWx1ZS5tYXRjaCgvW15cXHNdKy9nKSB8fCBbXTtcbn1cblxuZnVuY3Rpb24gaXNTb21ldGhpbmcob2JqZWN0KSB7XG4gICAgcmV0dXJuIG9iamVjdCAhPT0gbnVsbCAmJiBvYmplY3QgIT09IHVuZGVmaW5lZDtcbn1cbmZ1bmN0aW9uIGhhc1Byb3BlcnR5KG9iamVjdCwgcHJvcGVydHkpIHtcbiAgICByZXR1cm4gT2JqZWN0LnByb3RvdHlwZS5oYXNPd25Qcm9wZXJ0eS5jYWxsKG9iamVjdCwgcHJvcGVydHkpO1xufVxuXG5jb25zdCBhbGxNb2RpZmllcnMgPSBbXCJtZXRhXCIsIFwiY3RybFwiLCBcImFsdFwiLCBcInNoaWZ0XCJdO1xuY2xhc3MgQWN0aW9uIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBpbmRleCwgZGVzY3JpcHRvciwgc2NoZW1hKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuaW5kZXggPSBpbmRleDtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldCA9IGRlc2NyaXB0b3IuZXZlbnRUYXJnZXQgfHwgZWxlbWVudDtcbiAgICAgICAgdGhpcy5ldmVudE5hbWUgPSBkZXNjcmlwdG9yLmV2ZW50TmFtZSB8fCBnZXREZWZhdWx0RXZlbnROYW1lRm9yRWxlbWVudChlbGVtZW50KSB8fCBlcnJvcihcIm1pc3NpbmcgZXZlbnQgbmFtZVwiKTtcbiAgICAgICAgdGhpcy5ldmVudE9wdGlvbnMgPSBkZXNjcmlwdG9yLmV2ZW50T3B0aW9ucyB8fCB7fTtcbiAgICAgICAgdGhpcy5pZGVudGlmaWVyID0gZGVzY3JpcHRvci5pZGVudGlmaWVyIHx8IGVycm9yKFwibWlzc2luZyBpZGVudGlmaWVyXCIpO1xuICAgICAgICB0aGlzLm1ldGhvZE5hbWUgPSBkZXNjcmlwdG9yLm1ldGhvZE5hbWUgfHwgZXJyb3IoXCJtaXNzaW5nIG1ldGhvZCBuYW1lXCIpO1xuICAgICAgICB0aGlzLmtleUZpbHRlciA9IGRlc2NyaXB0b3Iua2V5RmlsdGVyIHx8IFwiXCI7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgIH1cbiAgICBzdGF0aWMgZm9yVG9rZW4odG9rZW4sIHNjaGVtYSkge1xuICAgICAgICByZXR1cm4gbmV3IHRoaXModG9rZW4uZWxlbWVudCwgdG9rZW4uaW5kZXgsIHBhcnNlQWN0aW9uRGVzY3JpcHRvclN0cmluZyh0b2tlbi5jb250ZW50KSwgc2NoZW1hKTtcbiAgICB9XG4gICAgdG9TdHJpbmcoKSB7XG4gICAgICAgIGNvbnN0IGV2ZW50RmlsdGVyID0gdGhpcy5rZXlGaWx0ZXIgPyBgLiR7dGhpcy5rZXlGaWx0ZXJ9YCA6IFwiXCI7XG4gICAgICAgIGNvbnN0IGV2ZW50VGFyZ2V0ID0gdGhpcy5ldmVudFRhcmdldE5hbWUgPyBgQCR7dGhpcy5ldmVudFRhcmdldE5hbWV9YCA6IFwiXCI7XG4gICAgICAgIHJldHVybiBgJHt0aGlzLmV2ZW50TmFtZX0ke2V2ZW50RmlsdGVyfSR7ZXZlbnRUYXJnZXR9LT4ke3RoaXMuaWRlbnRpZmllcn0jJHt0aGlzLm1ldGhvZE5hbWV9YDtcbiAgICB9XG4gICAgc2hvdWxkSWdub3JlS2V5Ym9hcmRFdmVudChldmVudCkge1xuICAgICAgICBpZiAoIXRoaXMua2V5RmlsdGVyKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgY29uc3QgZmlsdGVycyA9IHRoaXMua2V5RmlsdGVyLnNwbGl0KFwiK1wiKTtcbiAgICAgICAgaWYgKHRoaXMua2V5RmlsdGVyRGlzc2F0aXNmaWVkKGV2ZW50LCBmaWx0ZXJzKSkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgY29uc3Qgc3RhbmRhcmRGaWx0ZXIgPSBmaWx0ZXJzLmZpbHRlcigoa2V5KSA9PiAhYWxsTW9kaWZpZXJzLmluY2x1ZGVzKGtleSkpWzBdO1xuICAgICAgICBpZiAoIXN0YW5kYXJkRmlsdGVyKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKCFoYXNQcm9wZXJ0eSh0aGlzLmtleU1hcHBpbmdzLCBzdGFuZGFyZEZpbHRlcikpIHtcbiAgICAgICAgICAgIGVycm9yKGBjb250YWlucyB1bmtub3duIGtleSBmaWx0ZXI6ICR7dGhpcy5rZXlGaWx0ZXJ9YCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHRoaXMua2V5TWFwcGluZ3Nbc3RhbmRhcmRGaWx0ZXJdLnRvTG93ZXJDYXNlKCkgIT09IGV2ZW50LmtleS50b0xvd2VyQ2FzZSgpO1xuICAgIH1cbiAgICBzaG91bGRJZ25vcmVNb3VzZUV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGlmICghdGhpcy5rZXlGaWx0ZXIpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBjb25zdCBmaWx0ZXJzID0gW3RoaXMua2V5RmlsdGVyXTtcbiAgICAgICAgaWYgKHRoaXMua2V5RmlsdGVyRGlzc2F0aXNmaWVkKGV2ZW50LCBmaWx0ZXJzKSkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgIH1cbiAgICBnZXQgcGFyYW1zKCkge1xuICAgICAgICBjb25zdCBwYXJhbXMgPSB7fTtcbiAgICAgICAgY29uc3QgcGF0dGVybiA9IG5ldyBSZWdFeHAoYF5kYXRhLSR7dGhpcy5pZGVudGlmaWVyfS0oLispLXBhcmFtJGAsIFwiaVwiKTtcbiAgICAgICAgZm9yIChjb25zdCB7IG5hbWUsIHZhbHVlIH0gb2YgQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnQuYXR0cmlidXRlcykpIHtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoID0gbmFtZS5tYXRjaChwYXR0ZXJuKTtcbiAgICAgICAgICAgIGNvbnN0IGtleSA9IG1hdGNoICYmIG1hdGNoWzFdO1xuICAgICAgICAgICAgaWYgKGtleSkge1xuICAgICAgICAgICAgICAgIHBhcmFtc1tjYW1lbGl6ZShrZXkpXSA9IHR5cGVjYXN0KHZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFyYW1zO1xuICAgIH1cbiAgICBnZXQgZXZlbnRUYXJnZXROYW1lKCkge1xuICAgICAgICByZXR1cm4gc3RyaW5naWZ5RXZlbnRUYXJnZXQodGhpcy5ldmVudFRhcmdldCk7XG4gICAgfVxuICAgIGdldCBrZXlNYXBwaW5ncygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmtleU1hcHBpbmdzO1xuICAgIH1cbiAgICBrZXlGaWx0ZXJEaXNzYXRpc2ZpZWQoZXZlbnQsIGZpbHRlcnMpIHtcbiAgICAgICAgY29uc3QgW21ldGEsIGN0cmwsIGFsdCwgc2hpZnRdID0gYWxsTW9kaWZpZXJzLm1hcCgobW9kaWZpZXIpID0+IGZpbHRlcnMuaW5jbHVkZXMobW9kaWZpZXIpKTtcbiAgICAgICAgcmV0dXJuIGV2ZW50Lm1ldGFLZXkgIT09IG1ldGEgfHwgZXZlbnQuY3RybEtleSAhPT0gY3RybCB8fCBldmVudC5hbHRLZXkgIT09IGFsdCB8fCBldmVudC5zaGlmdEtleSAhPT0gc2hpZnQ7XG4gICAgfVxufVxuY29uc3QgZGVmYXVsdEV2ZW50TmFtZXMgPSB7XG4gICAgYTogKCkgPT4gXCJjbGlja1wiLFxuICAgIGJ1dHRvbjogKCkgPT4gXCJjbGlja1wiLFxuICAgIGZvcm06ICgpID0+IFwic3VibWl0XCIsXG4gICAgZGV0YWlsczogKCkgPT4gXCJ0b2dnbGVcIixcbiAgICBpbnB1dDogKGUpID0+IChlLmdldEF0dHJpYnV0ZShcInR5cGVcIikgPT0gXCJzdWJtaXRcIiA/IFwiY2xpY2tcIiA6IFwiaW5wdXRcIiksXG4gICAgc2VsZWN0OiAoKSA9PiBcImNoYW5nZVwiLFxuICAgIHRleHRhcmVhOiAoKSA9PiBcImlucHV0XCIsXG59O1xuZnVuY3Rpb24gZ2V0RGVmYXVsdEV2ZW50TmFtZUZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgIGNvbnN0IHRhZ05hbWUgPSBlbGVtZW50LnRhZ05hbWUudG9Mb3dlckNhc2UoKTtcbiAgICBpZiAodGFnTmFtZSBpbiBkZWZhdWx0RXZlbnROYW1lcykge1xuICAgICAgICByZXR1cm4gZGVmYXVsdEV2ZW50TmFtZXNbdGFnTmFtZV0oZWxlbWVudCk7XG4gICAgfVxufVxuZnVuY3Rpb24gZXJyb3IobWVzc2FnZSkge1xuICAgIHRocm93IG5ldyBFcnJvcihtZXNzYWdlKTtcbn1cbmZ1bmN0aW9uIHR5cGVjYXN0KHZhbHVlKSB7XG4gICAgdHJ5IHtcbiAgICAgICAgcmV0dXJuIEpTT04ucGFyc2UodmFsdWUpO1xuICAgIH1cbiAgICBjYXRjaCAob19PKSB7XG4gICAgICAgIHJldHVybiB2YWx1ZTtcbiAgICB9XG59XG5cbmNsYXNzIEJpbmRpbmcge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmFjdGlvbiA9IGFjdGlvbjtcbiAgICB9XG4gICAgZ2V0IGluZGV4KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uaW5kZXg7XG4gICAgfVxuICAgIGdldCBldmVudFRhcmdldCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYWN0aW9uLmV2ZW50VGFyZ2V0O1xuICAgIH1cbiAgICBnZXQgZXZlbnRPcHRpb25zKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uZXZlbnRPcHRpb25zO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBoYW5kbGVFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBhY3Rpb25FdmVudCA9IHRoaXMucHJlcGFyZUFjdGlvbkV2ZW50KGV2ZW50KTtcbiAgICAgICAgaWYgKHRoaXMud2lsbEJlSW52b2tlZEJ5RXZlbnQoZXZlbnQpICYmIHRoaXMuYXBwbHlFdmVudE1vZGlmaWVycyhhY3Rpb25FdmVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuaW52b2tlV2l0aEV2ZW50KGFjdGlvbkV2ZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgZXZlbnROYW1lKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uZXZlbnROYW1lO1xuICAgIH1cbiAgICBnZXQgbWV0aG9kKCkge1xuICAgICAgICBjb25zdCBtZXRob2QgPSB0aGlzLmNvbnRyb2xsZXJbdGhpcy5tZXRob2ROYW1lXTtcbiAgICAgICAgaWYgKHR5cGVvZiBtZXRob2QgPT0gXCJmdW5jdGlvblwiKSB7XG4gICAgICAgICAgICByZXR1cm4gbWV0aG9kO1xuICAgICAgICB9XG4gICAgICAgIHRocm93IG5ldyBFcnJvcihgQWN0aW9uIFwiJHt0aGlzLmFjdGlvbn1cIiByZWZlcmVuY2VzIHVuZGVmaW5lZCBtZXRob2QgXCIke3RoaXMubWV0aG9kTmFtZX1cImApO1xuICAgIH1cbiAgICBhcHBseUV2ZW50TW9kaWZpZXJzKGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdGhpcy5hY3Rpb247XG4gICAgICAgIGNvbnN0IHsgYWN0aW9uRGVzY3JpcHRvckZpbHRlcnMgfSA9IHRoaXMuY29udGV4dC5hcHBsaWNhdGlvbjtcbiAgICAgICAgY29uc3QgeyBjb250cm9sbGVyIH0gPSB0aGlzLmNvbnRleHQ7XG4gICAgICAgIGxldCBwYXNzZXMgPSB0cnVlO1xuICAgICAgICBmb3IgKGNvbnN0IFtuYW1lLCB2YWx1ZV0gb2YgT2JqZWN0LmVudHJpZXModGhpcy5ldmVudE9wdGlvbnMpKSB7XG4gICAgICAgICAgICBpZiAobmFtZSBpbiBhY3Rpb25EZXNjcmlwdG9yRmlsdGVycykge1xuICAgICAgICAgICAgICAgIGNvbnN0IGZpbHRlciA9IGFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzW25hbWVdO1xuICAgICAgICAgICAgICAgIHBhc3NlcyA9IHBhc3NlcyAmJiBmaWx0ZXIoeyBuYW1lLCB2YWx1ZSwgZXZlbnQsIGVsZW1lbnQsIGNvbnRyb2xsZXIgfSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICBjb250aW51ZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFzc2VzO1xuICAgIH1cbiAgICBwcmVwYXJlQWN0aW9uRXZlbnQoZXZlbnQpIHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24oZXZlbnQsIHsgcGFyYW1zOiB0aGlzLmFjdGlvbi5wYXJhbXMgfSk7XG4gICAgfVxuICAgIGludm9rZVdpdGhFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCB7IHRhcmdldCwgY3VycmVudFRhcmdldCB9ID0gZXZlbnQ7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLm1ldGhvZC5jYWxsKHRoaXMuY29udHJvbGxlciwgZXZlbnQpO1xuICAgICAgICAgICAgdGhpcy5jb250ZXh0LmxvZ0RlYnVnQWN0aXZpdHkodGhpcy5tZXRob2ROYW1lLCB7IGV2ZW50LCB0YXJnZXQsIGN1cnJlbnRUYXJnZXQsIGFjdGlvbjogdGhpcy5tZXRob2ROYW1lIH0pO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50LCBpbmRleCB9ID0gdGhpcztcbiAgICAgICAgICAgIGNvbnN0IGRldGFpbCA9IHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCwgaW5kZXgsIGV2ZW50IH07XG4gICAgICAgICAgICB0aGlzLmNvbnRleHQuaGFuZGxlRXJyb3IoZXJyb3IsIGBpbnZva2luZyBhY3Rpb24gXCIke3RoaXMuYWN0aW9ufVwiYCwgZGV0YWlsKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICB3aWxsQmVJbnZva2VkQnlFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBldmVudFRhcmdldCA9IGV2ZW50LnRhcmdldDtcbiAgICAgICAgaWYgKGV2ZW50IGluc3RhbmNlb2YgS2V5Ym9hcmRFdmVudCAmJiB0aGlzLmFjdGlvbi5zaG91bGRJZ25vcmVLZXlib2FyZEV2ZW50KGV2ZW50KSkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmIChldmVudCBpbnN0YW5jZW9mIE1vdXNlRXZlbnQgJiYgdGhpcy5hY3Rpb24uc2hvdWxkSWdub3JlTW91c2VFdmVudChldmVudCkpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBpZiAodGhpcy5lbGVtZW50ID09PSBldmVudFRhcmdldCkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAoZXZlbnRUYXJnZXQgaW5zdGFuY2VvZiBFbGVtZW50ICYmIHRoaXMuZWxlbWVudC5jb250YWlucyhldmVudFRhcmdldCkpIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmNvbnRhaW5zRWxlbWVudChldmVudFRhcmdldCk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5jb250YWluc0VsZW1lbnQodGhpcy5hY3Rpb24uZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuY29udHJvbGxlcjtcbiAgICB9XG4gICAgZ2V0IG1ldGhvZE5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFjdGlvbi5tZXRob2ROYW1lO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbn1cblxuY2xhc3MgRWxlbWVudE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXJJbml0ID0geyBhdHRyaWJ1dGVzOiB0cnVlLCBjaGlsZExpc3Q6IHRydWUsIHN1YnRyZWU6IHRydWUgfTtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5lbGVtZW50cyA9IG5ldyBTZXQoKTtcbiAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyID0gbmV3IE11dGF0aW9uT2JzZXJ2ZXIoKG11dGF0aW9ucykgPT4gdGhpcy5wcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IHRydWU7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIub2JzZXJ2ZSh0aGlzLmVsZW1lbnQsIHRoaXMubXV0YXRpb25PYnNlcnZlckluaXQpO1xuICAgICAgICAgICAgdGhpcy5yZWZyZXNoKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcGF1c2UoY2FsbGJhY2spIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGNhbGxiYWNrKCk7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIub2JzZXJ2ZSh0aGlzLmVsZW1lbnQsIHRoaXMubXV0YXRpb25PYnNlcnZlckluaXQpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIudGFrZVJlY29yZHMoKTtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gbmV3IFNldCh0aGlzLm1hdGNoRWxlbWVudHNJblRyZWUoKSk7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnRzKSkge1xuICAgICAgICAgICAgICAgIGlmICghbWF0Y2hlcy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICAgICAgdGhpcy5yZW1vdmVFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiBBcnJheS5mcm9tKG1hdGNoZXMpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5hZGRFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbnMobXV0YXRpb25zKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGZvciAoY29uc3QgbXV0YXRpb24gb2YgbXV0YXRpb25zKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzTXV0YXRpb24obXV0YXRpb24pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbihtdXRhdGlvbikge1xuICAgICAgICBpZiAobXV0YXRpb24udHlwZSA9PSBcImF0dHJpYnV0ZXNcIikge1xuICAgICAgICAgICAgdGhpcy5wcm9jZXNzQXR0cmlidXRlQ2hhbmdlKG11dGF0aW9uLnRhcmdldCwgbXV0YXRpb24uYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAobXV0YXRpb24udHlwZSA9PSBcImNoaWxkTGlzdFwiKSB7XG4gICAgICAgICAgICB0aGlzLnByb2Nlc3NSZW1vdmVkTm9kZXMobXV0YXRpb24ucmVtb3ZlZE5vZGVzKTtcbiAgICAgICAgICAgIHRoaXMucHJvY2Vzc0FkZGVkTm9kZXMobXV0YXRpb24uYWRkZWROb2Rlcyk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc0F0dHJpYnV0ZUNoYW5nZShlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmICh0aGlzLmVsZW1lbnRzLmhhcyhlbGVtZW50KSkge1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZUNoYW5nZWQgJiYgdGhpcy5tYXRjaEVsZW1lbnQoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgdGhpcy5yZW1vdmVFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgICAgIGVsc2UgaWYgKHRoaXMubWF0Y2hFbGVtZW50KGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLmFkZEVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc1JlbW92ZWROb2Rlcyhub2Rlcykge1xuICAgICAgICBmb3IgKGNvbnN0IG5vZGUgb2YgQXJyYXkuZnJvbShub2RlcykpIHtcbiAgICAgICAgICAgIGNvbnN0IGVsZW1lbnQgPSB0aGlzLmVsZW1lbnRGcm9tTm9kZShub2RlKTtcbiAgICAgICAgICAgIGlmIChlbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzVHJlZShlbGVtZW50LCB0aGlzLnJlbW92ZUVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NBZGRlZE5vZGVzKG5vZGVzKSB7XG4gICAgICAgIGZvciAoY29uc3Qgbm9kZSBvZiBBcnJheS5mcm9tKG5vZGVzKSkge1xuICAgICAgICAgICAgY29uc3QgZWxlbWVudCA9IHRoaXMuZWxlbWVudEZyb21Ob2RlKG5vZGUpO1xuICAgICAgICAgICAgaWYgKGVsZW1lbnQgJiYgdGhpcy5lbGVtZW50SXNBY3RpdmUoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnByb2Nlc3NUcmVlKGVsZW1lbnQsIHRoaXMuYWRkRWxlbWVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVsZWdhdGUubWF0Y2hFbGVtZW50KGVsZW1lbnQpO1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUgPSB0aGlzLmVsZW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVsZWdhdGUubWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKTtcbiAgICB9XG4gICAgcHJvY2Vzc1RyZWUodHJlZSwgcHJvY2Vzc29yKSB7XG4gICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiB0aGlzLm1hdGNoRWxlbWVudHNJblRyZWUodHJlZSkpIHtcbiAgICAgICAgICAgIHByb2Nlc3Nvci5jYWxsKHRoaXMsIGVsZW1lbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRGcm9tTm9kZShub2RlKSB7XG4gICAgICAgIGlmIChub2RlLm5vZGVUeXBlID09IE5vZGUuRUxFTUVOVF9OT0RFKSB7XG4gICAgICAgICAgICByZXR1cm4gbm9kZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50SXNBY3RpdmUoZWxlbWVudCkge1xuICAgICAgICBpZiAoZWxlbWVudC5pc0Nvbm5lY3RlZCAhPSB0aGlzLmVsZW1lbnQuaXNDb25uZWN0ZWQpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnQuY29udGFpbnMoZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgYWRkRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGlmICghdGhpcy5lbGVtZW50cy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIGlmICh0aGlzLmVsZW1lbnRJc0FjdGl2ZShlbGVtZW50KSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZWxlbWVudHMuYWRkKGVsZW1lbnQpO1xuICAgICAgICAgICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkKSB7XG4gICAgICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWQoZWxlbWVudCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHJlbW92ZUVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBpZiAodGhpcy5lbGVtZW50cy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuZWxlbWVudHMuZGVsZXRlKGVsZW1lbnQpO1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZCkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZChlbGVtZW50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbn1cblxuY2xhc3MgQXR0cmlidXRlT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlTmFtZSA9IGF0dHJpYnV0ZU5hbWU7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIgPSBuZXcgRWxlbWVudE9ic2VydmVyKGVsZW1lbnQsIHRoaXMpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudE9ic2VydmVyLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzZWxlY3RvcigpIHtcbiAgICAgICAgcmV0dXJuIGBbJHt0aGlzLmF0dHJpYnV0ZU5hbWV9XWA7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5wYXVzZShjYWxsYmFjayk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBnZXQgc3RhcnRlZCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0ZWQ7XG4gICAgfVxuICAgIG1hdGNoRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIHJldHVybiBlbGVtZW50Lmhhc0F0dHJpYnV0ZSh0aGlzLmF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUpIHtcbiAgICAgICAgY29uc3QgbWF0Y2ggPSB0aGlzLm1hdGNoRWxlbWVudCh0cmVlKSA/IFt0cmVlXSA6IFtdO1xuICAgICAgICBjb25zdCBtYXRjaGVzID0gQXJyYXkuZnJvbSh0cmVlLnF1ZXJ5U2VsZWN0b3JBbGwodGhpcy5zZWxlY3RvcikpO1xuICAgICAgICByZXR1cm4gbWF0Y2guY29uY2F0KG1hdGNoZXMpO1xuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQsIHRoaXMuYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZEF0dHJpYnV0ZShlbGVtZW50LCB0aGlzLmF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZVZhbHVlQ2hhbmdlZCAmJiB0aGlzLmF0dHJpYnV0ZU5hbWUgPT0gYXR0cmlidXRlTmFtZSkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxufVxuXG5mdW5jdGlvbiBhZGQobWFwLCBrZXksIHZhbHVlKSB7XG4gICAgZmV0Y2gobWFwLCBrZXkpLmFkZCh2YWx1ZSk7XG59XG5mdW5jdGlvbiBkZWwobWFwLCBrZXksIHZhbHVlKSB7XG4gICAgZmV0Y2gobWFwLCBrZXkpLmRlbGV0ZSh2YWx1ZSk7XG4gICAgcHJ1bmUobWFwLCBrZXkpO1xufVxuZnVuY3Rpb24gZmV0Y2gobWFwLCBrZXkpIHtcbiAgICBsZXQgdmFsdWVzID0gbWFwLmdldChrZXkpO1xuICAgIGlmICghdmFsdWVzKSB7XG4gICAgICAgIHZhbHVlcyA9IG5ldyBTZXQoKTtcbiAgICAgICAgbWFwLnNldChrZXksIHZhbHVlcyk7XG4gICAgfVxuICAgIHJldHVybiB2YWx1ZXM7XG59XG5mdW5jdGlvbiBwcnVuZShtYXAsIGtleSkge1xuICAgIGNvbnN0IHZhbHVlcyA9IG1hcC5nZXQoa2V5KTtcbiAgICBpZiAodmFsdWVzICE9IG51bGwgJiYgdmFsdWVzLnNpemUgPT0gMCkge1xuICAgICAgICBtYXAuZGVsZXRlKGtleSk7XG4gICAgfVxufVxuXG5jbGFzcyBNdWx0aW1hcCB7XG4gICAgY29uc3RydWN0b3IoKSB7XG4gICAgICAgIHRoaXMudmFsdWVzQnlLZXkgPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIGdldCBrZXlzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnZhbHVlc0J5S2V5LmtleXMoKSk7XG4gICAgfVxuICAgIGdldCB2YWx1ZXMoKSB7XG4gICAgICAgIGNvbnN0IHNldHMgPSBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkudmFsdWVzKCkpO1xuICAgICAgICByZXR1cm4gc2V0cy5yZWR1Y2UoKHZhbHVlcywgc2V0KSA9PiB2YWx1ZXMuY29uY2F0KEFycmF5LmZyb20oc2V0KSksIFtdKTtcbiAgICB9XG4gICAgZ2V0IHNpemUoKSB7XG4gICAgICAgIGNvbnN0IHNldHMgPSBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkudmFsdWVzKCkpO1xuICAgICAgICByZXR1cm4gc2V0cy5yZWR1Y2UoKHNpemUsIHNldCkgPT4gc2l6ZSArIHNldC5zaXplLCAwKTtcbiAgICB9XG4gICAgYWRkKGtleSwgdmFsdWUpIHtcbiAgICAgICAgYWRkKHRoaXMudmFsdWVzQnlLZXksIGtleSwgdmFsdWUpO1xuICAgIH1cbiAgICBkZWxldGUoa2V5LCB2YWx1ZSkge1xuICAgICAgICBkZWwodGhpcy52YWx1ZXNCeUtleSwga2V5LCB2YWx1ZSk7XG4gICAgfVxuICAgIGhhcyhrZXksIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHZhbHVlcyA9IHRoaXMudmFsdWVzQnlLZXkuZ2V0KGtleSk7XG4gICAgICAgIHJldHVybiB2YWx1ZXMgIT0gbnVsbCAmJiB2YWx1ZXMuaGFzKHZhbHVlKTtcbiAgICB9XG4gICAgaGFzS2V5KGtleSkge1xuICAgICAgICByZXR1cm4gdGhpcy52YWx1ZXNCeUtleS5oYXMoa2V5KTtcbiAgICB9XG4gICAgaGFzVmFsdWUodmFsdWUpIHtcbiAgICAgICAgY29uc3Qgc2V0cyA9IEFycmF5LmZyb20odGhpcy52YWx1ZXNCeUtleS52YWx1ZXMoKSk7XG4gICAgICAgIHJldHVybiBzZXRzLnNvbWUoKHNldCkgPT4gc2V0Lmhhcyh2YWx1ZSkpO1xuICAgIH1cbiAgICBnZXRWYWx1ZXNGb3JLZXkoa2V5KSB7XG4gICAgICAgIGNvbnN0IHZhbHVlcyA9IHRoaXMudmFsdWVzQnlLZXkuZ2V0KGtleSk7XG4gICAgICAgIHJldHVybiB2YWx1ZXMgPyBBcnJheS5mcm9tKHZhbHVlcykgOiBbXTtcbiAgICB9XG4gICAgZ2V0S2V5c0ZvclZhbHVlKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkpXG4gICAgICAgICAgICAuZmlsdGVyKChbX2tleSwgdmFsdWVzXSkgPT4gdmFsdWVzLmhhcyh2YWx1ZSkpXG4gICAgICAgICAgICAubWFwKChba2V5LCBfdmFsdWVzXSkgPT4ga2V5KTtcbiAgICB9XG59XG5cbmNsYXNzIEluZGV4ZWRNdWx0aW1hcCBleHRlbmRzIE11bHRpbWFwIHtcbiAgICBjb25zdHJ1Y3RvcigpIHtcbiAgICAgICAgc3VwZXIoKTtcbiAgICAgICAgdGhpcy5rZXlzQnlWYWx1ZSA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgZ2V0IHZhbHVlcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5rZXlzQnlWYWx1ZS5rZXlzKCkpO1xuICAgIH1cbiAgICBhZGQoa2V5LCB2YWx1ZSkge1xuICAgICAgICBzdXBlci5hZGQoa2V5LCB2YWx1ZSk7XG4gICAgICAgIGFkZCh0aGlzLmtleXNCeVZhbHVlLCB2YWx1ZSwga2V5KTtcbiAgICB9XG4gICAgZGVsZXRlKGtleSwgdmFsdWUpIHtcbiAgICAgICAgc3VwZXIuZGVsZXRlKGtleSwgdmFsdWUpO1xuICAgICAgICBkZWwodGhpcy5rZXlzQnlWYWx1ZSwgdmFsdWUsIGtleSk7XG4gICAgfVxuICAgIGhhc1ZhbHVlKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmtleXNCeVZhbHVlLmhhcyh2YWx1ZSk7XG4gICAgfVxuICAgIGdldEtleXNGb3JWYWx1ZSh2YWx1ZSkge1xuICAgICAgICBjb25zdCBzZXQgPSB0aGlzLmtleXNCeVZhbHVlLmdldCh2YWx1ZSk7XG4gICAgICAgIHJldHVybiBzZXQgPyBBcnJheS5mcm9tKHNldCkgOiBbXTtcbiAgICB9XG59XG5cbmNsYXNzIFNlbGVjdG9yT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIHNlbGVjdG9yLCBkZWxlZ2F0ZSwgZGV0YWlscykge1xuICAgICAgICB0aGlzLl9zZWxlY3RvciA9IHNlbGVjdG9yO1xuICAgICAgICB0aGlzLmRldGFpbHMgPSBkZXRhaWxzO1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlciA9IG5ldyBFbGVtZW50T2JzZXJ2ZXIoZWxlbWVudCwgdGhpcyk7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5tYXRjaGVzQnlFbGVtZW50ID0gbmV3IE11bHRpbWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RhcnRlZDtcbiAgICB9XG4gICAgZ2V0IHNlbGVjdG9yKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5fc2VsZWN0b3I7XG4gICAgfVxuICAgIHNldCBzZWxlY3RvcihzZWxlY3Rvcikge1xuICAgICAgICB0aGlzLl9zZWxlY3RvciA9IHNlbGVjdG9yO1xuICAgICAgICB0aGlzLnJlZnJlc2goKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHBhdXNlKGNhbGxiYWNrKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnBhdXNlKGNhbGxiYWNrKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgeyBzZWxlY3RvciB9ID0gdGhpcztcbiAgICAgICAgaWYgKHNlbGVjdG9yKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gZWxlbWVudC5tYXRjaGVzKHNlbGVjdG9yKTtcbiAgICAgICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLnNlbGVjdG9yTWF0Y2hFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIG1hdGNoZXMgJiYgdGhpcy5kZWxlZ2F0ZS5zZWxlY3Rvck1hdGNoRWxlbWVudChlbGVtZW50LCB0aGlzLmRldGFpbHMpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmV0dXJuIG1hdGNoZXM7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2ggPSB0aGlzLm1hdGNoRWxlbWVudCh0cmVlKSA/IFt0cmVlXSA6IFtdO1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlcyA9IEFycmF5LmZyb20odHJlZS5xdWVyeVNlbGVjdG9yQWxsKHNlbGVjdG9yKSkuZmlsdGVyKChtYXRjaCkgPT4gdGhpcy5tYXRjaEVsZW1lbnQobWF0Y2gpKTtcbiAgICAgICAgICAgIHJldHVybiBtYXRjaC5jb25jYXQobWF0Y2hlcyk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gW107XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCB7IHNlbGVjdG9yIH0gPSB0aGlzO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkKGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3JzID0gdGhpcy5tYXRjaGVzQnlFbGVtZW50LmdldEtleXNGb3JWYWx1ZShlbGVtZW50KTtcbiAgICAgICAgZm9yIChjb25zdCBzZWxlY3RvciBvZiBzZWxlY3RvcnMpIHtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIF9hdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlcyA9IHRoaXMubWF0Y2hFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlZEJlZm9yZSA9IHRoaXMubWF0Y2hlc0J5RWxlbWVudC5oYXMoc2VsZWN0b3IsIGVsZW1lbnQpO1xuICAgICAgICAgICAgaWYgKG1hdGNoZXMgJiYgIW1hdGNoZWRCZWZvcmUpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnNlbGVjdG9yTWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIGlmICghbWF0Y2hlcyAmJiBtYXRjaGVkQmVmb3JlKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUuc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yLCB0aGlzLmRldGFpbHMpO1xuICAgICAgICB0aGlzLm1hdGNoZXNCeUVsZW1lbnQuYWRkKHNlbGVjdG9yLCBlbGVtZW50KTtcbiAgICB9XG4gICAgc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpIHtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3RvciwgdGhpcy5kZXRhaWxzKTtcbiAgICAgICAgdGhpcy5tYXRjaGVzQnlFbGVtZW50LmRlbGV0ZShzZWxlY3RvciwgZWxlbWVudCk7XG4gICAgfVxufVxuXG5jbGFzcyBTdHJpbmdNYXBPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXAgPSBuZXcgTWFwKCk7XG4gICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlciA9IG5ldyBNdXRhdGlvbk9ic2VydmVyKChtdXRhdGlvbnMpID0+IHRoaXMucHJvY2Vzc011dGF0aW9ucyhtdXRhdGlvbnMpKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLm9ic2VydmUodGhpcy5lbGVtZW50LCB7IGF0dHJpYnV0ZXM6IHRydWUsIGF0dHJpYnV0ZU9sZFZhbHVlOiB0cnVlIH0pO1xuICAgICAgICAgICAgdGhpcy5yZWZyZXNoKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLnRha2VSZWNvcmRzKCk7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgZm9yIChjb25zdCBhdHRyaWJ1dGVOYW1lIG9mIHRoaXMua25vd25BdHRyaWJ1dGVOYW1lcykge1xuICAgICAgICAgICAgICAgIHRoaXMucmVmcmVzaEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lLCBudWxsKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IG11dGF0aW9uIG9mIG11dGF0aW9ucykge1xuICAgICAgICAgICAgICAgIHRoaXMucHJvY2Vzc011dGF0aW9uKG11dGF0aW9uKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzTXV0YXRpb24obXV0YXRpb24pIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IG11dGF0aW9uLmF0dHJpYnV0ZU5hbWU7XG4gICAgICAgIGlmIChhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgICAgICB0aGlzLnJlZnJlc2hBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSwgbXV0YXRpb24ub2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2hBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3Qga2V5ID0gdGhpcy5kZWxlZ2F0ZS5nZXRTdHJpbmdNYXBLZXlGb3JBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIGlmIChrZXkgIT0gbnVsbCkge1xuICAgICAgICAgICAgaWYgKCF0aGlzLnN0cmluZ01hcC5oYXMoYXR0cmlidXRlTmFtZSkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjb25zdCB2YWx1ZSA9IHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICBpZiAodGhpcy5zdHJpbmdNYXAuZ2V0KGF0dHJpYnV0ZU5hbWUpICE9IHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zdHJpbmdNYXBWYWx1ZUNoYW5nZWQodmFsdWUsIGtleSwgb2xkVmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKHZhbHVlID09IG51bGwpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvbGRWYWx1ZSA9IHRoaXMuc3RyaW5nTWFwLmdldChhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcC5kZWxldGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG9sZFZhbHVlKVxuICAgICAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcC5zZXQoYXR0cmlidXRlTmFtZSwgdmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlBZGRlZCkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlBZGRlZChrZXksIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwga2V5LCBvbGRWYWx1ZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBWYWx1ZUNoYW5nZWQpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwVmFsdWVDaGFuZ2VkKHZhbHVlLCBrZXksIG9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBLZXlSZW1vdmVkKGtleSwgYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwS2V5UmVtb3ZlZCkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlSZW1vdmVkKGtleSwgYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBrbm93bkF0dHJpYnV0ZU5hbWVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbShuZXcgU2V0KHRoaXMuY3VycmVudEF0dHJpYnV0ZU5hbWVzLmNvbmNhdCh0aGlzLnJlY29yZGVkQXR0cmlidXRlTmFtZXMpKSk7XG4gICAgfVxuICAgIGdldCBjdXJyZW50QXR0cmlidXRlTmFtZXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuZWxlbWVudC5hdHRyaWJ1dGVzKS5tYXAoKGF0dHJpYnV0ZSkgPT4gYXR0cmlidXRlLm5hbWUpO1xuICAgIH1cbiAgICBnZXQgcmVjb3JkZWRBdHRyaWJ1dGVOYW1lcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5zdHJpbmdNYXAua2V5cygpKTtcbiAgICB9XG59XG5cbmNsYXNzIFRva2VuTGlzdE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyID0gbmV3IEF0dHJpYnV0ZU9ic2VydmVyKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudG9rZW5zQnlFbGVtZW50ID0gbmV3IE11bHRpbWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLnBhdXNlKGNhbGxiYWNrKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGF0dHJpYnV0ZU5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLmF0dHJpYnV0ZU5hbWU7XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQpIHtcbiAgICAgICAgdGhpcy50b2tlbnNNYXRjaGVkKHRoaXMucmVhZFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCkpO1xuICAgIH1cbiAgICBlbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgW3VubWF0Y2hlZFRva2VucywgbWF0Y2hlZFRva2Vuc10gPSB0aGlzLnJlZnJlc2hUb2tlbnNGb3JFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICB0aGlzLnRva2Vuc1VubWF0Y2hlZCh1bm1hdGNoZWRUb2tlbnMpO1xuICAgICAgICB0aGlzLnRva2Vuc01hdGNoZWQobWF0Y2hlZFRva2Vucyk7XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUoZWxlbWVudCkge1xuICAgICAgICB0aGlzLnRva2Vuc1VubWF0Y2hlZCh0aGlzLnRva2Vuc0J5RWxlbWVudC5nZXRWYWx1ZXNGb3JLZXkoZWxlbWVudCkpO1xuICAgIH1cbiAgICB0b2tlbnNNYXRjaGVkKHRva2Vucykge1xuICAgICAgICB0b2tlbnMuZm9yRWFjaCgodG9rZW4pID0+IHRoaXMudG9rZW5NYXRjaGVkKHRva2VuKSk7XG4gICAgfVxuICAgIHRva2Vuc1VubWF0Y2hlZCh0b2tlbnMpIHtcbiAgICAgICAgdG9rZW5zLmZvckVhY2goKHRva2VuKSA9PiB0aGlzLnRva2VuVW5tYXRjaGVkKHRva2VuKSk7XG4gICAgfVxuICAgIHRva2VuTWF0Y2hlZCh0b2tlbikge1xuICAgICAgICB0aGlzLmRlbGVnYXRlLnRva2VuTWF0Y2hlZCh0b2tlbik7XG4gICAgICAgIHRoaXMudG9rZW5zQnlFbGVtZW50LmFkZCh0b2tlbi5lbGVtZW50LCB0b2tlbik7XG4gICAgfVxuICAgIHRva2VuVW5tYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUudG9rZW5Vbm1hdGNoZWQodG9rZW4pO1xuICAgICAgICB0aGlzLnRva2Vuc0J5RWxlbWVudC5kZWxldGUodG9rZW4uZWxlbWVudCwgdG9rZW4pO1xuICAgIH1cbiAgICByZWZyZXNoVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGNvbnN0IHByZXZpb3VzVG9rZW5zID0gdGhpcy50b2tlbnNCeUVsZW1lbnQuZ2V0VmFsdWVzRm9yS2V5KGVsZW1lbnQpO1xuICAgICAgICBjb25zdCBjdXJyZW50VG9rZW5zID0gdGhpcy5yZWFkVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgY29uc3QgZmlyc3REaWZmZXJpbmdJbmRleCA9IHppcChwcmV2aW91c1Rva2VucywgY3VycmVudFRva2VucykuZmluZEluZGV4KChbcHJldmlvdXNUb2tlbiwgY3VycmVudFRva2VuXSkgPT4gIXRva2Vuc0FyZUVxdWFsKHByZXZpb3VzVG9rZW4sIGN1cnJlbnRUb2tlbikpO1xuICAgICAgICBpZiAoZmlyc3REaWZmZXJpbmdJbmRleCA9PSAtMSkge1xuICAgICAgICAgICAgcmV0dXJuIFtbXSwgW11dO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIFtwcmV2aW91c1Rva2Vucy5zbGljZShmaXJzdERpZmZlcmluZ0luZGV4KSwgY3VycmVudFRva2Vucy5zbGljZShmaXJzdERpZmZlcmluZ0luZGV4KV07XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVhZFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5hdHRyaWJ1dGVOYW1lO1xuICAgICAgICBjb25zdCB0b2tlblN0cmluZyA9IGVsZW1lbnQuZ2V0QXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpIHx8IFwiXCI7XG4gICAgICAgIHJldHVybiBwYXJzZVRva2VuU3RyaW5nKHRva2VuU3RyaW5nLCBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKTtcbiAgICB9XG59XG5mdW5jdGlvbiBwYXJzZVRva2VuU3RyaW5nKHRva2VuU3RyaW5nLCBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgcmV0dXJuIHRva2VuU3RyaW5nXG4gICAgICAgIC50cmltKClcbiAgICAgICAgLnNwbGl0KC9cXHMrLylcbiAgICAgICAgLmZpbHRlcigoY29udGVudCkgPT4gY29udGVudC5sZW5ndGgpXG4gICAgICAgIC5tYXAoKGNvbnRlbnQsIGluZGV4KSA9PiAoeyBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBjb250ZW50LCBpbmRleCB9KSk7XG59XG5mdW5jdGlvbiB6aXAobGVmdCwgcmlnaHQpIHtcbiAgICBjb25zdCBsZW5ndGggPSBNYXRoLm1heChsZWZ0Lmxlbmd0aCwgcmlnaHQubGVuZ3RoKTtcbiAgICByZXR1cm4gQXJyYXkuZnJvbSh7IGxlbmd0aCB9LCAoXywgaW5kZXgpID0+IFtsZWZ0W2luZGV4XSwgcmlnaHRbaW5kZXhdXSk7XG59XG5mdW5jdGlvbiB0b2tlbnNBcmVFcXVhbChsZWZ0LCByaWdodCkge1xuICAgIHJldHVybiBsZWZ0ICYmIHJpZ2h0ICYmIGxlZnQuaW5kZXggPT0gcmlnaHQuaW5kZXggJiYgbGVmdC5jb250ZW50ID09IHJpZ2h0LmNvbnRlbnQ7XG59XG5cbmNsYXNzIFZhbHVlTGlzdE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyID0gbmV3IFRva2VuTGlzdE9ic2VydmVyKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMucGFyc2VSZXN1bHRzQnlUb2tlbiA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudCA9IG5ldyBXZWFrTWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy50b2tlbkxpc3RPYnNlcnZlci5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgYXR0cmlidXRlTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuYXR0cmlidXRlTmFtZTtcbiAgICB9XG4gICAgdG9rZW5NYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdG9rZW47XG4gICAgICAgIGNvbnN0IHsgdmFsdWUgfSA9IHRoaXMuZmV0Y2hQYXJzZVJlc3VsdEZvclRva2VuKHRva2VuKTtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICB0aGlzLmZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkuc2V0KHRva2VuLCB2YWx1ZSk7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHRva2VuVW5tYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdG9rZW47XG4gICAgICAgIGNvbnN0IHsgdmFsdWUgfSA9IHRoaXMuZmV0Y2hQYXJzZVJlc3VsdEZvclRva2VuKHRva2VuKTtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICB0aGlzLmZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkuZGVsZXRlKHRva2VuKTtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZFZhbHVlKGVsZW1lbnQsIHZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBmZXRjaFBhcnNlUmVzdWx0Rm9yVG9rZW4odG9rZW4pIHtcbiAgICAgICAgbGV0IHBhcnNlUmVzdWx0ID0gdGhpcy5wYXJzZVJlc3VsdHNCeVRva2VuLmdldCh0b2tlbik7XG4gICAgICAgIGlmICghcGFyc2VSZXN1bHQpIHtcbiAgICAgICAgICAgIHBhcnNlUmVzdWx0ID0gdGhpcy5wYXJzZVRva2VuKHRva2VuKTtcbiAgICAgICAgICAgIHRoaXMucGFyc2VSZXN1bHRzQnlUb2tlbi5zZXQodG9rZW4sIHBhcnNlUmVzdWx0KTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFyc2VSZXN1bHQ7XG4gICAgfVxuICAgIGZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBsZXQgdmFsdWVzQnlUb2tlbiA9IHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudC5nZXQoZWxlbWVudCk7XG4gICAgICAgIGlmICghdmFsdWVzQnlUb2tlbikge1xuICAgICAgICAgICAgdmFsdWVzQnlUb2tlbiA9IG5ldyBNYXAoKTtcbiAgICAgICAgICAgIHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudC5zZXQoZWxlbWVudCwgdmFsdWVzQnlUb2tlbik7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHZhbHVlc0J5VG9rZW47XG4gICAgfVxuICAgIHBhcnNlVG9rZW4odG9rZW4pIHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gdGhpcy5kZWxlZ2F0ZS5wYXJzZVZhbHVlRm9yVG9rZW4odG9rZW4pO1xuICAgICAgICAgICAgcmV0dXJuIHsgdmFsdWUgfTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHJldHVybiB7IGVycm9yIH07XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmNsYXNzIEJpbmRpbmdPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24gPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIgPSBuZXcgVmFsdWVMaXN0T2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLmFjdGlvbkF0dHJpYnV0ZSwgdGhpcyk7XG4gICAgICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICAgICAgZGVsZXRlIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXI7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBbGxBY3Rpb25zKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IGFjdGlvbkF0dHJpYnV0ZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmFjdGlvbkF0dHJpYnV0ZTtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY2hlbWE7XG4gICAgfVxuICAgIGdldCBiaW5kaW5ncygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5iaW5kaW5nc0J5QWN0aW9uLnZhbHVlcygpKTtcbiAgICB9XG4gICAgY29ubmVjdEFjdGlvbihhY3Rpb24pIHtcbiAgICAgICAgY29uc3QgYmluZGluZyA9IG5ldyBCaW5kaW5nKHRoaXMuY29udGV4dCwgYWN0aW9uKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nc0J5QWN0aW9uLnNldChhY3Rpb24sIGJpbmRpbmcpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlLmJpbmRpbmdDb25uZWN0ZWQoYmluZGluZyk7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RBY3Rpb24oYWN0aW9uKSB7XG4gICAgICAgIGNvbnN0IGJpbmRpbmcgPSB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uZ2V0KGFjdGlvbik7XG4gICAgICAgIGlmIChiaW5kaW5nKSB7XG4gICAgICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uZGVsZXRlKGFjdGlvbik7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZyk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZGlzY29ubmVjdEFsbEFjdGlvbnMoKSB7XG4gICAgICAgIHRoaXMuYmluZGluZ3MuZm9yRWFjaCgoYmluZGluZykgPT4gdGhpcy5kZWxlZ2F0ZS5iaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcsIHRydWUpKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nc0J5QWN0aW9uLmNsZWFyKCk7XG4gICAgfVxuICAgIHBhcnNlVmFsdWVGb3JUb2tlbih0b2tlbikge1xuICAgICAgICBjb25zdCBhY3Rpb24gPSBBY3Rpb24uZm9yVG9rZW4odG9rZW4sIHRoaXMuc2NoZW1hKTtcbiAgICAgICAgaWYgKGFjdGlvbi5pZGVudGlmaWVyID09IHRoaXMuaWRlbnRpZmllcikge1xuICAgICAgICAgICAgcmV0dXJuIGFjdGlvbjtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZFZhbHVlKGVsZW1lbnQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmNvbm5lY3RBY3Rpb24oYWN0aW9uKTtcbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZFZhbHVlKGVsZW1lbnQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBY3Rpb24oYWN0aW9uKTtcbiAgICB9XG59XG5cbmNsYXNzIFZhbHVlT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIHJlY2VpdmVyKSB7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMucmVjZWl2ZXIgPSByZWNlaXZlcjtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXBPYnNlcnZlciA9IG5ldyBTdHJpbmdNYXBPYnNlcnZlcih0aGlzLmVsZW1lbnQsIHRoaXMpO1xuICAgICAgICB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcCA9IHRoaXMuY29udHJvbGxlci52YWx1ZURlc2NyaXB0b3JNYXA7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLnN0cmluZ01hcE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrc0ZvckRlZmF1bHRWYWx1ZXMoKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXBPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmNvbnRyb2xsZXI7XG4gICAgfVxuICAgIGdldFN0cmluZ01hcEtleUZvckF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmIChhdHRyaWJ1dGVOYW1lIGluIHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwKSB7XG4gICAgICAgICAgICByZXR1cm4gdGhpcy52YWx1ZURlc2NyaXB0b3JNYXBbYXR0cmlidXRlTmFtZV0ubmFtZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBLZXlBZGRlZChrZXksIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwW2F0dHJpYnV0ZU5hbWVdO1xuICAgICAgICBpZiAoIXRoaXMuaGFzVmFsdWUoa2V5KSkge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2soa2V5LCBkZXNjcmlwdG9yLndyaXRlcih0aGlzLnJlY2VpdmVyW2tleV0pLCBkZXNjcmlwdG9yLndyaXRlcihkZXNjcmlwdG9yLmRlZmF1bHRWYWx1ZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwgbmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFtuYW1lXTtcbiAgICAgICAgaWYgKHZhbHVlID09PSBudWxsKVxuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICBpZiAob2xkVmFsdWUgPT09IG51bGwpIHtcbiAgICAgICAgICAgIG9sZFZhbHVlID0gZGVzY3JpcHRvci53cml0ZXIoZGVzY3JpcHRvci5kZWZhdWx0VmFsdWUpO1xuICAgICAgICB9XG4gICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKG5hbWUsIHZhbHVlLCBvbGRWYWx1ZSk7XG4gICAgfVxuICAgIHN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JOYW1lTWFwW2tleV07XG4gICAgICAgIGlmICh0aGlzLmhhc1ZhbHVlKGtleSkpIHtcbiAgICAgICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKGtleSwgZGVzY3JpcHRvci53cml0ZXIodGhpcy5yZWNlaXZlcltrZXldKSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2soa2V5LCBkZXNjcmlwdG9yLndyaXRlcihkZXNjcmlwdG9yLmRlZmF1bHRWYWx1ZSksIG9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBpbnZva2VDaGFuZ2VkQ2FsbGJhY2tzRm9yRGVmYXVsdFZhbHVlcygpIHtcbiAgICAgICAgZm9yIChjb25zdCB7IGtleSwgbmFtZSwgZGVmYXVsdFZhbHVlLCB3cml0ZXIgfSBvZiB0aGlzLnZhbHVlRGVzY3JpcHRvcnMpIHtcbiAgICAgICAgICAgIGlmIChkZWZhdWx0VmFsdWUgIT0gdW5kZWZpbmVkICYmICF0aGlzLmNvbnRyb2xsZXIuZGF0YS5oYXMoa2V5KSkge1xuICAgICAgICAgICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKG5hbWUsIHdyaXRlcihkZWZhdWx0VmFsdWUpLCB1bmRlZmluZWQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGludm9rZUNoYW5nZWRDYWxsYmFjayhuYW1lLCByYXdWYWx1ZSwgcmF3T2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3QgY2hhbmdlZE1ldGhvZE5hbWUgPSBgJHtuYW1lfUNoYW5nZWRgO1xuICAgICAgICBjb25zdCBjaGFuZ2VkTWV0aG9kID0gdGhpcy5yZWNlaXZlcltjaGFuZ2VkTWV0aG9kTmFtZV07XG4gICAgICAgIGlmICh0eXBlb2YgY2hhbmdlZE1ldGhvZCA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck5hbWVNYXBbbmFtZV07XG4gICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gZGVzY3JpcHRvci5yZWFkZXIocmF3VmFsdWUpO1xuICAgICAgICAgICAgICAgIGxldCBvbGRWYWx1ZSA9IHJhd09sZFZhbHVlO1xuICAgICAgICAgICAgICAgIGlmIChyYXdPbGRWYWx1ZSkge1xuICAgICAgICAgICAgICAgICAgICBvbGRWYWx1ZSA9IGRlc2NyaXB0b3IucmVhZGVyKHJhd09sZFZhbHVlKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgY2hhbmdlZE1ldGhvZC5jYWxsKHRoaXMucmVjZWl2ZXIsIHZhbHVlLCBvbGRWYWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgICAgICBpZiAoZXJyb3IgaW5zdGFuY2VvZiBUeXBlRXJyb3IpIHtcbiAgICAgICAgICAgICAgICAgICAgZXJyb3IubWVzc2FnZSA9IGBTdGltdWx1cyBWYWx1ZSBcIiR7dGhpcy5jb250ZXh0LmlkZW50aWZpZXJ9LiR7ZGVzY3JpcHRvci5uYW1lfVwiIC0gJHtlcnJvci5tZXNzYWdlfWA7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHRocm93IGVycm9yO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGdldCB2YWx1ZURlc2NyaXB0b3JzKCkge1xuICAgICAgICBjb25zdCB7IHZhbHVlRGVzY3JpcHRvck1hcCB9ID0gdGhpcztcbiAgICAgICAgcmV0dXJuIE9iamVjdC5rZXlzKHZhbHVlRGVzY3JpcHRvck1hcCkubWFwKChrZXkpID0+IHZhbHVlRGVzY3JpcHRvck1hcFtrZXldKTtcbiAgICB9XG4gICAgZ2V0IHZhbHVlRGVzY3JpcHRvck5hbWVNYXAoKSB7XG4gICAgICAgIGNvbnN0IGRlc2NyaXB0b3JzID0ge307XG4gICAgICAgIE9iamVjdC5rZXlzKHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwKS5mb3JFYWNoKChrZXkpID0+IHtcbiAgICAgICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcFtrZXldO1xuICAgICAgICAgICAgZGVzY3JpcHRvcnNbZGVzY3JpcHRvci5uYW1lXSA9IGRlc2NyaXB0b3I7XG4gICAgICAgIH0pO1xuICAgICAgICByZXR1cm4gZGVzY3JpcHRvcnM7XG4gICAgfVxuICAgIGhhc1ZhbHVlKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFthdHRyaWJ1dGVOYW1lXTtcbiAgICAgICAgY29uc3QgaGFzTWV0aG9kTmFtZSA9IGBoYXMke2NhcGl0YWxpemUoZGVzY3JpcHRvci5uYW1lKX1gO1xuICAgICAgICByZXR1cm4gdGhpcy5yZWNlaXZlcltoYXNNZXRob2ROYW1lXTtcbiAgICB9XG59XG5cbmNsYXNzIFRhcmdldE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3Rvcihjb250ZXh0LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudGFyZ2V0c0J5TmFtZSA9IG5ldyBNdWx0aW1hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnRva2VuTGlzdE9ic2VydmVyKSB7XG4gICAgICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyID0gbmV3IFRva2VuTGlzdE9ic2VydmVyKHRoaXMuZWxlbWVudCwgdGhpcy5hdHRyaWJ1dGVOYW1lLCB0aGlzKTtcbiAgICAgICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy50b2tlbkxpc3RPYnNlcnZlcikge1xuICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0QWxsVGFyZ2V0cygpO1xuICAgICAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgICAgICBkZWxldGUgdGhpcy50b2tlbkxpc3RPYnNlcnZlcjtcbiAgICAgICAgfVxuICAgIH1cbiAgICB0b2tlbk1hdGNoZWQoeyBlbGVtZW50LCBjb250ZW50OiBuYW1lIH0pIHtcbiAgICAgICAgaWYgKHRoaXMuc2NvcGUuY29udGFpbnNFbGVtZW50KGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLmNvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgdG9rZW5Vbm1hdGNoZWQoeyBlbGVtZW50LCBjb250ZW50OiBuYW1lIH0pIHtcbiAgICAgICAgdGhpcy5kaXNjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpO1xuICAgIH1cbiAgICBjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpIHtcbiAgICAgICAgdmFyIF9hO1xuICAgICAgICBpZiAoIXRoaXMudGFyZ2V0c0J5TmFtZS5oYXMobmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMudGFyZ2V0c0J5TmFtZS5hZGQobmFtZSwgZWxlbWVudCk7XG4gICAgICAgICAgICAoX2EgPSB0aGlzLnRva2VuTGlzdE9ic2VydmVyKSA9PT0gbnVsbCB8fCBfYSA9PT0gdm9pZCAwID8gdm9pZCAwIDogX2EucGF1c2UoKCkgPT4gdGhpcy5kZWxlZ2F0ZS50YXJnZXRDb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICh0aGlzLnRhcmdldHNCeU5hbWUuaGFzKG5hbWUsIGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLnRhcmdldHNCeU5hbWUuZGVsZXRlKG5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy50b2tlbkxpc3RPYnNlcnZlcikgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUudGFyZ2V0RGlzY29ubmVjdGVkKGVsZW1lbnQsIG5hbWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0QWxsVGFyZ2V0cygpIHtcbiAgICAgICAgZm9yIChjb25zdCBuYW1lIG9mIHRoaXMudGFyZ2V0c0J5TmFtZS5rZXlzKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgdGhpcy50YXJnZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShuYW1lKSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdFRhcmdldChlbGVtZW50LCBuYW1lKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgYXR0cmlidXRlTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIGBkYXRhLSR7dGhpcy5jb250ZXh0LmlkZW50aWZpZXJ9LXRhcmdldGA7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzY29wZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY29wZTtcbiAgICB9XG59XG5cbmZ1bmN0aW9uIHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBhbmNlc3RvcnMgPSBnZXRBbmNlc3RvcnNGb3JDb25zdHJ1Y3Rvcihjb25zdHJ1Y3Rvcik7XG4gICAgcmV0dXJuIEFycmF5LmZyb20oYW5jZXN0b3JzLnJlZHVjZSgodmFsdWVzLCBjb25zdHJ1Y3RvcikgPT4ge1xuICAgICAgICBnZXRPd25TdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgcHJvcGVydHlOYW1lKS5mb3JFYWNoKChuYW1lKSA9PiB2YWx1ZXMuYWRkKG5hbWUpKTtcbiAgICAgICAgcmV0dXJuIHZhbHVlcztcbiAgICB9LCBuZXcgU2V0KCkpKTtcbn1cbmZ1bmN0aW9uIHJlYWRJbmhlcml0YWJsZVN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBhbmNlc3RvcnMgPSBnZXRBbmNlc3RvcnNGb3JDb25zdHJ1Y3Rvcihjb25zdHJ1Y3Rvcik7XG4gICAgcmV0dXJuIGFuY2VzdG9ycy5yZWR1Y2UoKHBhaXJzLCBjb25zdHJ1Y3RvcikgPT4ge1xuICAgICAgICBwYWlycy5wdXNoKC4uLmdldE93blN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpKTtcbiAgICAgICAgcmV0dXJuIHBhaXJzO1xuICAgIH0sIFtdKTtcbn1cbmZ1bmN0aW9uIGdldEFuY2VzdG9yc0ZvckNvbnN0cnVjdG9yKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgYW5jZXN0b3JzID0gW107XG4gICAgd2hpbGUgKGNvbnN0cnVjdG9yKSB7XG4gICAgICAgIGFuY2VzdG9ycy5wdXNoKGNvbnN0cnVjdG9yKTtcbiAgICAgICAgY29uc3RydWN0b3IgPSBPYmplY3QuZ2V0UHJvdG90eXBlT2YoY29uc3RydWN0b3IpO1xuICAgIH1cbiAgICByZXR1cm4gYW5jZXN0b3JzLnJldmVyc2UoKTtcbn1cbmZ1bmN0aW9uIGdldE93blN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBkZWZpbml0aW9uID0gY29uc3RydWN0b3JbcHJvcGVydHlOYW1lXTtcbiAgICByZXR1cm4gQXJyYXkuaXNBcnJheShkZWZpbml0aW9uKSA/IGRlZmluaXRpb24gOiBbXTtcbn1cbmZ1bmN0aW9uIGdldE93blN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBkZWZpbml0aW9uID0gY29uc3RydWN0b3JbcHJvcGVydHlOYW1lXTtcbiAgICByZXR1cm4gZGVmaW5pdGlvbiA/IE9iamVjdC5rZXlzKGRlZmluaXRpb24pLm1hcCgoa2V5KSA9PiBba2V5LCBkZWZpbml0aW9uW2tleV1dKSA6IFtdO1xufVxuXG5jbGFzcyBPdXRsZXRPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5vdXRsZXRzQnlOYW1lID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUgPSBuZXcgTXVsdGltYXAoKTtcbiAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwID0gbmV3IE1hcCgpO1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwID0gbmV3IE1hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0RGVmaW5pdGlvbnMuZm9yRWFjaCgob3V0bGV0TmFtZSkgPT4ge1xuICAgICAgICAgICAgICAgIHRoaXMuc2V0dXBTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICAgICAgICAgIHRoaXMuc2V0dXBBdHRyaWJ1dGVPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgICAgIHRoaXMuZGVwZW5kZW50Q29udGV4dHMuZm9yRWFjaCgoY29udGV4dCkgPT4gY29udGV4dC5yZWZyZXNoKCkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIucmVmcmVzaCgpKTtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIucmVmcmVzaCgpKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBbGxPdXRsZXRzKCk7XG4gICAgICAgICAgICB0aGlzLnN0b3BTZWxlY3Rvck9ic2VydmVycygpO1xuICAgICAgICAgICAgdGhpcy5zdG9wQXR0cmlidXRlT2JzZXJ2ZXJzKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcFNlbGVjdG9yT2JzZXJ2ZXJzKCkge1xuICAgICAgICBpZiAodGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLnNpemUgPiAwKSB7XG4gICAgICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuZm9yRWFjaCgob2JzZXJ2ZXIpID0+IG9ic2VydmVyLnN0b3AoKSk7XG4gICAgICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuY2xlYXIoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wQXR0cmlidXRlT2JzZXJ2ZXJzKCkge1xuICAgICAgICBpZiAodGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5zaXplID4gMCkge1xuICAgICAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIuc3RvcCgpKTtcbiAgICAgICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuY2xlYXIoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzZWxlY3Rvck1hdGNoZWQoZWxlbWVudCwgX3NlbGVjdG9yLCB7IG91dGxldE5hbWUgfSkge1xuICAgICAgICBjb25zdCBvdXRsZXQgPSB0aGlzLmdldE91dGxldChlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKG91dGxldCkge1xuICAgICAgICAgICAgdGhpcy5jb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgX3NlbGVjdG9yLCB7IG91dGxldE5hbWUgfSkge1xuICAgICAgICBjb25zdCBvdXRsZXQgPSB0aGlzLmdldE91dGxldEZyb21NYXAoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIGlmIChvdXRsZXQpIHtcbiAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdE91dGxldChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHNlbGVjdG9yTWF0Y2hFbGVtZW50KGVsZW1lbnQsIHsgb3V0bGV0TmFtZSB9KSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5zZWxlY3RvcihvdXRsZXROYW1lKTtcbiAgICAgICAgY29uc3QgaGFzT3V0bGV0ID0gdGhpcy5oYXNPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IGhhc091dGxldENvbnRyb2xsZXIgPSBlbGVtZW50Lm1hdGNoZXMoYFske3RoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGV9fj0ke291dGxldE5hbWV9XWApO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIHJldHVybiBoYXNPdXRsZXQgJiYgaGFzT3V0bGV0Q29udHJvbGxlciAmJiBlbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkQXR0cmlidXRlKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBjb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICghdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5oYXMob3V0bGV0TmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0c0J5TmFtZS5hZGQob3V0bGV0TmFtZSwgb3V0bGV0KTtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuYWRkKG91dGxldE5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmdldChvdXRsZXROYW1lKSkgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUub3V0bGV0Q29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgaWYgKHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuaGFzKG91dGxldE5hbWUsIGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLm91dGxldHNCeU5hbWUuZGVsZXRlKG91dGxldE5hbWUsIG91dGxldCk7XG4gICAgICAgICAgICB0aGlzLm91dGxldEVsZW1lbnRzQnlOYW1lLmRlbGV0ZShvdXRsZXROYW1lLCBlbGVtZW50KTtcbiAgICAgICAgICAgIChfYSA9IHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcFxuICAgICAgICAgICAgICAgIC5nZXQob3V0bGV0TmFtZSkpID09PSBudWxsIHx8IF9hID09PSB2b2lkIDAgPyB2b2lkIDAgOiBfYS5wYXVzZSgoKSA9PiB0aGlzLmRlbGVnYXRlLm91dGxldERpc2Nvbm5lY3RlZChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0QWxsT3V0bGV0cygpIHtcbiAgICAgICAgZm9yIChjb25zdCBvdXRsZXROYW1lIG9mIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUua2V5cykge1xuICAgICAgICAgICAgZm9yIChjb25zdCBlbGVtZW50IG9mIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuZ2V0VmFsdWVzRm9yS2V5KG91dGxldE5hbWUpKSB7XG4gICAgICAgICAgICAgICAgZm9yIChjb25zdCBvdXRsZXQgb2YgdGhpcy5vdXRsZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShvdXRsZXROYW1lKSkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgdXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IG9ic2VydmVyID0gdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmdldChvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKG9ic2VydmVyKSB7XG4gICAgICAgICAgICBvYnNlcnZlci5zZWxlY3RvciA9IHRoaXMuc2VsZWN0b3Iob3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2V0dXBTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLnNlbGVjdG9yKG91dGxldE5hbWUpO1xuICAgICAgICBjb25zdCBzZWxlY3Rvck9ic2VydmVyID0gbmV3IFNlbGVjdG9yT2JzZXJ2ZXIoZG9jdW1lbnQuYm9keSwgc2VsZWN0b3IsIHRoaXMsIHsgb3V0bGV0TmFtZSB9KTtcbiAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLnNldChvdXRsZXROYW1lLCBzZWxlY3Rvck9ic2VydmVyKTtcbiAgICAgICAgc2VsZWN0b3JPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzZXR1cEF0dHJpYnV0ZU9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuYXR0cmlidXRlTmFtZUZvck91dGxldE5hbWUob3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU9ic2VydmVyID0gbmV3IEF0dHJpYnV0ZU9ic2VydmVyKHRoaXMuc2NvcGUuZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgdGhpcyk7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuc2V0KG91dGxldE5hbWUsIGF0dHJpYnV0ZU9ic2VydmVyKTtcbiAgICAgICAgYXR0cmlidXRlT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgc2VsZWN0b3Iob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5vdXRsZXRzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgYXR0cmlidXRlTmFtZUZvck91dGxldE5hbWUob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5zY2hlbWEub3V0bGV0QXR0cmlidXRlRm9yU2NvcGUodGhpcy5pZGVudGlmaWVyLCBvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgZ2V0T3V0bGV0TmFtZUZyb21PdXRsZXRBdHRyaWJ1dGVOYW1lKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0RGVmaW5pdGlvbnMuZmluZCgob3V0bGV0TmFtZSkgPT4gdGhpcy5hdHRyaWJ1dGVOYW1lRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKSA9PT0gYXR0cmlidXRlTmFtZSk7XG4gICAgfVxuICAgIGdldCBvdXRsZXREZXBlbmRlbmNpZXMoKSB7XG4gICAgICAgIGNvbnN0IGRlcGVuZGVuY2llcyA9IG5ldyBNdWx0aW1hcCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5tb2R1bGVzLmZvckVhY2goKG1vZHVsZSkgPT4ge1xuICAgICAgICAgICAgY29uc3QgY29uc3RydWN0b3IgPSBtb2R1bGUuZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3I7XG4gICAgICAgICAgICBjb25zdCBvdXRsZXRzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwib3V0bGV0c1wiKTtcbiAgICAgICAgICAgIG91dGxldHMuZm9yRWFjaCgob3V0bGV0KSA9PiBkZXBlbmRlbmNpZXMuYWRkKG91dGxldCwgbW9kdWxlLmlkZW50aWZpZXIpKTtcbiAgICAgICAgfSk7XG4gICAgICAgIHJldHVybiBkZXBlbmRlbmNpZXM7XG4gICAgfVxuICAgIGdldCBvdXRsZXREZWZpbml0aW9ucygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0RGVwZW5kZW5jaWVzLmdldEtleXNGb3JWYWx1ZSh0aGlzLmlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBnZXQgZGVwZW5kZW50Q29udHJvbGxlcklkZW50aWZpZXJzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXREZXBlbmRlbmNpZXMuZ2V0VmFsdWVzRm9yS2V5KHRoaXMuaWRlbnRpZmllcik7XG4gICAgfVxuICAgIGdldCBkZXBlbmRlbnRDb250ZXh0cygpIHtcbiAgICAgICAgY29uc3QgaWRlbnRpZmllcnMgPSB0aGlzLmRlcGVuZGVudENvbnRyb2xsZXJJZGVudGlmaWVycztcbiAgICAgICAgcmV0dXJuIHRoaXMucm91dGVyLmNvbnRleHRzLmZpbHRlcigoY29udGV4dCkgPT4gaWRlbnRpZmllcnMuaW5jbHVkZXMoY29udGV4dC5pZGVudGlmaWVyKSk7XG4gICAgfVxuICAgIGhhc091dGxldChlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiAhIXRoaXMuZ2V0T3V0bGV0KGVsZW1lbnQsIG91dGxldE5hbWUpIHx8ICEhdGhpcy5nZXRPdXRsZXRGcm9tTWFwKGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBnZXRPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5nZXRDb250cm9sbGVyRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgfVxuICAgIGdldE91dGxldEZyb21NYXAoZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShvdXRsZXROYW1lKS5maW5kKChvdXRsZXQpID0+IG91dGxldC5lbGVtZW50ID09PSBlbGVtZW50KTtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IGFwcGxpY2F0aW9uKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgcm91dGVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5yb3V0ZXI7XG4gICAgfVxufVxuXG5jbGFzcyBDb250ZXh0IHtcbiAgICBjb25zdHJ1Y3Rvcihtb2R1bGUsIHNjb3BlKSB7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eSA9IChmdW5jdGlvbk5hbWUsIGRldGFpbCA9IHt9KSA9PiB7XG4gICAgICAgICAgICBjb25zdCB7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXIsIGVsZW1lbnQgfSA9IHRoaXM7XG4gICAgICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9LCBkZXRhaWwpO1xuICAgICAgICAgICAgdGhpcy5hcHBsaWNhdGlvbi5sb2dEZWJ1Z0FjdGl2aXR5KHRoaXMuaWRlbnRpZmllciwgZnVuY3Rpb25OYW1lLCBkZXRhaWwpO1xuICAgICAgICB9O1xuICAgICAgICB0aGlzLm1vZHVsZSA9IG1vZHVsZTtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgICAgICB0aGlzLmNvbnRyb2xsZXIgPSBuZXcgbW9kdWxlLmNvbnRyb2xsZXJDb25zdHJ1Y3Rvcih0aGlzKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nT2JzZXJ2ZXIgPSBuZXcgQmluZGluZ09ic2VydmVyKHRoaXMsIHRoaXMuZGlzcGF0Y2hlcik7XG4gICAgICAgIHRoaXMudmFsdWVPYnNlcnZlciA9IG5ldyBWYWx1ZU9ic2VydmVyKHRoaXMsIHRoaXMuY29udHJvbGxlcik7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIgPSBuZXcgVGFyZ2V0T2JzZXJ2ZXIodGhpcywgdGhpcyk7XG4gICAgICAgIHRoaXMub3V0bGV0T2JzZXJ2ZXIgPSBuZXcgT3V0bGV0T2JzZXJ2ZXIodGhpcywgdGhpcyk7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLmNvbnRyb2xsZXIuaW5pdGlhbGl6ZSgpO1xuICAgICAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiaW5pdGlhbGl6ZVwiKTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHRoaXMuaGFuZGxlRXJyb3IoZXJyb3IsIFwiaW5pdGlhbGl6aW5nIGNvbnRyb2xsZXJcIik7XG4gICAgICAgIH1cbiAgICB9XG4gICAgY29ubmVjdCgpIHtcbiAgICAgICAgdGhpcy5iaW5kaW5nT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy52YWx1ZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB0cnkge1xuICAgICAgICAgICAgdGhpcy5jb250cm9sbGVyLmNvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImNvbm5lY3RcIik7XG4gICAgICAgIH1cbiAgICAgICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICB0aGlzLmhhbmRsZUVycm9yKGVycm9yLCBcImNvbm5lY3RpbmcgY29udHJvbGxlclwiKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLm91dGxldE9ic2VydmVyLnJlZnJlc2goKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIHRoaXMuY29udHJvbGxlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJkaXNjb25uZWN0XCIpO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgdGhpcy5oYW5kbGVFcnJvcihlcnJvciwgXCJkaXNjb25uZWN0aW5nIGNvbnRyb2xsZXJcIik7XG4gICAgICAgIH1cbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLnZhbHVlT2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLmJpbmRpbmdPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGdldCBhcHBsaWNhdGlvbigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMubW9kdWxlLmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMubW9kdWxlLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGRpc3BhdGNoZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmRpc3BhdGNoZXI7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgcGFyZW50RWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5wYXJlbnRFbGVtZW50O1xuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsID0ge30pIHtcbiAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50IH0gPSB0aGlzO1xuICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9LCBkZXRhaWwpO1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBgRXJyb3IgJHttZXNzYWdlfWAsIGRldGFpbCk7XG4gICAgfVxuICAgIHRhcmdldENvbm5lY3RlZChlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHRoaXMuaW52b2tlQ29udHJvbGxlck1ldGhvZChgJHtuYW1lfVRhcmdldENvbm5lY3RlZGAsIGVsZW1lbnQpO1xuICAgIH1cbiAgICB0YXJnZXREaXNjb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZX1UYXJnZXREaXNjb25uZWN0ZWRgLCBlbGVtZW50KTtcbiAgICB9XG4gICAgb3V0bGV0Q29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZXNwYWNlQ2FtZWxpemUobmFtZSl9T3V0bGV0Q29ubmVjdGVkYCwgb3V0bGV0LCBlbGVtZW50KTtcbiAgICB9XG4gICAgb3V0bGV0RGlzY29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZXNwYWNlQ2FtZWxpemUobmFtZSl9T3V0bGV0RGlzY29ubmVjdGVkYCwgb3V0bGV0LCBlbGVtZW50KTtcbiAgICB9XG4gICAgaW52b2tlQ29udHJvbGxlck1ldGhvZChtZXRob2ROYW1lLCAuLi5hcmdzKSB7XG4gICAgICAgIGNvbnN0IGNvbnRyb2xsZXIgPSB0aGlzLmNvbnRyb2xsZXI7XG4gICAgICAgIGlmICh0eXBlb2YgY29udHJvbGxlclttZXRob2ROYW1lXSA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgICAgIGNvbnRyb2xsZXJbbWV0aG9kTmFtZV0oLi4uYXJncyk7XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmZ1bmN0aW9uIGJsZXNzKGNvbnN0cnVjdG9yKSB7XG4gICAgcmV0dXJuIHNoYWRvdyhjb25zdHJ1Y3RvciwgZ2V0Qmxlc3NlZFByb3BlcnRpZXMoY29uc3RydWN0b3IpKTtcbn1cbmZ1bmN0aW9uIHNoYWRvdyhjb25zdHJ1Y3RvciwgcHJvcGVydGllcykge1xuICAgIGNvbnN0IHNoYWRvd0NvbnN0cnVjdG9yID0gZXh0ZW5kKGNvbnN0cnVjdG9yKTtcbiAgICBjb25zdCBzaGFkb3dQcm9wZXJ0aWVzID0gZ2V0U2hhZG93UHJvcGVydGllcyhjb25zdHJ1Y3Rvci5wcm90b3R5cGUsIHByb3BlcnRpZXMpO1xuICAgIE9iamVjdC5kZWZpbmVQcm9wZXJ0aWVzKHNoYWRvd0NvbnN0cnVjdG9yLnByb3RvdHlwZSwgc2hhZG93UHJvcGVydGllcyk7XG4gICAgcmV0dXJuIHNoYWRvd0NvbnN0cnVjdG9yO1xufVxuZnVuY3Rpb24gZ2V0Qmxlc3NlZFByb3BlcnRpZXMoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBibGVzc2luZ3MgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJibGVzc2luZ3NcIik7XG4gICAgcmV0dXJuIGJsZXNzaW5ncy5yZWR1Y2UoKGJsZXNzZWRQcm9wZXJ0aWVzLCBibGVzc2luZykgPT4ge1xuICAgICAgICBjb25zdCBwcm9wZXJ0aWVzID0gYmxlc3NpbmcoY29uc3RydWN0b3IpO1xuICAgICAgICBmb3IgKGNvbnN0IGtleSBpbiBwcm9wZXJ0aWVzKSB7XG4gICAgICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gYmxlc3NlZFByb3BlcnRpZXNba2V5XSB8fCB7fTtcbiAgICAgICAgICAgIGJsZXNzZWRQcm9wZXJ0aWVzW2tleV0gPSBPYmplY3QuYXNzaWduKGRlc2NyaXB0b3IsIHByb3BlcnRpZXNba2V5XSk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGJsZXNzZWRQcm9wZXJ0aWVzO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIGdldFNoYWRvd1Byb3BlcnRpZXMocHJvdG90eXBlLCBwcm9wZXJ0aWVzKSB7XG4gICAgcmV0dXJuIGdldE93bktleXMocHJvcGVydGllcykucmVkdWNlKChzaGFkb3dQcm9wZXJ0aWVzLCBrZXkpID0+IHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IGdldFNoYWRvd2VkRGVzY3JpcHRvcihwcm90b3R5cGUsIHByb3BlcnRpZXMsIGtleSk7XG4gICAgICAgIGlmIChkZXNjcmlwdG9yKSB7XG4gICAgICAgICAgICBPYmplY3QuYXNzaWduKHNoYWRvd1Byb3BlcnRpZXMsIHsgW2tleV06IGRlc2NyaXB0b3IgfSk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHNoYWRvd1Byb3BlcnRpZXM7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gZ2V0U2hhZG93ZWREZXNjcmlwdG9yKHByb3RvdHlwZSwgcHJvcGVydGllcywga2V5KSB7XG4gICAgY29uc3Qgc2hhZG93aW5nRGVzY3JpcHRvciA9IE9iamVjdC5nZXRPd25Qcm9wZXJ0eURlc2NyaXB0b3IocHJvdG90eXBlLCBrZXkpO1xuICAgIGNvbnN0IHNoYWRvd2VkQnlWYWx1ZSA9IHNoYWRvd2luZ0Rlc2NyaXB0b3IgJiYgXCJ2YWx1ZVwiIGluIHNoYWRvd2luZ0Rlc2NyaXB0b3I7XG4gICAgaWYgKCFzaGFkb3dlZEJ5VmFsdWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IE9iamVjdC5nZXRPd25Qcm9wZXJ0eURlc2NyaXB0b3IocHJvcGVydGllcywga2V5KS52YWx1ZTtcbiAgICAgICAgaWYgKHNoYWRvd2luZ0Rlc2NyaXB0b3IpIHtcbiAgICAgICAgICAgIGRlc2NyaXB0b3IuZ2V0ID0gc2hhZG93aW5nRGVzY3JpcHRvci5nZXQgfHwgZGVzY3JpcHRvci5nZXQ7XG4gICAgICAgICAgICBkZXNjcmlwdG9yLnNldCA9IHNoYWRvd2luZ0Rlc2NyaXB0b3Iuc2V0IHx8IGRlc2NyaXB0b3Iuc2V0O1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBkZXNjcmlwdG9yO1xuICAgIH1cbn1cbmNvbnN0IGdldE93bktleXMgPSAoKCkgPT4ge1xuICAgIGlmICh0eXBlb2YgT2JqZWN0LmdldE93blByb3BlcnR5U3ltYm9scyA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgcmV0dXJuIChvYmplY3QpID0+IFsuLi5PYmplY3QuZ2V0T3duUHJvcGVydHlOYW1lcyhvYmplY3QpLCAuLi5PYmplY3QuZ2V0T3duUHJvcGVydHlTeW1ib2xzKG9iamVjdCldO1xuICAgIH1cbiAgICBlbHNlIHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5nZXRPd25Qcm9wZXJ0eU5hbWVzO1xuICAgIH1cbn0pKCk7XG5jb25zdCBleHRlbmQgPSAoKCkgPT4ge1xuICAgIGZ1bmN0aW9uIGV4dGVuZFdpdGhSZWZsZWN0KGNvbnN0cnVjdG9yKSB7XG4gICAgICAgIGZ1bmN0aW9uIGV4dGVuZGVkKCkge1xuICAgICAgICAgICAgcmV0dXJuIFJlZmxlY3QuY29uc3RydWN0KGNvbnN0cnVjdG9yLCBhcmd1bWVudHMsIG5ldy50YXJnZXQpO1xuICAgICAgICB9XG4gICAgICAgIGV4dGVuZGVkLnByb3RvdHlwZSA9IE9iamVjdC5jcmVhdGUoY29uc3RydWN0b3IucHJvdG90eXBlLCB7XG4gICAgICAgICAgICBjb25zdHJ1Y3RvcjogeyB2YWx1ZTogZXh0ZW5kZWQgfSxcbiAgICAgICAgfSk7XG4gICAgICAgIFJlZmxlY3Quc2V0UHJvdG90eXBlT2YoZXh0ZW5kZWQsIGNvbnN0cnVjdG9yKTtcbiAgICAgICAgcmV0dXJuIGV4dGVuZGVkO1xuICAgIH1cbiAgICBmdW5jdGlvbiB0ZXN0UmVmbGVjdEV4dGVuc2lvbigpIHtcbiAgICAgICAgY29uc3QgYSA9IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHRoaXMuYS5jYWxsKHRoaXMpO1xuICAgICAgICB9O1xuICAgICAgICBjb25zdCBiID0gZXh0ZW5kV2l0aFJlZmxlY3QoYSk7XG4gICAgICAgIGIucHJvdG90eXBlLmEgPSBmdW5jdGlvbiAoKSB7IH07XG4gICAgICAgIHJldHVybiBuZXcgYigpO1xuICAgIH1cbiAgICB0cnkge1xuICAgICAgICB0ZXN0UmVmbGVjdEV4dGVuc2lvbigpO1xuICAgICAgICByZXR1cm4gZXh0ZW5kV2l0aFJlZmxlY3Q7XG4gICAgfVxuICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICByZXR1cm4gKGNvbnN0cnVjdG9yKSA9PiBjbGFzcyBleHRlbmRlZCBleHRlbmRzIGNvbnN0cnVjdG9yIHtcbiAgICAgICAgfTtcbiAgICB9XG59KSgpO1xuXG5mdW5jdGlvbiBibGVzc0RlZmluaXRpb24oZGVmaW5pdGlvbikge1xuICAgIHJldHVybiB7XG4gICAgICAgIGlkZW50aWZpZXI6IGRlZmluaXRpb24uaWRlbnRpZmllcixcbiAgICAgICAgY29udHJvbGxlckNvbnN0cnVjdG9yOiBibGVzcyhkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvciksXG4gICAgfTtcbn1cblxuY2xhc3MgTW9kdWxlIHtcbiAgICBjb25zdHJ1Y3RvcihhcHBsaWNhdGlvbiwgZGVmaW5pdGlvbikge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uID0gYXBwbGljYXRpb247XG4gICAgICAgIHRoaXMuZGVmaW5pdGlvbiA9IGJsZXNzRGVmaW5pdGlvbihkZWZpbml0aW9uKTtcbiAgICAgICAgdGhpcy5jb250ZXh0c0J5U2NvcGUgPSBuZXcgV2Vha01hcCgpO1xuICAgICAgICB0aGlzLmNvbm5lY3RlZENvbnRleHRzID0gbmV3IFNldCgpO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVmaW5pdGlvbi5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlckNvbnN0cnVjdG9yKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5kZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvcjtcbiAgICB9XG4gICAgZ2V0IGNvbnRleHRzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmNvbm5lY3RlZENvbnRleHRzKTtcbiAgICB9XG4gICAgY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkge1xuICAgICAgICBjb25zdCBjb250ZXh0ID0gdGhpcy5mZXRjaENvbnRleHRGb3JTY29wZShzY29wZSk7XG4gICAgICAgIHRoaXMuY29ubmVjdGVkQ29udGV4dHMuYWRkKGNvbnRleHQpO1xuICAgICAgICBjb250ZXh0LmNvbm5lY3QoKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkge1xuICAgICAgICBjb25zdCBjb250ZXh0ID0gdGhpcy5jb250ZXh0c0J5U2NvcGUuZ2V0KHNjb3BlKTtcbiAgICAgICAgaWYgKGNvbnRleHQpIHtcbiAgICAgICAgICAgIHRoaXMuY29ubmVjdGVkQ29udGV4dHMuZGVsZXRlKGNvbnRleHQpO1xuICAgICAgICAgICAgY29udGV4dC5kaXNjb25uZWN0KCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZmV0Y2hDb250ZXh0Rm9yU2NvcGUoc2NvcGUpIHtcbiAgICAgICAgbGV0IGNvbnRleHQgPSB0aGlzLmNvbnRleHRzQnlTY29wZS5nZXQoc2NvcGUpO1xuICAgICAgICBpZiAoIWNvbnRleHQpIHtcbiAgICAgICAgICAgIGNvbnRleHQgPSBuZXcgQ29udGV4dCh0aGlzLCBzY29wZSk7XG4gICAgICAgICAgICB0aGlzLmNvbnRleHRzQnlTY29wZS5zZXQoc2NvcGUsIGNvbnRleHQpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBjb250ZXh0O1xuICAgIH1cbn1cblxuY2xhc3MgQ2xhc3NNYXAge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgaGFzKG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGF0YS5oYXModGhpcy5nZXREYXRhS2V5KG5hbWUpKTtcbiAgICB9XG4gICAgZ2V0KG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZ2V0QWxsKG5hbWUpWzBdO1xuICAgIH1cbiAgICBnZXRBbGwobmFtZSkge1xuICAgICAgICBjb25zdCB0b2tlblN0cmluZyA9IHRoaXMuZGF0YS5nZXQodGhpcy5nZXREYXRhS2V5KG5hbWUpKSB8fCBcIlwiO1xuICAgICAgICByZXR1cm4gdG9rZW5pemUodG9rZW5TdHJpbmcpO1xuICAgIH1cbiAgICBnZXRBdHRyaWJ1dGVOYW1lKG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGF0YS5nZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KHRoaXMuZ2V0RGF0YUtleShuYW1lKSk7XG4gICAgfVxuICAgIGdldERhdGFLZXkobmFtZSkge1xuICAgICAgICByZXR1cm4gYCR7bmFtZX0tY2xhc3NgO1xuICAgIH1cbiAgICBnZXQgZGF0YSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZGF0YTtcbiAgICB9XG59XG5cbmNsYXNzIERhdGFNYXAge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQoa2V5KSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUobmFtZSk7XG4gICAgfVxuICAgIHNldChrZXksIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgdGhpcy5lbGVtZW50LnNldEF0dHJpYnV0ZShuYW1lLCB2YWx1ZSk7XG4gICAgICAgIHJldHVybiB0aGlzLmdldChrZXkpO1xuICAgIH1cbiAgICBoYXMoa2V5KSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5oYXNBdHRyaWJ1dGUobmFtZSk7XG4gICAgfVxuICAgIGRlbGV0ZShrZXkpIHtcbiAgICAgICAgaWYgKHRoaXMuaGFzKGtleSkpIHtcbiAgICAgICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgICAgIHRoaXMuZWxlbWVudC5yZW1vdmVBdHRyaWJ1dGUobmFtZSk7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KGtleSkge1xuICAgICAgICByZXR1cm4gYGRhdGEtJHt0aGlzLmlkZW50aWZpZXJ9LSR7ZGFzaGVyaXplKGtleSl9YDtcbiAgICB9XG59XG5cbmNsYXNzIEd1aWRlIHtcbiAgICBjb25zdHJ1Y3Rvcihsb2dnZXIpIHtcbiAgICAgICAgdGhpcy53YXJuZWRLZXlzQnlPYmplY3QgPSBuZXcgV2Vha01hcCgpO1xuICAgICAgICB0aGlzLmxvZ2dlciA9IGxvZ2dlcjtcbiAgICB9XG4gICAgd2FybihvYmplY3QsIGtleSwgbWVzc2FnZSkge1xuICAgICAgICBsZXQgd2FybmVkS2V5cyA9IHRoaXMud2FybmVkS2V5c0J5T2JqZWN0LmdldChvYmplY3QpO1xuICAgICAgICBpZiAoIXdhcm5lZEtleXMpIHtcbiAgICAgICAgICAgIHdhcm5lZEtleXMgPSBuZXcgU2V0KCk7XG4gICAgICAgICAgICB0aGlzLndhcm5lZEtleXNCeU9iamVjdC5zZXQob2JqZWN0LCB3YXJuZWRLZXlzKTtcbiAgICAgICAgfVxuICAgICAgICBpZiAoIXdhcm5lZEtleXMuaGFzKGtleSkpIHtcbiAgICAgICAgICAgIHdhcm5lZEtleXMuYWRkKGtleSk7XG4gICAgICAgICAgICB0aGlzLmxvZ2dlci53YXJuKG1lc3NhZ2UsIG9iamVjdCk7XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmZ1bmN0aW9uIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbihhdHRyaWJ1dGVOYW1lLCB0b2tlbikge1xuICAgIHJldHVybiBgWyR7YXR0cmlidXRlTmFtZX1+PVwiJHt0b2tlbn1cIl1gO1xufVxuXG5jbGFzcyBUYXJnZXRTZXQge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5zY2hlbWE7XG4gICAgfVxuICAgIGhhcyh0YXJnZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmZpbmQodGFyZ2V0TmFtZSkgIT0gbnVsbDtcbiAgICB9XG4gICAgZmluZCguLi50YXJnZXROYW1lcykge1xuICAgICAgICByZXR1cm4gdGFyZ2V0TmFtZXMucmVkdWNlKCh0YXJnZXQsIHRhcmdldE5hbWUpID0+IHRhcmdldCB8fCB0aGlzLmZpbmRUYXJnZXQodGFyZ2V0TmFtZSkgfHwgdGhpcy5maW5kTGVnYWN5VGFyZ2V0KHRhcmdldE5hbWUpLCB1bmRlZmluZWQpO1xuICAgIH1cbiAgICBmaW5kQWxsKC4uLnRhcmdldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiB0YXJnZXROYW1lcy5yZWR1Y2UoKHRhcmdldHMsIHRhcmdldE5hbWUpID0+IFtcbiAgICAgICAgICAgIC4uLnRhcmdldHMsXG4gICAgICAgICAgICAuLi50aGlzLmZpbmRBbGxUYXJnZXRzKHRhcmdldE5hbWUpLFxuICAgICAgICAgICAgLi4udGhpcy5maW5kQWxsTGVnYWN5VGFyZ2V0cyh0YXJnZXROYW1lKSxcbiAgICAgICAgXSwgW10pO1xuICAgIH1cbiAgICBmaW5kVGFyZ2V0KHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZmluZEVsZW1lbnQoc2VsZWN0b3IpO1xuICAgIH1cbiAgICBmaW5kQWxsVGFyZ2V0cyh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSk7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmZpbmRBbGxFbGVtZW50cyhzZWxlY3Rvcik7XG4gICAgfVxuICAgIGdldFNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGVGb3JTY29wZSh0aGlzLmlkZW50aWZpZXIpO1xuICAgICAgICByZXR1cm4gYXR0cmlidXRlVmFsdWVDb250YWluc1Rva2VuKGF0dHJpYnV0ZU5hbWUsIHRhcmdldE5hbWUpO1xuICAgIH1cbiAgICBmaW5kTGVnYWN5VGFyZ2V0KHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldExlZ2FjeVNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVwcmVjYXRlKHRoaXMuc2NvcGUuZmluZEVsZW1lbnQoc2VsZWN0b3IpLCB0YXJnZXROYW1lKTtcbiAgICB9XG4gICAgZmluZEFsbExlZ2FjeVRhcmdldHModGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0TGVnYWN5U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5maW5kQWxsRWxlbWVudHMoc2VsZWN0b3IpLm1hcCgoZWxlbWVudCkgPT4gdGhpcy5kZXByZWNhdGUoZWxlbWVudCwgdGFyZ2V0TmFtZSkpO1xuICAgIH1cbiAgICBnZXRMZWdhY3lTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCB0YXJnZXREZXNjcmlwdG9yID0gYCR7dGhpcy5pZGVudGlmaWVyfS4ke3RhcmdldE5hbWV9YDtcbiAgICAgICAgcmV0dXJuIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbih0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGUsIHRhcmdldERlc2NyaXB0b3IpO1xuICAgIH1cbiAgICBkZXByZWNhdGUoZWxlbWVudCwgdGFyZ2V0TmFtZSkge1xuICAgICAgICBpZiAoZWxlbWVudCkge1xuICAgICAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyIH0gPSB0aGlzO1xuICAgICAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuc2NoZW1hLnRhcmdldEF0dHJpYnV0ZTtcbiAgICAgICAgICAgIGNvbnN0IHJldmlzZWRBdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEudGFyZ2V0QXR0cmlidXRlRm9yU2NvcGUoaWRlbnRpZmllcik7XG4gICAgICAgICAgICB0aGlzLmd1aWRlLndhcm4oZWxlbWVudCwgYHRhcmdldDoke3RhcmdldE5hbWV9YCwgYFBsZWFzZSByZXBsYWNlICR7YXR0cmlidXRlTmFtZX09XCIke2lkZW50aWZpZXJ9LiR7dGFyZ2V0TmFtZX1cIiB3aXRoICR7cmV2aXNlZEF0dHJpYnV0ZU5hbWV9PVwiJHt0YXJnZXROYW1lfVwiLiBgICtcbiAgICAgICAgICAgICAgICBgVGhlICR7YXR0cmlidXRlTmFtZX0gYXR0cmlidXRlIGlzIGRlcHJlY2F0ZWQgYW5kIHdpbGwgYmUgcmVtb3ZlZCBpbiBhIGZ1dHVyZSB2ZXJzaW9uIG9mIFN0aW11bHVzLmApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBlbGVtZW50O1xuICAgIH1cbiAgICBnZXQgZ3VpZGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmd1aWRlO1xuICAgIH1cbn1cblxuY2xhc3MgT3V0bGV0U2V0IHtcbiAgICBjb25zdHJ1Y3RvcihzY29wZSwgY29udHJvbGxlckVsZW1lbnQpIHtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgICAgICB0aGlzLmNvbnRyb2xsZXJFbGVtZW50ID0gY29udHJvbGxlckVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuc2NoZW1hO1xuICAgIH1cbiAgICBoYXMob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5maW5kKG91dGxldE5hbWUpICE9IG51bGw7XG4gICAgfVxuICAgIGZpbmQoLi4ub3V0bGV0TmFtZXMpIHtcbiAgICAgICAgcmV0dXJuIG91dGxldE5hbWVzLnJlZHVjZSgob3V0bGV0LCBvdXRsZXROYW1lKSA9PiBvdXRsZXQgfHwgdGhpcy5maW5kT3V0bGV0KG91dGxldE5hbWUpLCB1bmRlZmluZWQpO1xuICAgIH1cbiAgICBmaW5kQWxsKC4uLm91dGxldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiBvdXRsZXROYW1lcy5yZWR1Y2UoKG91dGxldHMsIG91dGxldE5hbWUpID0+IFsuLi5vdXRsZXRzLCAuLi50aGlzLmZpbmRBbGxPdXRsZXRzKG91dGxldE5hbWUpXSwgW10pO1xuICAgIH1cbiAgICBnZXRTZWxlY3RvckZvck91dGxldE5hbWUob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEub3V0bGV0QXR0cmlidXRlRm9yU2NvcGUodGhpcy5pZGVudGlmaWVyLCBvdXRsZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udHJvbGxlckVsZW1lbnQuZ2V0QXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbiAgICBmaW5kT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKHNlbGVjdG9yKVxuICAgICAgICAgICAgcmV0dXJuIHRoaXMuZmluZEVsZW1lbnQoc2VsZWN0b3IsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBmaW5kQWxsT3V0bGV0cyhvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRTZWxlY3RvckZvck91dGxldE5hbWUob3V0bGV0TmFtZSk7XG4gICAgICAgIHJldHVybiBzZWxlY3RvciA/IHRoaXMuZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yLCBvdXRsZXROYW1lKSA6IFtdO1xuICAgIH1cbiAgICBmaW5kRWxlbWVudChzZWxlY3Rvciwgb3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBlbGVtZW50cyA9IHRoaXMuc2NvcGUucXVlcnlFbGVtZW50cyhzZWxlY3Rvcik7XG4gICAgICAgIHJldHVybiBlbGVtZW50cy5maWx0ZXIoKGVsZW1lbnQpID0+IHRoaXMubWF0Y2hlc0VsZW1lbnQoZWxlbWVudCwgc2VsZWN0b3IsIG91dGxldE5hbWUpKVswXTtcbiAgICB9XG4gICAgZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yLCBvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGVsZW1lbnRzID0gdGhpcy5zY29wZS5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKTtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnRzLmZpbHRlcigoZWxlbWVudCkgPT4gdGhpcy5tYXRjaGVzRWxlbWVudChlbGVtZW50LCBzZWxlY3Rvciwgb3V0bGV0TmFtZSkpO1xuICAgIH1cbiAgICBtYXRjaGVzRWxlbWVudChlbGVtZW50LCBzZWxlY3Rvciwgb3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBjb250cm9sbGVyQXR0cmlidXRlID0gZWxlbWVudC5nZXRBdHRyaWJ1dGUodGhpcy5zY29wZS5zY2hlbWEuY29udHJvbGxlckF0dHJpYnV0ZSkgfHwgXCJcIjtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnQubWF0Y2hlcyhzZWxlY3RvcikgJiYgY29udHJvbGxlckF0dHJpYnV0ZS5zcGxpdChcIiBcIikuaW5jbHVkZXMob3V0bGV0TmFtZSk7XG4gICAgfVxufVxuXG5jbGFzcyBTY29wZSB7XG4gICAgY29uc3RydWN0b3Ioc2NoZW1hLCBlbGVtZW50LCBpZGVudGlmaWVyLCBsb2dnZXIpIHtcbiAgICAgICAgdGhpcy50YXJnZXRzID0gbmV3IFRhcmdldFNldCh0aGlzKTtcbiAgICAgICAgdGhpcy5jbGFzc2VzID0gbmV3IENsYXNzTWFwKHRoaXMpO1xuICAgICAgICB0aGlzLmRhdGEgPSBuZXcgRGF0YU1hcCh0aGlzKTtcbiAgICAgICAgdGhpcy5jb250YWluc0VsZW1lbnQgPSAoZWxlbWVudCkgPT4ge1xuICAgICAgICAgICAgcmV0dXJuIGVsZW1lbnQuY2xvc2VzdCh0aGlzLmNvbnRyb2xsZXJTZWxlY3RvcikgPT09IHRoaXMuZWxlbWVudDtcbiAgICAgICAgfTtcbiAgICAgICAgdGhpcy5zY2hlbWEgPSBzY2hlbWE7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuaWRlbnRpZmllciA9IGlkZW50aWZpZXI7XG4gICAgICAgIHRoaXMuZ3VpZGUgPSBuZXcgR3VpZGUobG9nZ2VyKTtcbiAgICAgICAgdGhpcy5vdXRsZXRzID0gbmV3IE91dGxldFNldCh0aGlzLmRvY3VtZW50U2NvcGUsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBmaW5kRWxlbWVudChzZWxlY3Rvcikge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpID8gdGhpcy5lbGVtZW50IDogdGhpcy5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKS5maW5kKHRoaXMuY29udGFpbnNFbGVtZW50KTtcbiAgICB9XG4gICAgZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yKSB7XG4gICAgICAgIHJldHVybiBbXG4gICAgICAgICAgICAuLi4odGhpcy5lbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpID8gW3RoaXMuZWxlbWVudF0gOiBbXSksXG4gICAgICAgICAgICAuLi50aGlzLnF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpLmZpbHRlcih0aGlzLmNvbnRhaW5zRWxlbWVudCksXG4gICAgICAgIF07XG4gICAgfVxuICAgIHF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5lbGVtZW50LnF1ZXJ5U2VsZWN0b3JBbGwoc2VsZWN0b3IpKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJTZWxlY3RvcigpIHtcbiAgICAgICAgcmV0dXJuIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbih0aGlzLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlLCB0aGlzLmlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBnZXQgaXNEb2N1bWVudFNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50ID09PSBkb2N1bWVudC5kb2N1bWVudEVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBkb2N1bWVudFNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5pc0RvY3VtZW50U2NvcGVcbiAgICAgICAgICAgID8gdGhpc1xuICAgICAgICAgICAgOiBuZXcgU2NvcGUodGhpcy5zY2hlbWEsIGRvY3VtZW50LmRvY3VtZW50RWxlbWVudCwgdGhpcy5pZGVudGlmaWVyLCB0aGlzLmd1aWRlLmxvZ2dlcik7XG4gICAgfVxufVxuXG5jbGFzcyBTY29wZU9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBzY2hlbWEsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIgPSBuZXcgVmFsdWVMaXN0T2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLmNvbnRyb2xsZXJBdHRyaWJ1dGUsIHRoaXMpO1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllckJ5RWxlbWVudCA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMgPSBuZXcgV2Vha01hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJBdHRyaWJ1dGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlO1xuICAgIH1cbiAgICBwYXJzZVZhbHVlRm9yVG9rZW4odG9rZW4pIHtcbiAgICAgICAgY29uc3QgeyBlbGVtZW50LCBjb250ZW50OiBpZGVudGlmaWVyIH0gPSB0b2tlbjtcbiAgICAgICAgcmV0dXJuIHRoaXMucGFyc2VWYWx1ZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBwYXJzZVZhbHVlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBzY29wZXNCeUlkZW50aWZpZXIgPSB0aGlzLmZldGNoU2NvcGVzQnlJZGVudGlmaWVyRm9yRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgbGV0IHNjb3BlID0gc2NvcGVzQnlJZGVudGlmaWVyLmdldChpZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKCFzY29wZSkge1xuICAgICAgICAgICAgc2NvcGUgPSB0aGlzLmRlbGVnYXRlLmNyZWF0ZVNjb3BlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcik7XG4gICAgICAgICAgICBzY29wZXNCeUlkZW50aWZpZXIuc2V0KGlkZW50aWZpZXIsIHNjb3BlKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gc2NvcGU7XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpIHtcbiAgICAgICAgY29uc3QgcmVmZXJlbmNlQ291bnQgPSAodGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5nZXQodmFsdWUpIHx8IDApICsgMTtcbiAgICAgICAgdGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5zZXQodmFsdWUsIHJlZmVyZW5jZUNvdW50KTtcbiAgICAgICAgaWYgKHJlZmVyZW5jZUNvdW50ID09IDEpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc2NvcGVDb25uZWN0ZWQodmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRWYWx1ZShlbGVtZW50LCB2YWx1ZSkge1xuICAgICAgICBjb25zdCByZWZlcmVuY2VDb3VudCA9IHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMuZ2V0KHZhbHVlKTtcbiAgICAgICAgaWYgKHJlZmVyZW5jZUNvdW50KSB7XG4gICAgICAgICAgICB0aGlzLnNjb3BlUmVmZXJlbmNlQ291bnRzLnNldCh2YWx1ZSwgcmVmZXJlbmNlQ291bnQgLSAxKTtcbiAgICAgICAgICAgIGlmIChyZWZlcmVuY2VDb3VudCA9PSAxKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zY29wZURpc2Nvbm5lY3RlZCh2YWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgZmV0Y2hTY29wZXNCeUlkZW50aWZpZXJGb3JFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgbGV0IHNjb3Blc0J5SWRlbnRpZmllciA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyQnlFbGVtZW50LmdldChlbGVtZW50KTtcbiAgICAgICAgaWYgKCFzY29wZXNCeUlkZW50aWZpZXIpIHtcbiAgICAgICAgICAgIHNjb3Blc0J5SWRlbnRpZmllciA9IG5ldyBNYXAoKTtcbiAgICAgICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyQnlFbGVtZW50LnNldChlbGVtZW50LCBzY29wZXNCeUlkZW50aWZpZXIpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBzY29wZXNCeUlkZW50aWZpZXI7XG4gICAgfVxufVxuXG5jbGFzcyBSb3V0ZXIge1xuICAgIGNvbnN0cnVjdG9yKGFwcGxpY2F0aW9uKSB7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24gPSBhcHBsaWNhdGlvbjtcbiAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyID0gbmV3IFNjb3BlT2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLnNjaGVtYSwgdGhpcyk7XG4gICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMubW9kdWxlc0J5SWRlbnRpZmllciA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGxvZ2dlcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24ubG9nZ2VyO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlckF0dHJpYnV0ZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGU7XG4gICAgfVxuICAgIGdldCBtb2R1bGVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIudmFsdWVzKCkpO1xuICAgIH1cbiAgICBnZXQgY29udGV4dHMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm1vZHVsZXMucmVkdWNlKChjb250ZXh0cywgbW9kdWxlKSA9PiBjb250ZXh0cy5jb25jYXQobW9kdWxlLmNvbnRleHRzKSwgW10pO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuc2NvcGVPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGxvYWREZWZpbml0aW9uKGRlZmluaXRpb24pIHtcbiAgICAgICAgdGhpcy51bmxvYWRJZGVudGlmaWVyKGRlZmluaXRpb24uaWRlbnRpZmllcik7XG4gICAgICAgIGNvbnN0IG1vZHVsZSA9IG5ldyBNb2R1bGUodGhpcy5hcHBsaWNhdGlvbiwgZGVmaW5pdGlvbik7XG4gICAgICAgIHRoaXMuY29ubmVjdE1vZHVsZShtb2R1bGUpO1xuICAgICAgICBjb25zdCBhZnRlckxvYWQgPSBkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3Rvci5hZnRlckxvYWQ7XG4gICAgICAgIGlmIChhZnRlckxvYWQpIHtcbiAgICAgICAgICAgIGFmdGVyTG9hZC5jYWxsKGRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yLCBkZWZpbml0aW9uLmlkZW50aWZpZXIsIHRoaXMuYXBwbGljYXRpb24pO1xuICAgICAgICB9XG4gICAgfVxuICAgIHVubG9hZElkZW50aWZpZXIoaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RNb2R1bGUobW9kdWxlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXRDb250ZXh0Rm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICByZXR1cm4gbW9kdWxlLmNvbnRleHRzLmZpbmQoKGNvbnRleHQpID0+IGNvbnRleHQuZWxlbWVudCA9PSBlbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9wb3NlVG9Db25uZWN0U2NvcGVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgICAgIGNvbnN0IHNjb3BlID0gdGhpcy5zY29wZU9ic2VydmVyLnBhcnNlVmFsdWVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKHNjb3BlKSB7XG4gICAgICAgICAgICB0aGlzLnNjb3BlT2JzZXJ2ZXIuZWxlbWVudE1hdGNoZWRWYWx1ZShzY29wZS5lbGVtZW50LCBzY29wZSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICBjb25zb2xlLmVycm9yKGBDb3VsZG4ndCBmaW5kIG9yIGNyZWF0ZSBzY29wZSBmb3IgaWRlbnRpZmllcjogXCIke2lkZW50aWZpZXJ9XCIgYW5kIGVsZW1lbnQ6YCwgZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgaGFuZGxlRXJyb3IoZXJyb3IsIG1lc3NhZ2UsIGRldGFpbCkge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwpO1xuICAgIH1cbiAgICBjcmVhdGVTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgcmV0dXJuIG5ldyBTY29wZSh0aGlzLnNjaGVtYSwgZWxlbWVudCwgaWRlbnRpZmllciwgdGhpcy5sb2dnZXIpO1xuICAgIH1cbiAgICBzY29wZUNvbm5lY3RlZChzY29wZSkge1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllci5hZGQoc2NvcGUuaWRlbnRpZmllciwgc2NvcGUpO1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KHNjb3BlLmlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICBtb2R1bGUuY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2NvcGVEaXNjb25uZWN0ZWQoc2NvcGUpIHtcbiAgICAgICAgdGhpcy5zY29wZXNCeUlkZW50aWZpZXIuZGVsZXRlKHNjb3BlLmlkZW50aWZpZXIsIHNjb3BlKTtcbiAgICAgICAgY29uc3QgbW9kdWxlID0gdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmdldChzY29wZS5pZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKG1vZHVsZSkge1xuICAgICAgICAgICAgbW9kdWxlLmRpc2Nvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGNvbm5lY3RNb2R1bGUobW9kdWxlKSB7XG4gICAgICAgIHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5zZXQobW9kdWxlLmlkZW50aWZpZXIsIG1vZHVsZSk7XG4gICAgICAgIGNvbnN0IHNjb3BlcyA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmdldFZhbHVlc0ZvcktleShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIHNjb3Blcy5mb3JFYWNoKChzY29wZSkgPT4gbW9kdWxlLmNvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdE1vZHVsZShtb2R1bGUpIHtcbiAgICAgICAgdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmRlbGV0ZShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIGNvbnN0IHNjb3BlcyA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmdldFZhbHVlc0ZvcktleShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIHNjb3Blcy5mb3JFYWNoKChzY29wZSkgPT4gbW9kdWxlLmRpc2Nvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpKTtcbiAgICB9XG59XG5cbmNvbnN0IGRlZmF1bHRTY2hlbWEgPSB7XG4gICAgY29udHJvbGxlckF0dHJpYnV0ZTogXCJkYXRhLWNvbnRyb2xsZXJcIixcbiAgICBhY3Rpb25BdHRyaWJ1dGU6IFwiZGF0YS1hY3Rpb25cIixcbiAgICB0YXJnZXRBdHRyaWJ1dGU6IFwiZGF0YS10YXJnZXRcIixcbiAgICB0YXJnZXRBdHRyaWJ1dGVGb3JTY29wZTogKGlkZW50aWZpZXIpID0+IGBkYXRhLSR7aWRlbnRpZmllcn0tdGFyZ2V0YCxcbiAgICBvdXRsZXRBdHRyaWJ1dGVGb3JTY29wZTogKGlkZW50aWZpZXIsIG91dGxldCkgPT4gYGRhdGEtJHtpZGVudGlmaWVyfS0ke291dGxldH0tb3V0bGV0YCxcbiAgICBrZXlNYXBwaW5nczogT2JqZWN0LmFzc2lnbihPYmplY3QuYXNzaWduKHsgZW50ZXI6IFwiRW50ZXJcIiwgdGFiOiBcIlRhYlwiLCBlc2M6IFwiRXNjYXBlXCIsIHNwYWNlOiBcIiBcIiwgdXA6IFwiQXJyb3dVcFwiLCBkb3duOiBcIkFycm93RG93blwiLCBsZWZ0OiBcIkFycm93TGVmdFwiLCByaWdodDogXCJBcnJvd1JpZ2h0XCIsIGhvbWU6IFwiSG9tZVwiLCBlbmQ6IFwiRW5kXCIsIHBhZ2VfdXA6IFwiUGFnZVVwXCIsIHBhZ2VfZG93bjogXCJQYWdlRG93blwiIH0sIG9iamVjdEZyb21FbnRyaWVzKFwiYWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXpcIi5zcGxpdChcIlwiKS5tYXAoKGMpID0+IFtjLCBjXSkpKSwgb2JqZWN0RnJvbUVudHJpZXMoXCIwMTIzNDU2Nzg5XCIuc3BsaXQoXCJcIikubWFwKChuKSA9PiBbbiwgbl0pKSksXG59O1xuZnVuY3Rpb24gb2JqZWN0RnJvbUVudHJpZXMoYXJyYXkpIHtcbiAgICByZXR1cm4gYXJyYXkucmVkdWNlKChtZW1vLCBbaywgdl0pID0+IChPYmplY3QuYXNzaWduKE9iamVjdC5hc3NpZ24oe30sIG1lbW8pLCB7IFtrXTogdiB9KSksIHt9KTtcbn1cblxuY2xhc3MgQXBwbGljYXRpb24ge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQgPSBkb2N1bWVudC5kb2N1bWVudEVsZW1lbnQsIHNjaGVtYSA9IGRlZmF1bHRTY2hlbWEpIHtcbiAgICAgICAgdGhpcy5sb2dnZXIgPSBjb25zb2xlO1xuICAgICAgICB0aGlzLmRlYnVnID0gZmFsc2U7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eSA9IChpZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCA9IHt9KSA9PiB7XG4gICAgICAgICAgICBpZiAodGhpcy5kZWJ1Zykge1xuICAgICAgICAgICAgICAgIHRoaXMubG9nRm9ybWF0dGVkTWVzc2FnZShpZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH07XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgICAgICB0aGlzLmRpc3BhdGNoZXIgPSBuZXcgRGlzcGF0Y2hlcih0aGlzKTtcbiAgICAgICAgdGhpcy5yb3V0ZXIgPSBuZXcgUm91dGVyKHRoaXMpO1xuICAgICAgICB0aGlzLmFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzID0gT2JqZWN0LmFzc2lnbih7fSwgZGVmYXVsdEFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzKTtcbiAgICB9XG4gICAgc3RhdGljIHN0YXJ0KGVsZW1lbnQsIHNjaGVtYSkge1xuICAgICAgICBjb25zdCBhcHBsaWNhdGlvbiA9IG5ldyB0aGlzKGVsZW1lbnQsIHNjaGVtYSk7XG4gICAgICAgIGFwcGxpY2F0aW9uLnN0YXJ0KCk7XG4gICAgICAgIHJldHVybiBhcHBsaWNhdGlvbjtcbiAgICB9XG4gICAgYXN5bmMgc3RhcnQoKSB7XG4gICAgICAgIGF3YWl0IGRvbVJlYWR5KCk7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RhcnRpbmdcIik7XG4gICAgICAgIHRoaXMuZGlzcGF0Y2hlci5zdGFydCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5zdGFydCgpO1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJhcHBsaWNhdGlvblwiLCBcInN0YXJ0XCIpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJhcHBsaWNhdGlvblwiLCBcInN0b3BwaW5nXCIpO1xuICAgICAgICB0aGlzLmRpc3BhdGNoZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5zdG9wKCk7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RvcFwiKTtcbiAgICB9XG4gICAgcmVnaXN0ZXIoaWRlbnRpZmllciwgY29udHJvbGxlckNvbnN0cnVjdG9yKSB7XG4gICAgICAgIHRoaXMubG9hZCh7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXJDb25zdHJ1Y3RvciB9KTtcbiAgICB9XG4gICAgcmVnaXN0ZXJBY3Rpb25PcHRpb24obmFtZSwgZmlsdGVyKSB7XG4gICAgICAgIHRoaXMuYWN0aW9uRGVzY3JpcHRvckZpbHRlcnNbbmFtZV0gPSBmaWx0ZXI7XG4gICAgfVxuICAgIGxvYWQoaGVhZCwgLi4ucmVzdCkge1xuICAgICAgICBjb25zdCBkZWZpbml0aW9ucyA9IEFycmF5LmlzQXJyYXkoaGVhZCkgPyBoZWFkIDogW2hlYWQsIC4uLnJlc3RdO1xuICAgICAgICBkZWZpbml0aW9ucy5mb3JFYWNoKChkZWZpbml0aW9uKSA9PiB7XG4gICAgICAgICAgICBpZiAoZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3Iuc2hvdWxkTG9hZCkge1xuICAgICAgICAgICAgICAgIHRoaXMucm91dGVyLmxvYWREZWZpbml0aW9uKGRlZmluaXRpb24pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICB9XG4gICAgdW5sb2FkKGhlYWQsIC4uLnJlc3QpIHtcbiAgICAgICAgY29uc3QgaWRlbnRpZmllcnMgPSBBcnJheS5pc0FycmF5KGhlYWQpID8gaGVhZCA6IFtoZWFkLCAuLi5yZXN0XTtcbiAgICAgICAgaWRlbnRpZmllcnMuZm9yRWFjaCgoaWRlbnRpZmllcikgPT4gdGhpcy5yb3V0ZXIudW5sb2FkSWRlbnRpZmllcihpZGVudGlmaWVyKSk7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVycygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMucm91dGVyLmNvbnRleHRzLm1hcCgoY29udGV4dCkgPT4gY29udGV4dC5jb250cm9sbGVyKTtcbiAgICB9XG4gICAgZ2V0Q29udHJvbGxlckZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgY29uc3QgY29udGV4dCA9IHRoaXMucm91dGVyLmdldENvbnRleHRGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICAgICAgcmV0dXJuIGNvbnRleHQgPyBjb250ZXh0LmNvbnRyb2xsZXIgOiBudWxsO1xuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZXJyb3IoYCVzXFxuXFxuJW9cXG5cXG4lb2AsIG1lc3NhZ2UsIGVycm9yLCBkZXRhaWwpO1xuICAgICAgICAoX2EgPSB3aW5kb3cub25lcnJvcikgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLmNhbGwod2luZG93LCBtZXNzYWdlLCBcIlwiLCAwLCAwLCBlcnJvcik7XG4gICAgfVxuICAgIGxvZ0Zvcm1hdHRlZE1lc3NhZ2UoaWRlbnRpZmllciwgZnVuY3Rpb25OYW1lLCBkZXRhaWwgPSB7fSkge1xuICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgYXBwbGljYXRpb246IHRoaXMgfSwgZGV0YWlsKTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZ3JvdXBDb2xsYXBzZWQoYCR7aWRlbnRpZmllcn0gIyR7ZnVuY3Rpb25OYW1lfWApO1xuICAgICAgICB0aGlzLmxvZ2dlci5sb2coXCJkZXRhaWxzOlwiLCBPYmplY3QuYXNzaWduKHt9LCBkZXRhaWwpKTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZ3JvdXBFbmQoKTtcbiAgICB9XG59XG5mdW5jdGlvbiBkb21SZWFkeSgpIHtcbiAgICByZXR1cm4gbmV3IFByb21pc2UoKHJlc29sdmUpID0+IHtcbiAgICAgICAgaWYgKGRvY3VtZW50LnJlYWR5U3RhdGUgPT0gXCJsb2FkaW5nXCIpIHtcbiAgICAgICAgICAgIGRvY3VtZW50LmFkZEV2ZW50TGlzdGVuZXIoXCJET01Db250ZW50TG9hZGVkXCIsICgpID0+IHJlc29sdmUoKSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXNvbHZlKCk7XG4gICAgICAgIH1cbiAgICB9KTtcbn1cblxuZnVuY3Rpb24gQ2xhc3NQcm9wZXJ0aWVzQmxlc3NpbmcoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBjbGFzc2VzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwiY2xhc3Nlc1wiKTtcbiAgICByZXR1cm4gY2xhc3Nlcy5yZWR1Y2UoKHByb3BlcnRpZXMsIGNsYXNzRGVmaW5pdGlvbikgPT4ge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihwcm9wZXJ0aWVzLCBwcm9wZXJ0aWVzRm9yQ2xhc3NEZWZpbml0aW9uKGNsYXNzRGVmaW5pdGlvbikpO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JDbGFzc0RlZmluaXRpb24oa2V5KSB7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake2tleX1DbGFzc2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgeyBjbGFzc2VzIH0gPSB0aGlzO1xuICAgICAgICAgICAgICAgIGlmIChjbGFzc2VzLmhhcyhrZXkpKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBjbGFzc2VzLmdldChrZXkpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgY29uc3QgYXR0cmlidXRlID0gY2xhc3Nlcy5nZXRBdHRyaWJ1dGVOYW1lKGtleSk7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyBhdHRyaWJ1dGUgXCIke2F0dHJpYnV0ZX1cImApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtrZXl9Q2xhc3Nlc2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMuY2xhc3Nlcy5nZXRBbGwoa2V5KTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKGtleSl9Q2xhc3NgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLmNsYXNzZXMuaGFzKGtleSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5cbmZ1bmN0aW9uIE91dGxldFByb3BlcnRpZXNCbGVzc2luZyhjb25zdHJ1Y3Rvcikge1xuICAgIGNvbnN0IG91dGxldHMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJvdXRsZXRzXCIpO1xuICAgIHJldHVybiBvdXRsZXRzLnJlZHVjZSgocHJvcGVydGllcywgb3V0bGV0RGVmaW5pdGlvbikgPT4ge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihwcm9wZXJ0aWVzLCBwcm9wZXJ0aWVzRm9yT3V0bGV0RGVmaW5pdGlvbihvdXRsZXREZWZpbml0aW9uKSk7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gZ2V0T3V0bGV0Q29udHJvbGxlcihjb250cm9sbGVyLCBlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgcmV0dXJuIGNvbnRyb2xsZXIuYXBwbGljYXRpb24uZ2V0Q29udHJvbGxlckZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xufVxuZnVuY3Rpb24gZ2V0Q29udHJvbGxlckFuZEVuc3VyZUNvbm5lY3RlZFNjb3BlKGNvbnRyb2xsZXIsIGVsZW1lbnQsIG91dGxldE5hbWUpIHtcbiAgICBsZXQgb3V0bGV0Q29udHJvbGxlciA9IGdldE91dGxldENvbnRyb2xsZXIoY29udHJvbGxlciwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgaWYgKG91dGxldENvbnRyb2xsZXIpXG4gICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xuICAgIGNvbnRyb2xsZXIuYXBwbGljYXRpb24ucm91dGVyLnByb3Bvc2VUb0Nvbm5lY3RTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIG91dGxldENvbnRyb2xsZXIgPSBnZXRPdXRsZXRDb250cm9sbGVyKGNvbnRyb2xsZXIsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICByZXR1cm4gb3V0bGV0Q29udHJvbGxlcjtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JPdXRsZXREZWZpbml0aW9uKG5hbWUpIHtcbiAgICBjb25zdCBjYW1lbGl6ZWROYW1lID0gbmFtZXNwYWNlQ2FtZWxpemUobmFtZSk7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake2NhbWVsaXplZE5hbWV9T3V0bGV0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRFbGVtZW50ID0gdGhpcy5vdXRsZXRzLmZpbmQobmFtZSk7XG4gICAgICAgICAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLm91dGxldHMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmIChvdXRsZXRFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IG91dGxldENvbnRyb2xsZXIgPSBnZXRDb250cm9sbGVyQW5kRW5zdXJlQ29ubmVjdGVkU2NvcGUodGhpcywgb3V0bGV0RWxlbWVudCwgbmFtZSk7XG4gICAgICAgICAgICAgICAgICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICAgICAgICAgICAgICAgICAgcmV0dXJuIG91dGxldENvbnRyb2xsZXI7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgVGhlIHByb3ZpZGVkIG91dGxldCBlbGVtZW50IGlzIG1pc3NpbmcgYW4gb3V0bGV0IGNvbnRyb2xsZXIgXCIke25hbWV9XCIgaW5zdGFuY2UgZm9yIGhvc3QgY29udHJvbGxlciBcIiR7dGhpcy5pZGVudGlmaWVyfVwiYCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyBvdXRsZXQgZWxlbWVudCBcIiR7bmFtZX1cIiBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCIuIFN0aW11bHVzIGNvdWxkbid0IGZpbmQgYSBtYXRjaGluZyBvdXRsZXQgZWxlbWVudCB1c2luZyBzZWxlY3RvciBcIiR7c2VsZWN0b3J9XCIuYCk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRzID0gdGhpcy5vdXRsZXRzLmZpbmRBbGwobmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG91dGxldHMubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gb3V0bGV0c1xuICAgICAgICAgICAgICAgICAgICAgICAgLm1hcCgob3V0bGV0RWxlbWVudCkgPT4ge1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc3Qgb3V0bGV0Q29udHJvbGxlciA9IGdldENvbnRyb2xsZXJBbmRFbnN1cmVDb25uZWN0ZWRTY29wZSh0aGlzLCBvdXRsZXRFbGVtZW50LCBuYW1lKTtcbiAgICAgICAgICAgICAgICAgICAgICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc29sZS53YXJuKGBUaGUgcHJvdmlkZWQgb3V0bGV0IGVsZW1lbnQgaXMgbWlzc2luZyBhbiBvdXRsZXQgY29udHJvbGxlciBcIiR7bmFtZX1cIiBpbnN0YW5jZSBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCJgLCBvdXRsZXRFbGVtZW50KTtcbiAgICAgICAgICAgICAgICAgICAgfSlcbiAgICAgICAgICAgICAgICAgICAgICAgIC5maWx0ZXIoKGNvbnRyb2xsZXIpID0+IGNvbnRyb2xsZXIpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICByZXR1cm4gW107XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRFbGVtZW50YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRFbGVtZW50ID0gdGhpcy5vdXRsZXRzLmZpbmQobmFtZSk7XG4gICAgICAgICAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLm91dGxldHMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmIChvdXRsZXRFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRFbGVtZW50O1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBNaXNzaW5nIG91dGxldCBlbGVtZW50IFwiJHtuYW1lfVwiIGZvciBob3N0IGNvbnRyb2xsZXIgXCIke3RoaXMuaWRlbnRpZmllcn1cIi4gU3RpbXVsdXMgY291bGRuJ3QgZmluZCBhIG1hdGNoaW5nIG91dGxldCBlbGVtZW50IHVzaW5nIHNlbGVjdG9yIFwiJHtzZWxlY3Rvcn1cIi5gKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRFbGVtZW50c2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0cy5maW5kQWxsKG5hbWUpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2BoYXMke2NhcGl0YWxpemUoY2FtZWxpemVkTmFtZSl9T3V0bGV0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXRzLmhhcyhuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbn1cblxuZnVuY3Rpb24gVGFyZ2V0UHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgdGFyZ2V0cyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBcInRhcmdldHNcIik7XG4gICAgcmV0dXJuIHRhcmdldHMucmVkdWNlKChwcm9wZXJ0aWVzLCB0YXJnZXREZWZpbml0aW9uKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JUYXJnZXREZWZpbml0aW9uKHRhcmdldERlZmluaXRpb24pKTtcbiAgICB9LCB7fSk7XG59XG5mdW5jdGlvbiBwcm9wZXJ0aWVzRm9yVGFyZ2V0RGVmaW5pdGlvbihuYW1lKSB7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake25hbWV9VGFyZ2V0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCB0YXJnZXQgPSB0aGlzLnRhcmdldHMuZmluZChuYW1lKTtcbiAgICAgICAgICAgICAgICBpZiAodGFyZ2V0KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiB0YXJnZXQ7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICB0aHJvdyBuZXcgRXJyb3IoYE1pc3NpbmcgdGFyZ2V0IGVsZW1lbnQgXCIke25hbWV9XCIgZm9yIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCIgY29udHJvbGxlcmApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtuYW1lfVRhcmdldHNgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLnRhcmdldHMuZmluZEFsbChuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKG5hbWUpfVRhcmdldGBdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMudGFyZ2V0cy5oYXMobmFtZSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5cbmZ1bmN0aW9uIFZhbHVlUHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgdmFsdWVEZWZpbml0aW9uUGFpcnMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNPYmplY3RQYWlycyhjb25zdHJ1Y3RvciwgXCJ2YWx1ZXNcIik7XG4gICAgY29uc3QgcHJvcGVydHlEZXNjcmlwdG9yTWFwID0ge1xuICAgICAgICB2YWx1ZURlc2NyaXB0b3JNYXA6IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdmFsdWVEZWZpbml0aW9uUGFpcnMucmVkdWNlKChyZXN1bHQsIHZhbHVlRGVmaW5pdGlvblBhaXIpID0+IHtcbiAgICAgICAgICAgICAgICAgICAgY29uc3QgdmFsdWVEZXNjcmlwdG9yID0gcGFyc2VWYWx1ZURlZmluaXRpb25QYWlyKHZhbHVlRGVmaW5pdGlvblBhaXIsIHRoaXMuaWRlbnRpZmllcik7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLmRhdGEuZ2V0QXR0cmlidXRlTmFtZUZvcktleSh2YWx1ZURlc2NyaXB0b3Iua2V5KTtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24ocmVzdWx0LCB7IFthdHRyaWJ1dGVOYW1lXTogdmFsdWVEZXNjcmlwdG9yIH0pO1xuICAgICAgICAgICAgICAgIH0sIHt9KTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbiAgICByZXR1cm4gdmFsdWVEZWZpbml0aW9uUGFpcnMucmVkdWNlKChwcm9wZXJ0aWVzLCB2YWx1ZURlZmluaXRpb25QYWlyKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JWYWx1ZURlZmluaXRpb25QYWlyKHZhbHVlRGVmaW5pdGlvblBhaXIpKTtcbiAgICB9LCBwcm9wZXJ0eURlc2NyaXB0b3JNYXApO1xufVxuZnVuY3Rpb24gcHJvcGVydGllc0ZvclZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpciwgY29udHJvbGxlcikge1xuICAgIGNvbnN0IGRlZmluaXRpb24gPSBwYXJzZVZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpciwgY29udHJvbGxlcik7XG4gICAgY29uc3QgeyBrZXksIG5hbWUsIHJlYWRlcjogcmVhZCwgd3JpdGVyOiB3cml0ZSB9ID0gZGVmaW5pdGlvbjtcbiAgICByZXR1cm4ge1xuICAgICAgICBbbmFtZV06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCB2YWx1ZSA9IHRoaXMuZGF0YS5nZXQoa2V5KTtcbiAgICAgICAgICAgICAgICBpZiAodmFsdWUgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIHJlYWQodmFsdWUpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGRlZmluaXRpb24uZGVmYXVsdFZhbHVlO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBzZXQodmFsdWUpIHtcbiAgICAgICAgICAgICAgICBpZiAodmFsdWUgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRhdGEuZGVsZXRlKGtleSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRhdGEuc2V0KGtleSwgd3JpdGUodmFsdWUpKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYGhhcyR7Y2FwaXRhbGl6ZShuYW1lKX1gXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLmRhdGEuaGFzKGtleSkgfHwgZGVmaW5pdGlvbi5oYXNDdXN0b21EZWZhdWx0VmFsdWU7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5mdW5jdGlvbiBwYXJzZVZhbHVlRGVmaW5pdGlvblBhaXIoW3Rva2VuLCB0eXBlRGVmaW5pdGlvbl0sIGNvbnRyb2xsZXIpIHtcbiAgICByZXR1cm4gdmFsdWVEZXNjcmlwdG9yRm9yVG9rZW5BbmRUeXBlRGVmaW5pdGlvbih7XG4gICAgICAgIGNvbnRyb2xsZXIsXG4gICAgICAgIHRva2VuLFxuICAgICAgICB0eXBlRGVmaW5pdGlvbixcbiAgICB9KTtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQoY29uc3RhbnQpIHtcbiAgICBzd2l0Y2ggKGNvbnN0YW50KSB7XG4gICAgICAgIGNhc2UgQXJyYXk6XG4gICAgICAgICAgICByZXR1cm4gXCJhcnJheVwiO1xuICAgICAgICBjYXNlIEJvb2xlYW46XG4gICAgICAgICAgICByZXR1cm4gXCJib29sZWFuXCI7XG4gICAgICAgIGNhc2UgTnVtYmVyOlxuICAgICAgICAgICAgcmV0dXJuIFwibnVtYmVyXCI7XG4gICAgICAgIGNhc2UgT2JqZWN0OlxuICAgICAgICAgICAgcmV0dXJuIFwib2JqZWN0XCI7XG4gICAgICAgIGNhc2UgU3RyaW5nOlxuICAgICAgICAgICAgcmV0dXJuIFwic3RyaW5nXCI7XG4gICAgfVxufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KGRlZmF1bHRWYWx1ZSkge1xuICAgIHN3aXRjaCAodHlwZW9mIGRlZmF1bHRWYWx1ZSkge1xuICAgICAgICBjYXNlIFwiYm9vbGVhblwiOlxuICAgICAgICAgICAgcmV0dXJuIFwiYm9vbGVhblwiO1xuICAgICAgICBjYXNlIFwibnVtYmVyXCI6XG4gICAgICAgICAgICByZXR1cm4gXCJudW1iZXJcIjtcbiAgICAgICAgY2FzZSBcInN0cmluZ1wiOlxuICAgICAgICAgICAgcmV0dXJuIFwic3RyaW5nXCI7XG4gICAgfVxuICAgIGlmIChBcnJheS5pc0FycmF5KGRlZmF1bHRWYWx1ZSkpXG4gICAgICAgIHJldHVybiBcImFycmF5XCI7XG4gICAgaWYgKE9iamVjdC5wcm90b3R5cGUudG9TdHJpbmcuY2FsbChkZWZhdWx0VmFsdWUpID09PSBcIltvYmplY3QgT2JqZWN0XVwiKVxuICAgICAgICByZXR1cm4gXCJvYmplY3RcIjtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVUeXBlT2JqZWN0KHBheWxvYWQpIHtcbiAgICBjb25zdCB7IGNvbnRyb2xsZXIsIHRva2VuLCB0eXBlT2JqZWN0IH0gPSBwYXlsb2FkO1xuICAgIGNvbnN0IGhhc1R5cGUgPSBpc1NvbWV0aGluZyh0eXBlT2JqZWN0LnR5cGUpO1xuICAgIGNvbnN0IGhhc0RlZmF1bHQgPSBpc1NvbWV0aGluZyh0eXBlT2JqZWN0LmRlZmF1bHQpO1xuICAgIGNvbnN0IGZ1bGxPYmplY3QgPSBoYXNUeXBlICYmIGhhc0RlZmF1bHQ7XG4gICAgY29uc3Qgb25seVR5cGUgPSBoYXNUeXBlICYmICFoYXNEZWZhdWx0O1xuICAgIGNvbnN0IG9ubHlEZWZhdWx0ID0gIWhhc1R5cGUgJiYgaGFzRGVmYXVsdDtcbiAgICBjb25zdCB0eXBlRnJvbU9iamVjdCA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZU9iamVjdC50eXBlKTtcbiAgICBjb25zdCB0eXBlRnJvbURlZmF1bHRWYWx1ZSA9IHBhcnNlVmFsdWVUeXBlRGVmYXVsdChwYXlsb2FkLnR5cGVPYmplY3QuZGVmYXVsdCk7XG4gICAgaWYgKG9ubHlUeXBlKVxuICAgICAgICByZXR1cm4gdHlwZUZyb21PYmplY3Q7XG4gICAgaWYgKG9ubHlEZWZhdWx0KVxuICAgICAgICByZXR1cm4gdHlwZUZyb21EZWZhdWx0VmFsdWU7XG4gICAgaWYgKHR5cGVGcm9tT2JqZWN0ICE9PSB0eXBlRnJvbURlZmF1bHRWYWx1ZSkge1xuICAgICAgICBjb25zdCBwcm9wZXJ0eVBhdGggPSBjb250cm9sbGVyID8gYCR7Y29udHJvbGxlcn0uJHt0b2tlbn1gIDogdG9rZW47XG4gICAgICAgIHRocm93IG5ldyBFcnJvcihgVGhlIHNwZWNpZmllZCBkZWZhdWx0IHZhbHVlIGZvciB0aGUgU3RpbXVsdXMgVmFsdWUgXCIke3Byb3BlcnR5UGF0aH1cIiBtdXN0IG1hdGNoIHRoZSBkZWZpbmVkIHR5cGUgXCIke3R5cGVGcm9tT2JqZWN0fVwiLiBUaGUgcHJvdmlkZWQgZGVmYXVsdCB2YWx1ZSBvZiBcIiR7dHlwZU9iamVjdC5kZWZhdWx0fVwiIGlzIG9mIHR5cGUgXCIke3R5cGVGcm9tRGVmYXVsdFZhbHVlfVwiLmApO1xuICAgIH1cbiAgICBpZiAoZnVsbE9iamVjdClcbiAgICAgICAgcmV0dXJuIHR5cGVGcm9tT2JqZWN0O1xufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVEZWZpbml0aW9uKHBheWxvYWQpIHtcbiAgICBjb25zdCB7IGNvbnRyb2xsZXIsIHRva2VuLCB0eXBlRGVmaW5pdGlvbiB9ID0gcGF5bG9hZDtcbiAgICBjb25zdCB0eXBlT2JqZWN0ID0geyBjb250cm9sbGVyLCB0b2tlbiwgdHlwZU9iamVjdDogdHlwZURlZmluaXRpb24gfTtcbiAgICBjb25zdCB0eXBlRnJvbU9iamVjdCA9IHBhcnNlVmFsdWVUeXBlT2JqZWN0KHR5cGVPYmplY3QpO1xuICAgIGNvbnN0IHR5cGVGcm9tRGVmYXVsdFZhbHVlID0gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KHR5cGVEZWZpbml0aW9uKTtcbiAgICBjb25zdCB0eXBlRnJvbUNvbnN0YW50ID0gcGFyc2VWYWx1ZVR5cGVDb25zdGFudCh0eXBlRGVmaW5pdGlvbik7XG4gICAgY29uc3QgdHlwZSA9IHR5cGVGcm9tT2JqZWN0IHx8IHR5cGVGcm9tRGVmYXVsdFZhbHVlIHx8IHR5cGVGcm9tQ29uc3RhbnQ7XG4gICAgaWYgKHR5cGUpXG4gICAgICAgIHJldHVybiB0eXBlO1xuICAgIGNvbnN0IHByb3BlcnR5UGF0aCA9IGNvbnRyb2xsZXIgPyBgJHtjb250cm9sbGVyfS4ke3R5cGVEZWZpbml0aW9ufWAgOiB0b2tlbjtcbiAgICB0aHJvdyBuZXcgRXJyb3IoYFVua25vd24gdmFsdWUgdHlwZSBcIiR7cHJvcGVydHlQYXRofVwiIGZvciBcIiR7dG9rZW59XCIgdmFsdWVgKTtcbn1cbmZ1bmN0aW9uIGRlZmF1bHRWYWx1ZUZvckRlZmluaXRpb24odHlwZURlZmluaXRpb24pIHtcbiAgICBjb25zdCBjb25zdGFudCA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZURlZmluaXRpb24pO1xuICAgIGlmIChjb25zdGFudClcbiAgICAgICAgcmV0dXJuIGRlZmF1bHRWYWx1ZXNCeVR5cGVbY29uc3RhbnRdO1xuICAgIGNvbnN0IGhhc0RlZmF1bHQgPSBoYXNQcm9wZXJ0eSh0eXBlRGVmaW5pdGlvbiwgXCJkZWZhdWx0XCIpO1xuICAgIGNvbnN0IGhhc1R5cGUgPSBoYXNQcm9wZXJ0eSh0eXBlRGVmaW5pdGlvbiwgXCJ0eXBlXCIpO1xuICAgIGNvbnN0IHR5cGVPYmplY3QgPSB0eXBlRGVmaW5pdGlvbjtcbiAgICBpZiAoaGFzRGVmYXVsdClcbiAgICAgICAgcmV0dXJuIHR5cGVPYmplY3QuZGVmYXVsdDtcbiAgICBpZiAoaGFzVHlwZSkge1xuICAgICAgICBjb25zdCB7IHR5cGUgfSA9IHR5cGVPYmplY3Q7XG4gICAgICAgIGNvbnN0IGNvbnN0YW50RnJvbVR5cGUgPSBwYXJzZVZhbHVlVHlwZUNvbnN0YW50KHR5cGUpO1xuICAgICAgICBpZiAoY29uc3RhbnRGcm9tVHlwZSlcbiAgICAgICAgICAgIHJldHVybiBkZWZhdWx0VmFsdWVzQnlUeXBlW2NvbnN0YW50RnJvbVR5cGVdO1xuICAgIH1cbiAgICByZXR1cm4gdHlwZURlZmluaXRpb247XG59XG5mdW5jdGlvbiB2YWx1ZURlc2NyaXB0b3JGb3JUb2tlbkFuZFR5cGVEZWZpbml0aW9uKHBheWxvYWQpIHtcbiAgICBjb25zdCB7IHRva2VuLCB0eXBlRGVmaW5pdGlvbiB9ID0gcGF5bG9hZDtcbiAgICBjb25zdCBrZXkgPSBgJHtkYXNoZXJpemUodG9rZW4pfS12YWx1ZWA7XG4gICAgY29uc3QgdHlwZSA9IHBhcnNlVmFsdWVUeXBlRGVmaW5pdGlvbihwYXlsb2FkKTtcbiAgICByZXR1cm4ge1xuICAgICAgICB0eXBlLFxuICAgICAgICBrZXksXG4gICAgICAgIG5hbWU6IGNhbWVsaXplKGtleSksXG4gICAgICAgIGdldCBkZWZhdWx0VmFsdWUoKSB7XG4gICAgICAgICAgICByZXR1cm4gZGVmYXVsdFZhbHVlRm9yRGVmaW5pdGlvbih0eXBlRGVmaW5pdGlvbik7XG4gICAgICAgIH0sXG4gICAgICAgIGdldCBoYXNDdXN0b21EZWZhdWx0VmFsdWUoKSB7XG4gICAgICAgICAgICByZXR1cm4gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KHR5cGVEZWZpbml0aW9uKSAhPT0gdW5kZWZpbmVkO1xuICAgICAgICB9LFxuICAgICAgICByZWFkZXI6IHJlYWRlcnNbdHlwZV0sXG4gICAgICAgIHdyaXRlcjogd3JpdGVyc1t0eXBlXSB8fCB3cml0ZXJzLmRlZmF1bHQsXG4gICAgfTtcbn1cbmNvbnN0IGRlZmF1bHRWYWx1ZXNCeVR5cGUgPSB7XG4gICAgZ2V0IGFycmF5KCkge1xuICAgICAgICByZXR1cm4gW107XG4gICAgfSxcbiAgICBib29sZWFuOiBmYWxzZSxcbiAgICBudW1iZXI6IDAsXG4gICAgZ2V0IG9iamVjdCgpIHtcbiAgICAgICAgcmV0dXJuIHt9O1xuICAgIH0sXG4gICAgc3RyaW5nOiBcIlwiLFxufTtcbmNvbnN0IHJlYWRlcnMgPSB7XG4gICAgYXJyYXkodmFsdWUpIHtcbiAgICAgICAgY29uc3QgYXJyYXkgPSBKU09OLnBhcnNlKHZhbHVlKTtcbiAgICAgICAgaWYgKCFBcnJheS5pc0FycmF5KGFycmF5KSkge1xuICAgICAgICAgICAgdGhyb3cgbmV3IFR5cGVFcnJvcihgZXhwZWN0ZWQgdmFsdWUgb2YgdHlwZSBcImFycmF5XCIgYnV0IGluc3RlYWQgZ290IHZhbHVlIFwiJHt2YWx1ZX1cIiBvZiB0eXBlIFwiJHtwYXJzZVZhbHVlVHlwZURlZmF1bHQoYXJyYXkpfVwiYCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGFycmF5O1xuICAgIH0sXG4gICAgYm9vbGVhbih2YWx1ZSkge1xuICAgICAgICByZXR1cm4gISh2YWx1ZSA9PSBcIjBcIiB8fCBTdHJpbmcodmFsdWUpLnRvTG93ZXJDYXNlKCkgPT0gXCJmYWxzZVwiKTtcbiAgICB9LFxuICAgIG51bWJlcih2YWx1ZSkge1xuICAgICAgICByZXR1cm4gTnVtYmVyKHZhbHVlLnJlcGxhY2UoL18vZywgXCJcIikpO1xuICAgIH0sXG4gICAgb2JqZWN0KHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IG9iamVjdCA9IEpTT04ucGFyc2UodmFsdWUpO1xuICAgICAgICBpZiAob2JqZWN0ID09PSBudWxsIHx8IHR5cGVvZiBvYmplY3QgIT0gXCJvYmplY3RcIiB8fCBBcnJheS5pc0FycmF5KG9iamVjdCkpIHtcbiAgICAgICAgICAgIHRocm93IG5ldyBUeXBlRXJyb3IoYGV4cGVjdGVkIHZhbHVlIG9mIHR5cGUgXCJvYmplY3RcIiBidXQgaW5zdGVhZCBnb3QgdmFsdWUgXCIke3ZhbHVlfVwiIG9mIHR5cGUgXCIke3BhcnNlVmFsdWVUeXBlRGVmYXVsdChvYmplY3QpfVwiYCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIG9iamVjdDtcbiAgICB9LFxuICAgIHN0cmluZyh2YWx1ZSkge1xuICAgICAgICByZXR1cm4gdmFsdWU7XG4gICAgfSxcbn07XG5jb25zdCB3cml0ZXJzID0ge1xuICAgIGRlZmF1bHQ6IHdyaXRlU3RyaW5nLFxuICAgIGFycmF5OiB3cml0ZUpTT04sXG4gICAgb2JqZWN0OiB3cml0ZUpTT04sXG59O1xuZnVuY3Rpb24gd3JpdGVKU09OKHZhbHVlKSB7XG4gICAgcmV0dXJuIEpTT04uc3RyaW5naWZ5KHZhbHVlKTtcbn1cbmZ1bmN0aW9uIHdyaXRlU3RyaW5nKHZhbHVlKSB7XG4gICAgcmV0dXJuIGAke3ZhbHVlfWA7XG59XG5cbmNsYXNzIENvbnRyb2xsZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICB9XG4gICAgc3RhdGljIGdldCBzaG91bGRMb2FkKCkge1xuICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICB9XG4gICAgc3RhdGljIGFmdGVyTG9hZChfaWRlbnRpZmllciwgX2FwcGxpY2F0aW9uKSB7XG4gICAgICAgIHJldHVybjtcbiAgICB9XG4gICAgZ2V0IGFwcGxpY2F0aW9uKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgc2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NvcGU7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHRhcmdldHMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLnRhcmdldHM7XG4gICAgfVxuICAgIGdldCBvdXRsZXRzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5vdXRsZXRzO1xuICAgIH1cbiAgICBnZXQgY2xhc3NlcygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuY2xhc3NlcztcbiAgICB9XG4gICAgZ2V0IGRhdGEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmRhdGE7XG4gICAgfVxuICAgIGluaXRpYWxpemUoKSB7XG4gICAgfVxuICAgIGNvbm5lY3QoKSB7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3QoKSB7XG4gICAgfVxuICAgIGRpc3BhdGNoKGV2ZW50TmFtZSwgeyB0YXJnZXQgPSB0aGlzLmVsZW1lbnQsIGRldGFpbCA9IHt9LCBwcmVmaXggPSB0aGlzLmlkZW50aWZpZXIsIGJ1YmJsZXMgPSB0cnVlLCBjYW5jZWxhYmxlID0gdHJ1ZSwgfSA9IHt9KSB7XG4gICAgICAgIGNvbnN0IHR5cGUgPSBwcmVmaXggPyBgJHtwcmVmaXh9OiR7ZXZlbnROYW1lfWAgOiBldmVudE5hbWU7XG4gICAgICAgIGNvbnN0IGV2ZW50ID0gbmV3IEN1c3RvbUV2ZW50KHR5cGUsIHsgZGV0YWlsLCBidWJibGVzLCBjYW5jZWxhYmxlIH0pO1xuICAgICAgICB0YXJnZXQuZGlzcGF0Y2hFdmVudChldmVudCk7XG4gICAgICAgIHJldHVybiBldmVudDtcbiAgICB9XG59XG5Db250cm9sbGVyLmJsZXNzaW5ncyA9IFtcbiAgICBDbGFzc1Byb3BlcnRpZXNCbGVzc2luZyxcbiAgICBUYXJnZXRQcm9wZXJ0aWVzQmxlc3NpbmcsXG4gICAgVmFsdWVQcm9wZXJ0aWVzQmxlc3NpbmcsXG4gICAgT3V0bGV0UHJvcGVydGllc0JsZXNzaW5nLFxuXTtcbkNvbnRyb2xsZXIudGFyZ2V0cyA9IFtdO1xuQ29udHJvbGxlci5vdXRsZXRzID0gW107XG5Db250cm9sbGVyLnZhbHVlcyA9IHt9O1xuXG5leHBvcnQgeyBBcHBsaWNhdGlvbiwgQXR0cmlidXRlT2JzZXJ2ZXIsIENvbnRleHQsIENvbnRyb2xsZXIsIEVsZW1lbnRPYnNlcnZlciwgSW5kZXhlZE11bHRpbWFwLCBNdWx0aW1hcCwgU2VsZWN0b3JPYnNlcnZlciwgU3RyaW5nTWFwT2JzZXJ2ZXIsIFRva2VuTGlzdE9ic2VydmVyLCBWYWx1ZUxpc3RPYnNlcnZlciwgYWRkLCBkZWZhdWx0U2NoZW1hLCBkZWwsIGZldGNoLCBwcnVuZSB9O1xuIiwgImltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tIFwiQGhvdHdpcmVkL3N0aW11bHVzXCJcblxuLyoqXG4gKiBTdGltdWx1cyBDb250cm9sbGVyIGZvciBcIkJ1eSBOb3dcIiBidXR0b25cbiAqXG4gKiBIYW5kbGVzIGRpcmVjdCBwcm9kdWN0LXRvLWNoZWNrb3V0IGZsb3dcbiAqXG4gKiBVc2FnZSBpbiBUd2lnOlxuICogPGRpdiBkYXRhLWNvbnRyb2xsZXI9XCJidXktbm93XCJcbiAqICAgICAgZGF0YS1idXktbm93LXByb2R1Y3QtaWQtdmFsdWU9XCIuLi5cIlxuICogICAgICBkYXRhLWJ1eS1ub3ctcHJvZHVjdC1uaWQtdmFsdWU9XCIuLi5cIlxuICogICAgICBkYXRhLWJ1eS1ub3ctcGFyZW50LWlkLXZhbHVlPVwiLi4uXCJcbiAqICAgICAgZGF0YS1idXktbm93LWFjdGlvbi11cmwtdmFsdWU9XCIuLi5cIlxuICogICAgICBkYXRhLWJ1eS1ub3ctY3NyZi10b2tlbi12YWx1ZT1cIi4uLlwiPlxuICogICA8YnV0dG9uIGRhdGEtYWN0aW9uPVwiYnV5LW5vdyNzdWJtaXRcIj5CdXkgTm93PC9idXR0b24+XG4gKiA8L2Rpdj5cbiAqL1xuZXhwb3J0IGRlZmF1bHQgY2xhc3MgZXh0ZW5kcyBDb250cm9sbGVyIHtcbiAgc3RhdGljIHZhbHVlcyA9IHtcbiAgICBwcm9kdWN0SWQ6IFN0cmluZyxcbiAgICBwcm9kdWN0TmlkOiBTdHJpbmcsXG4gICAgcGFyZW50SWQ6IFN0cmluZyxcbiAgICBhY3Rpb25Vcmw6IFN0cmluZyxcbiAgICBjc3JmVG9rZW46IFN0cmluZ1xuICB9XG5cbiAgc3RhdGljIHRhcmdldHMgPSBbXCJidXR0b25cIl1cblxuICBjb25uZWN0KCkge1xuICAgIGNvbnNvbGUubG9nKCdTdHJpcGUgQnV5IE5vdyBjb250cm9sbGVyIGNvbm5lY3RlZCcsIHtcbiAgICAgIHByb2R1Y3RJZDogdGhpcy5wcm9kdWN0SWRWYWx1ZSxcbiAgICAgIHByb2R1Y3ROaWQ6IHRoaXMucHJvZHVjdE5pZFZhbHVlXG4gICAgfSlcbiAgfVxuXG4gIC8qKlxuICAgKiBIYW5kbGUgQnV5IE5vdyBidXR0b24gY2xpY2tcbiAgICogQHBhcmFtIHtFdmVudH0gZXZlbnRcbiAgICovXG4gIHN1Ym1pdChldmVudCkge1xuICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KClcblxuICAgIGNvbnNvbGUubG9nKCdCdXkgTm93IGNsaWNrZWQnKVxuXG4gICAgY29uc3QgYnV0dG9uID0gZXZlbnQuY3VycmVudFRhcmdldFxuXG4gICAgLy8gRGlzYWJsZSBidXR0b24gYW5kIHNob3cgbG9hZGluZyBzdGF0ZVxuICAgIHRoaXMuc2V0TG9hZGluZ1N0YXRlKGJ1dHRvbiwgdHJ1ZSlcblxuICAgIC8vIEdldCBxdWFudGl0eSBmcm9tIGFtb3VudCBpbnB1dFxuICAgIGNvbnN0IGFtb3VudElucHV0ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ2Ftb3VudFRvQmFza2V0JylcbiAgICBjb25zdCBhbW91bnQgPSBhbW91bnRJbnB1dCA/IGFtb3VudElucHV0LnZhbHVlIDogMVxuXG4gICAgLy8gR2V0IHByb2R1Y3QgZm9ybSBkYXRhIChmb3IgdmFyaWFudHMsIHNlbGVjdGlvbnMsIGV0Yy4pXG4gICAgY29uc3QgcHJvZHVjdEZvcm0gPSBkb2N1bWVudC5xdWVyeVNlbGVjdG9yKCcuanMtb3hQcm9kdWN0Rm9ybScpXG4gICAgY29uc3QgZm9ybURhdGEgPSBwcm9kdWN0Rm9ybSA/IG5ldyBGb3JtRGF0YShwcm9kdWN0Rm9ybSkgOiBuZXcgRm9ybURhdGEoKVxuXG4gICAgLy8gUHJlcGFyZSBmb3JtIGZpZWxkc1xuICAgIGNvbnN0IGZpZWxkcyA9IHtcbiAgICAgICdjbCc6ICdzdHJpcGVfY2hlY2tvdXRfb25lcGFnZScsXG4gICAgICAnZm5jJzogJ2FkZFByb2R1Y3RBbmRDaGVja291dCcsXG4gICAgICAnYWlkJzogdGhpcy5wcm9kdWN0SWRWYWx1ZSxcbiAgICAgICdhbmlkJzogdGhpcy5wcm9kdWN0TmlkVmFsdWUsXG4gICAgICAncGFyZW50aWQnOiB0aGlzLnBhcmVudElkVmFsdWUsXG4gICAgICAnYW0nOiBhbW91bnQsXG4gICAgICAnc3Rva2VuJzogdGhpcy5jc3JmVG9rZW5WYWx1ZVxuICAgIH1cblxuICAgIC8vIEFkZCB2YXJpYW50IHNlbGVjdGlvbnMgZnJvbSBwcm9kdWN0IGZvcm1cbiAgICBmb3IgKGxldCBba2V5LCB2YWx1ZV0gb2YgZm9ybURhdGEuZW50cmllcygpKSB7XG4gICAgICBpZiAoIWZpZWxkc1trZXldICYmIGtleSAhPT0gJ2ZuYycgJiYga2V5ICE9PSAnY2wnKSB7XG4gICAgICAgIGZpZWxkc1trZXldID0gdmFsdWVcbiAgICAgIH1cbiAgICB9XG5cbiAgICBjb25zb2xlLmxvZygnU3VibWl0dGluZyBCdXkgTm93IGZvcm06JywgZmllbGRzKVxuXG4gICAgLy8gQ3JlYXRlIGFuZCBzdWJtaXQgaGlkZGVuIGZvcm1cbiAgICB0aGlzLnN1Ym1pdEZvcm0oZmllbGRzKVxuICB9XG5cbiAgLyoqXG4gICAqIENyZWF0ZSBoaWRkZW4gZm9ybSBhbmQgc3VibWl0XG4gICAqIEBwYXJhbSB7T2JqZWN0fSBmaWVsZHNcbiAgICovXG4gIHN1Ym1pdEZvcm0oZmllbGRzKSB7XG4gICAgY29uc3QgZm9ybSA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ2Zvcm0nKVxuICAgIGZvcm0ubWV0aG9kID0gJ1BPU1QnXG4gICAgZm9ybS5hY3Rpb24gPSB0aGlzLmFjdGlvblVybFZhbHVlXG4gICAgZm9ybS5zdHlsZS5kaXNwbGF5ID0gJ25vbmUnXG5cbiAgICAvLyBBZGQgYWxsIGZpZWxkcyBhcyBoaWRkZW4gaW5wdXRzXG4gICAgT2JqZWN0LmVudHJpZXMoZmllbGRzKS5mb3JFYWNoKChbbmFtZSwgdmFsdWVdKSA9PiB7XG4gICAgICBjb25zdCBpbnB1dCA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ2lucHV0JylcbiAgICAgIGlucHV0LnR5cGUgPSAnaGlkZGVuJ1xuICAgICAgaW5wdXQubmFtZSA9IG5hbWVcbiAgICAgIGlucHV0LnZhbHVlID0gdmFsdWVcbiAgICAgIGZvcm0uYXBwZW5kQ2hpbGQoaW5wdXQpXG4gICAgfSlcblxuICAgIC8vIEFkZCB0byBET00gYW5kIHN1Ym1pdFxuICAgIGRvY3VtZW50LmJvZHkuYXBwZW5kQ2hpbGQoZm9ybSlcblxuICAgIC8vIFNtYWxsIGRlbGF5IHRvIGVuc3VyZSBmb3JtIGlzIGluIERPTVxuICAgIHNldFRpbWVvdXQoKCkgPT4ge1xuICAgICAgZm9ybS5zdWJtaXQoKVxuICAgIH0sIDEwMClcbiAgfVxuXG4gIC8qKlxuICAgKiBTZXQgYnV0dG9uIGxvYWRpbmcgc3RhdGVcbiAgICogQHBhcmFtIHtIVE1MRWxlbWVudH0gYnV0dG9uXG4gICAqIEBwYXJhbSB7Qm9vbGVhbn0gaXNMb2FkaW5nXG4gICAqL1xuICBzZXRMb2FkaW5nU3RhdGUoYnV0dG9uLCBpc0xvYWRpbmcpIHtcbiAgICBpZiAoaXNMb2FkaW5nKSB7XG4gICAgICAvLyBTdG9yZSBvcmlnaW5hbCBIVE1MXG4gICAgICBidXR0b24uZGF0YXNldC5vcmlnaW5hbEh0bWwgPSBidXR0b24uaW5uZXJIVE1MXG5cbiAgICAgIC8vIFNldCBsb2FkaW5nIHN0YXRlXG4gICAgICBidXR0b24uZGlzYWJsZWQgPSB0cnVlXG4gICAgICBidXR0b24uaW5uZXJIVE1MID0gYFxuICAgICAgICA8c3BhbiBjbGFzcz1cInNwaW5uZXItYm9yZGVyIHNwaW5uZXItYm9yZGVyLXNtIG1lLTJcIiByb2xlPVwic3RhdHVzXCIgYXJpYS1oaWRkZW49XCJ0cnVlXCI+PC9zcGFuPlxuICAgICAgICBQcm9jZXNzaW5nLi4uXG4gICAgICBgXG4gICAgfSBlbHNlIHtcbiAgICAgIC8vIFJlc3RvcmUgb3JpZ2luYWwgc3RhdGVcbiAgICAgIGJ1dHRvbi5kaXNhYmxlZCA9IGZhbHNlXG4gICAgICBpZiAoYnV0dG9uLmRhdGFzZXQub3JpZ2luYWxIdG1sKSB7XG4gICAgICAgIGJ1dHRvbi5pbm5lckhUTUwgPSBidXR0b24uZGF0YXNldC5vcmlnaW5hbEh0bWxcbiAgICAgIH1cbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIGVycm9yc1xuICAgKiBAcGFyYW0ge0Vycm9yfSBlcnJvclxuICAgKi9cbiAgaGFuZGxlRXJyb3IoZXJyb3IpIHtcbiAgICBjb25zb2xlLmVycm9yKCdCdXkgTm93IGVycm9yOicsIGVycm9yKVxuXG4gICAgLy8gU2hvdyBlcnJvciB0byB1c2VyXG4gICAgYWxlcnQoJ1NvcnJ5LCB0aGVyZSB3YXMgYW4gZXJyb3IgcHJvY2Vzc2luZyB5b3VyIHJlcXVlc3QuIFBsZWFzZSB0cnkgYWdhaW4uJylcblxuICAgIC8vIFJlc2V0IGJ1dHRvbiBzdGF0ZVxuICAgIGlmICh0aGlzLmhhc0J1dHRvblRhcmdldCkge1xuICAgICAgdGhpcy5zZXRMb2FkaW5nU3RhdGUodGhpcy5idXR0b25UYXJnZXQsIGZhbHNlKVxuICAgIH1cbiAgfVxufVxuIiwgImltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tIFwiQGhvdHdpcmVkL3N0aW11bHVzXCJcblxuLyoqXG4gKiBTdGltdWx1cyBDb250cm9sbGVyIGZvciBTdHJpcGUgUGF5bWVudCBFbGVtZW50IG9uIE9yZGVyIFBhZ2VcbiAqXG4gKiBIYW5kbGVzIFN0cmlwZSBwYXltZW50IGZvcm0gaW5pdGlhbGl6YXRpb24gYW5kIHN1Ym1pc3Npb24gb24gdGhlIG9yZGVyIGNvbmZpcm1hdGlvbiBwYWdlXG4gKlxuICogVXNhZ2UgaW4gVHdpZzpcbiAqIDxkaXYgZGF0YS1jb250cm9sbGVyPVwic3RyaXBlLW9yZGVyXCJcbiAqICAgICAgZGF0YS1zdHJpcGUtb3JkZXItcHVibGlzaGFibGUta2V5LXZhbHVlPVwicGtfLi4uXCJcbiAqICAgICAgZGF0YS1zdHJpcGUtb3JkZXItY2xpZW50LXNlY3JldC12YWx1ZT1cInBpXy4uLl9zZWNyZXRfLi4uXCI+XG4gKiAgIDxkaXYgaWQ9XCJwYXltZW50LWVsZW1lbnRcIj48L2Rpdj5cbiAqICAgPGRpdiBpZD1cInBheW1lbnQtZXJyb3JzXCIgc3R5bGU9XCJkaXNwbGF5Om5vbmVcIj5cbiAqICAgICA8c3BhbiBkYXRhLXN0cmlwZS1vcmRlci10YXJnZXQ9XCJlcnJvck1lc3NhZ2VcIj48L3NwYW4+XG4gKiAgIDwvZGl2PlxuICogPC9kaXY+XG4gKi9cbmV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgQ29udHJvbGxlciB7XG4gIHN0YXRpYyB2YWx1ZXMgPSB7XG4gICAgcHVibGlzaGFibGVLZXk6IFN0cmluZyxcbiAgICBjbGllbnRTZWNyZXQ6IFN0cmluZ1xuICB9XG5cbiAgc3RhdGljIHRhcmdldHMgPSBbXCJlcnJvck1lc3NhZ2VcIiwgXCJsb2FkaW5nXCJdXG5cbiAgY29ubmVjdCgpIHtcbiAgICBjb25zb2xlLmxvZygnU3RyaXBlIE9yZGVyIGNvbnRyb2xsZXIgY29ubmVjdGVkJywge1xuICAgICAgaGFzUHVibGlzaGFibGVLZXk6ICEhdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlLFxuICAgICAgaGFzQ2xpZW50U2VjcmV0OiAhIXRoaXMuY2xpZW50U2VjcmV0VmFsdWUsXG4gICAgICBwdWJsaXNoYWJsZUtleTogdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlID8gdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlLnN1YnN0cmluZygwLCAxMCkgKyAnLi4uJyA6ICdtaXNzaW5nJyxcbiAgICAgIGNsaWVudFNlY3JldExlbmd0aDogdGhpcy5jbGllbnRTZWNyZXRWYWx1ZSA/IHRoaXMuY2xpZW50U2VjcmV0VmFsdWUubGVuZ3RoIDogMFxuICAgIH0pXG5cbiAgICAvLyBHZXQgZGVidWcgaW5mbyBmcm9tIGVsZW1lbnRcbiAgICBjb25zdCBkZWJ1Z0luZm8gPSB0aGlzLmVsZW1lbnQuZ2V0QXR0cmlidXRlKCdkYXRhLWRlYnVnLWluZm8nKVxuICAgIGlmIChkZWJ1Z0luZm8pIHtcbiAgICAgIGNvbnNvbGUubG9nKCdEZWJ1ZyBpbmZvOicsIGRlYnVnSW5mbylcbiAgICB9XG5cbiAgICAvLyBWYWxpZGF0ZSByZXF1aXJlZCBjb25maWd1cmF0aW9uXG4gICAgaWYgKCF0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ1N0cmlwZSBwdWJsaXNoYWJsZSBrZXkgbm90IGNvbmZpZ3VyZWQnKVxuICAgICAgdGhpcy5zaG93RXJyb3IoJ1N0cmlwZSBjb25maWd1cmF0aW9uIGVycm9yLiBQbGVhc2UgY29udGFjdCBzdXBwb3J0LicpXG4gICAgICByZXR1cm5cbiAgICB9XG4vKlxuICAgIGlmICghdGhpcy5jbGllbnRTZWNyZXRWYWx1ZSkge1xuICAgICAgY29uc29sZS53YXJuKCdcdTI2QTBcdUZFMEYgU3RyaXBlIGNsaWVudCBzZWNyZXQgbm90IGF2YWlsYWJsZScsIHtcbiAgICAgICAgbWVzc2FnZTogJ1RoZSBiYWNrZW5kIGRpZCBub3QgZ2VuZXJhdGUgYSBQYXltZW50SW50ZW50IGNsaWVudCBzZWNyZXQuJyxcbiAgICAgICAgcG9zc2libGVSZWFzb25zOiBbXG4gICAgICAgICAgJzEuIFBheW1lbnQgbWV0aG9kIG5vdCBkZXRlY3RlZCBhcyBTdHJpcGUgKGNoZWNrIHBheW1lbnQgSUQgPSBvc2Nfc3RyaXBlX2NhcmQpJyxcbiAgICAgICAgICAnMi4gVXNlciBub3QgbG9nZ2VkIGluIG9yIHNlc3Npb24gaXNzdWUnLFxuICAgICAgICAgICczLiBCYWNrZW5kIGVycm9yIGNyZWF0aW5nIFBheW1lbnRJbnRlbnQgKGNoZWNrIFBIUCBsb2dzKScsXG4gICAgICAgICAgJzQuIFN0cmlwZVBheW1lbnRTZXJ2aWNlIG5vdCBwcm9wZXJseSBjb25maWd1cmVkJ1xuICAgICAgICBdLFxuICAgICAgICBuZXh0U3RlcHM6ICdDaGVjayBicm93c2VyIE5ldHdvcmsgdGFiIGFuZCBQSFAgZXJyb3IgbG9ncydcbiAgICAgIH0pXG5cbiAgICAgIC8vIFNob3cgdXNlci1mcmllbmRseSBtZXNzYWdlXG4gICAgICB0aGlzLnNob3dFcnJvcignUGF5bWVudCBpbml0aWFsaXphdGlvbiBmYWlsZWQuIFBsZWFzZSByZWZyZXNoIHRoZSBwYWdlIG9yIGNvbnRhY3Qgc3VwcG9ydC4nKVxuICAgICAgcmV0dXJuXG4gICAgfSovXG5cbiAgICAvLyBXYWl0IGZvciBTdHJpcGUuanMgdG8gbG9hZFxuICAgIHRoaXMuaW5pdGlhbGl6ZVN0cmlwZSgpXG4gIH1cblxuICBkaXNjb25uZWN0KCkge1xuICAgIC8vIENsZWFudXAgaWYgbmVlZGVkXG4gICAgaWYgKHRoaXMucGF5bWVudEVsZW1lbnQpIHtcbiAgICAgIHRoaXMucGF5bWVudEVsZW1lbnQudW5tb3VudCgpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEluaXRpYWxpemUgU3RyaXBlIGFuZCBtb3VudCBQYXltZW50IEVsZW1lbnRcbiAgICovXG4gIGFzeW5jIGluaXRpYWxpemVTdHJpcGUoKSB7XG4gICAgLy8gV2FpdCBmb3IgU3RyaXBlLmpzIHRvIGJlIGF2YWlsYWJsZVxuICAgIGlmICh0eXBlb2YgU3RyaXBlID09PSAndW5kZWZpbmVkJykge1xuICAgICAgY29uc29sZS5sb2coJ1dhaXRpbmcgZm9yIFN0cmlwZS5qcyB0byBsb2FkLi4uJylcbiAgICAgIGF3YWl0IHRoaXMud2FpdEZvclN0cmlwZSgpXG4gICAgfVxuXG4gICAgdHJ5IHtcbiAgICAgIC8vIEluaXRpYWxpemUgU3RyaXBlXG4gICAgICB0aGlzLnN0cmlwZSA9IFN0cmlwZSh0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUpXG5cbiAgICAgIC8vIENyZWF0ZSBFbGVtZW50cyB3aXRoIHN0eWxpbmdcbiAgICAgIGNvbnN0IGFwcGVhcmFuY2UgPSB7XG4gICAgICAgIHRoZW1lOiAnc3RyaXBlJyxcbiAgICAgICAgdmFyaWFibGVzOiB7XG4gICAgICAgICAgY29sb3JQcmltYXJ5OiAnIzA1NzBkZScsXG4gICAgICAgICAgY29sb3JCYWNrZ3JvdW5kOiAnI2ZmZmZmZicsXG4gICAgICAgICAgY29sb3JUZXh0OiAnIzMwMzEzZCcsXG4gICAgICAgICAgZm9udEZhbWlseTogJ3N5c3RlbS11aSwgc2Fucy1zZXJpZicsXG4gICAgICAgICAgYm9yZGVyUmFkaXVzOiAnNHB4J1xuICAgICAgICB9XG4gICAgICB9XG5cbiAgICAgIHRoaXMuZWxlbWVudHMgPSB0aGlzLnN0cmlwZS5lbGVtZW50cyh7XG4gICAgICAgIGFwcGVhcmFuY2U6IGFwcGVhcmFuY2VcbiAgICAgIH0pXG5cbiAgICAgIGNvbnN0IGNhcmQgPSB0aGlzLmVsZW1lbnRzLmNyZWF0ZSgnY2FyZCcpO1xuICAgICAgY2FyZC5tb3VudCgnI2NhcmQtZWxlbWVudCcpO1xuXG4gICAgICBjb25zb2xlLmxvZygnU3RyaXBlIFBheW1lbnQgRWxlbWVudCBpbml0aWFsaXplZCBzdWNjZXNzZnVsbHknKVxuXG4gICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ0ZhaWxlZCB0byBpbml0aWFsaXplIFN0cmlwZTonLCBlcnJvcilcbiAgICAgIHRoaXMuc2hvd0Vycm9yKCdGYWlsZWQgdG8gaW5pdGlhbGl6ZSBwYXltZW50IGZvcm0uIFBsZWFzZSByZWZyZXNoIHRoZSBwYWdlLicpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFdhaXQgZm9yIFN0cmlwZS5qcyBsaWJyYXJ5IHRvIGxvYWRcbiAgICogQHJldHVybnMge1Byb21pc2V9XG4gICAqL1xuICB3YWl0Rm9yU3RyaXBlKCkge1xuICAgIHJldHVybiBuZXcgUHJvbWlzZSgocmVzb2x2ZSkgPT4ge1xuICAgICAgY29uc3QgY2hlY2tTdHJpcGUgPSAoKSA9PiB7XG4gICAgICAgIGlmICh0eXBlb2YgU3RyaXBlICE9PSAndW5kZWZpbmVkJykge1xuICAgICAgICAgIHJlc29sdmUoKVxuICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgIHNldFRpbWVvdXQoY2hlY2tTdHJpcGUsIDEwMClcbiAgICAgICAgfVxuICAgICAgfVxuICAgICAgY2hlY2tTdHJpcGUoKVxuICAgIH0pXG4gIH1cblxuICAvKipcbiAgICogU2hvdyBsb2FkaW5nIGluZGljYXRvclxuICAgKi9cbiAgc2hvd0xvYWRpbmcoKSB7XG4gICAgaWYgKHRoaXMuaGFzTG9hZGluZ1RhcmdldCkge1xuICAgICAgdGhpcy5sb2FkaW5nVGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFNob3cgZXJyb3IgbWVzc2FnZVxuICAgKiBAcGFyYW0ge1N0cmluZ30gbWVzc2FnZVxuICAgKi9cbiAgc2hvd0Vycm9yKG1lc3NhZ2UpIHtcbiAgICBjb25zdCBlcnJvckRpdiA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdwYXltZW50LWVycm9ycycpXG4gICAgaWYgKGVycm9yRGl2ICYmIHRoaXMuaGFzRXJyb3JNZXNzYWdlVGFyZ2V0KSB7XG4gICAgICBlcnJvckRpdi5zdHlsZS5kaXNwbGF5ID0gJ2Jsb2NrJ1xuICAgICAgdGhpcy5lcnJvck1lc3NhZ2VUYXJnZXQudGV4dENvbnRlbnQgPSBtZXNzYWdlXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhpZGUgZXJyb3IgbWVzc2FnZVxuICAgKi9cbiAgaGlkZUVycm9yKCkge1xuICAgIGNvbnN0IGVycm9yRGl2ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ3BheW1lbnQtZXJyb3JzJylcbiAgICBpZiAoZXJyb3JEaXYpIHtcbiAgICAgIGVycm9yRGl2LnN0eWxlLmRpc3BsYXkgPSAnbm9uZSdcbiAgICAgIGlmICh0aGlzLmhhc0Vycm9yTWVzc2FnZVRhcmdldCkge1xuICAgICAgICB0aGlzLmVycm9yTWVzc2FnZVRhcmdldC50ZXh0Q29udGVudCA9ICcnXG4gICAgICB9XG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhpZGUgbG9hZGluZyBpbmRpY2F0b3JcbiAgICovXG4gIGhpZGVMb2FkaW5nKCkge1xuICAgIGlmICh0aGlzLmhhc0xvYWRpbmdUYXJnZXQpIHtcbiAgICAgIHRoaXMubG9hZGluZ1RhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ25vbmUnXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEdldCBTdHJpcGUgaW5zdGFuY2UgKGZvciBmb3JtIHN1Ym1pc3Npb24gaGFuZGxlcilcbiAgICogQHJldHVybnMge09iamVjdH0gU3RyaXBlIGluc3RhbmNlXG4gICAqL1xuICBnZXRTdHJpcGUoKSB7XG4gICAgcmV0dXJuIHRoaXMuc3RyaXBlXG4gIH1cblxuICAvKipcbiAgICogR2V0IEVsZW1lbnRzIGluc3RhbmNlIChmb3IgZm9ybSBzdWJtaXNzaW9uIGhhbmRsZXIpXG4gICAqIEByZXR1cm5zIHtPYmplY3R9IEVsZW1lbnRzIGluc3RhbmNlXG4gICAqL1xuICBnZXRFbGVtZW50cygpIHtcbiAgICByZXR1cm4gdGhpcy5lbGVtZW50c1xuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBvcmRlciBmb3JtIHN1Ym1pc3Npb25cbiAgICogVGhpcyBtZXRob2Qgc2hvdWxkIGJlIGNhbGxlZCB3aGVuIHRoZSBvcmRlciBjb25maXJtYXRpb24gYnV0dG9uIGlzIGNsaWNrZWRcbiAgICogQHBhcmFtIHtFdmVudH0gZXZlbnQgLSBGb3JtIHN1Ym1pc3Npb24gZXZlbnRcbiAgICovXG4gIGFzeW5jIGhhbmRsZVBheW1lbnQoZXZlbnQpIHtcbiAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpXG5cbiAgICBpZiAoIXRoaXMuc3RyaXBlIHx8ICF0aGlzLmVsZW1lbnRzKSB7XG4gICAgICB0aGlzLnNob3dFcnJvcignUGF5bWVudCBmb3JtIG5vdCBpbml0aWFsaXplZC4gUGxlYXNlIHJlZnJlc2ggdGhlIHBhZ2UuJylcbiAgICAgIHJldHVyblxuICAgIH1cblxuICAgIHRoaXMuc2hvd0xvYWRpbmcoKVxuICAgIHRoaXMuaGlkZUVycm9yKClcblxuICAgIHRyeSB7XG4gICAgICAvLyBHZXQgdGhlIHJldHVybiBVUkwgZnJvbSBjdXJyZW50IGxvY2F0aW9uXG4gICAgICBjb25zdCBzaG9wVXJsID0gd2luZG93LmxvY2F0aW9uLm9yaWdpbiArIHdpbmRvdy5sb2NhdGlvbi5wYXRobmFtZS5zcGxpdCgnL2luZGV4LnBocCcpWzBdXG4gICAgICBjb25zdCByZXR1cm5VcmwgPSBzaG9wVXJsICsgJy9pbmRleC5waHA/Y2w9b3JkZXImZm5jPXN0cmlwZVJldHVybidcblxuICAgICAgY29uc29sZS5sb2coJ0NvbmZpcm1pbmcgcGF5bWVudCB3aXRoIHJldHVybiBVUkw6JywgcmV0dXJuVXJsKVxuXG4gICAgICAvLyBDb25maXJtIHBheW1lbnQgd2l0aCBTdHJpcGVcbiAgICAgIGNvbnN0IHsgZXJyb3IgfSA9IGF3YWl0IHRoaXMuc3RyaXBlLmNvbmZpcm1QYXltZW50KHtcbiAgICAgICAgZWxlbWVudHM6IHRoaXMuZWxlbWVudHMsXG4gICAgICAgIGNvbmZpcm1QYXJhbXM6IHtcbiAgICAgICAgICByZXR1cm5fdXJsOiByZXR1cm5VcmwsXG4gICAgICAgIH0sXG4gICAgICB9KVxuXG4gICAgICAvLyBUaGlzIGNvZGUgd2lsbCBvbmx5IGV4ZWN1dGUgaWYgdGhlcmUncyBhbiBpbW1lZGlhdGUgZXJyb3JcbiAgICAgIC8vIElmIHBheW1lbnQgc3VjY2VlZHMgb3IgcmVxdWlyZXMgcmVkaXJlY3QsIHVzZXIgd2lsbCBiZSByZWRpcmVjdGVkXG4gICAgICBpZiAoZXJyb3IpIHtcbiAgICAgICAgY29uc29sZS5lcnJvcignUGF5bWVudCBjb25maXJtYXRpb24gZXJyb3I6JywgZXJyb3IpXG5cbiAgICAgICAgLy8gU2hvdyBlcnJvciB0byBjdXN0b21lclxuICAgICAgICBpZiAoZXJyb3IudHlwZSA9PT0gJ2NhcmRfZXJyb3InIHx8IGVycm9yLnR5cGUgPT09ICd2YWxpZGF0aW9uX2Vycm9yJykge1xuICAgICAgICAgIHRoaXMuc2hvd0Vycm9yKGVycm9yLm1lc3NhZ2UpXG4gICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgdGhpcy5zaG93RXJyb3IoJ0FuIHVuZXhwZWN0ZWQgZXJyb3Igb2NjdXJyZWQuIFBsZWFzZSB0cnkgYWdhaW4uJylcbiAgICAgICAgfVxuICAgICAgfVxuXG4gICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ1BheW1lbnQgcHJvY2Vzc2luZyBlcnJvcjonLCBlcnJvcilcbiAgICAgIHRoaXMuc2hvd0Vycm9yKCdQYXltZW50IHByb2Nlc3NpbmcgZmFpbGVkLiBQbGVhc2UgdHJ5IGFnYWluLicpXG4gICAgfSBmaW5hbGx5IHtcbiAgICAgIHRoaXMuaGlkZUxvYWRpbmcoKVxuICAgIH1cbiAgfVxufVxuIiwgIi8qKlxuICogU3RyaXBlIE1vZHVsZSAtIEphdmFTY3JpcHQgRW50cnkgUG9pbnRcbiAqXG4gKiBJbml0aWFsaXplcyBTdGltdWx1cy5qcyBhbmQgcmVnaXN0ZXJzIGFsbCBjb250cm9sbGVyc1xuICovXG5cbmltcG9ydCB7IEFwcGxpY2F0aW9uIH0gZnJvbSBcIkBob3R3aXJlZC9zdGltdWx1c1wiXG5cbi8vIEltcG9ydCBjb250cm9sbGVyc1xuaW1wb3J0IEJ1eU5vd0NvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvYnV5X25vd19jb250cm9sbGVyXCJcbmltcG9ydCBTdHJpcGVPcmRlckNvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvc3RyaXBlX29yZGVyX2NvbnRyb2xsZXJcIlxuXG4vLyBTdGFydCBTdGltdWx1cyBhcHBsaWNhdGlvblxud2luZG93LlN0aW11bHVzID0gQXBwbGljYXRpb24uc3RhcnQoKVxuXG4vLyBSZWdpc3RlciBjb250cm9sbGVyc1xuU3RpbXVsdXMucmVnaXN0ZXIoXCJidXktbm93XCIsIEJ1eU5vd0NvbnRyb2xsZXIpXG5TdGltdWx1cy5yZWdpc3RlcihcInN0cmlwZS1vcmRlclwiLCBTdHJpcGVPcmRlckNvbnRyb2xsZXIpXG5cbi8vIERlYnVnIG1vZGUgaW4gZGV2ZWxvcG1lbnRcbmlmIChwcm9jZXNzLmVudi5OT0RFX0VOViA9PT0gJ2RldmVsb3BtZW50Jykge1xuICBTdGltdWx1cy5kZWJ1ZyA9IHRydWVcbiAgY29uc29sZS5sb2coJ1N0cmlwZSBNb2R1bGU6IFN0aW11bHVzIGluaXRpYWxpemVkIHdpdGggY29udHJvbGxlcnM6JywgU3RpbXVsdXMucm91dGVyLm1vZHVsZXNCeUlkZW50aWZpZXIpXG59XG5cbmNvbnNvbGUubG9nKCdTdHJpcGUgTW9kdWxlOiBKYXZhU2NyaXB0IGxvYWRlZCBhbmQgcmVhZHknKVxuIl0sCiAgIm1hcHBpbmdzIjogIjs7Ozs7Ozs7O0FBSUEsTUFBTSxnQkFBTixNQUFvQjtBQUFBLElBQ2hCLFlBQVksYUFBYSxXQUFXLGNBQWM7QUFDOUMsV0FBSyxjQUFjO0FBQ25CLFdBQUssWUFBWTtBQUNqQixXQUFLLGVBQWU7QUFDcEIsV0FBSyxvQkFBb0Isb0JBQUksSUFBSTtBQUFBLElBQ3JDO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxZQUFZLGlCQUFpQixLQUFLLFdBQVcsTUFBTSxLQUFLLFlBQVk7QUFBQSxJQUM3RTtBQUFBLElBQ0EsYUFBYTtBQUNULFdBQUssWUFBWSxvQkFBb0IsS0FBSyxXQUFXLE1BQU0sS0FBSyxZQUFZO0FBQUEsSUFDaEY7QUFBQSxJQUNBLGlCQUFpQixTQUFTO0FBQ3RCLFdBQUssa0JBQWtCLElBQUksT0FBTztBQUFBLElBQ3RDO0FBQUEsSUFDQSxvQkFBb0IsU0FBUztBQUN6QixXQUFLLGtCQUFrQixPQUFPLE9BQU87QUFBQSxJQUN6QztBQUFBLElBQ0EsWUFBWSxPQUFPO0FBQ2YsWUFBTSxnQkFBZ0IsWUFBWSxLQUFLO0FBQ3ZDLGlCQUFXLFdBQVcsS0FBSyxVQUFVO0FBQ2pDLFlBQUksY0FBYyw2QkFBNkI7QUFDM0M7QUFBQSxRQUNKLE9BQ0s7QUFDRCxrQkFBUSxZQUFZLGFBQWE7QUFBQSxRQUNyQztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxjQUFjO0FBQ1YsYUFBTyxLQUFLLGtCQUFrQixPQUFPO0FBQUEsSUFDekM7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sTUFBTSxLQUFLLEtBQUssaUJBQWlCLEVBQUUsS0FBSyxDQUFDLE1BQU0sVUFBVTtBQUM1RCxjQUFNLFlBQVksS0FBSyxPQUFPLGFBQWEsTUFBTTtBQUNqRCxlQUFPLFlBQVksYUFBYSxLQUFLLFlBQVksYUFBYSxJQUFJO0FBQUEsTUFDdEUsQ0FBQztBQUFBLElBQ0w7QUFBQSxFQUNKO0FBQ0EsV0FBUyxZQUFZLE9BQU87QUFDeEIsUUFBSSxpQ0FBaUMsT0FBTztBQUN4QyxhQUFPO0FBQUEsSUFDWCxPQUNLO0FBQ0QsWUFBTSxFQUFFLHlCQUF5QixJQUFJO0FBQ3JDLGFBQU8sT0FBTyxPQUFPLE9BQU87QUFBQSxRQUN4Qiw2QkFBNkI7QUFBQSxRQUM3QiwyQkFBMkI7QUFDdkIsZUFBSyw4QkFBOEI7QUFDbkMsbUNBQXlCLEtBQUssSUFBSTtBQUFBLFFBQ3RDO0FBQUEsTUFDSixDQUFDO0FBQUEsSUFDTDtBQUFBLEVBQ0o7QUFFQSxNQUFNLGFBQU4sTUFBaUI7QUFBQSxJQUNiLFlBQVksYUFBYTtBQUNyQixXQUFLLGNBQWM7QUFDbkIsV0FBSyxvQkFBb0Isb0JBQUksSUFBSTtBQUNqQyxXQUFLLFVBQVU7QUFBQSxJQUNuQjtBQUFBLElBQ0EsUUFBUTtBQUNKLFVBQUksQ0FBQyxLQUFLLFNBQVM7QUFDZixhQUFLLFVBQVU7QUFDZixhQUFLLGVBQWUsUUFBUSxDQUFDLGtCQUFrQixjQUFjLFFBQVEsQ0FBQztBQUFBLE1BQzFFO0FBQUEsSUFDSjtBQUFBLElBQ0EsT0FBTztBQUNILFVBQUksS0FBSyxTQUFTO0FBQ2QsYUFBSyxVQUFVO0FBQ2YsYUFBSyxlQUFlLFFBQVEsQ0FBQyxrQkFBa0IsY0FBYyxXQUFXLENBQUM7QUFBQSxNQUM3RTtBQUFBLElBQ0o7QUFBQSxJQUNBLElBQUksaUJBQWlCO0FBQ2pCLGFBQU8sTUFBTSxLQUFLLEtBQUssa0JBQWtCLE9BQU8sQ0FBQyxFQUFFLE9BQU8sQ0FBQyxXQUFXLFFBQVEsVUFBVSxPQUFPLE1BQU0sS0FBSyxJQUFJLE9BQU8sQ0FBQyxDQUFDLEdBQUcsQ0FBQyxDQUFDO0FBQUEsSUFDaEk7QUFBQSxJQUNBLGlCQUFpQixTQUFTO0FBQ3RCLFdBQUssNkJBQTZCLE9BQU8sRUFBRSxpQkFBaUIsT0FBTztBQUFBLElBQ3ZFO0FBQUEsSUFDQSxvQkFBb0IsU0FBUyxzQkFBc0IsT0FBTztBQUN0RCxXQUFLLDZCQUE2QixPQUFPLEVBQUUsb0JBQW9CLE9BQU87QUFDdEUsVUFBSTtBQUNBLGFBQUssOEJBQThCLE9BQU87QUFBQSxJQUNsRDtBQUFBLElBQ0EsWUFBWUEsUUFBTyxTQUFTLFNBQVMsQ0FBQyxHQUFHO0FBQ3JDLFdBQUssWUFBWSxZQUFZQSxRQUFPLFNBQVMsT0FBTyxJQUFJLE1BQU07QUFBQSxJQUNsRTtBQUFBLElBQ0EsOEJBQThCLFNBQVM7QUFDbkMsWUFBTSxnQkFBZ0IsS0FBSyw2QkFBNkIsT0FBTztBQUMvRCxVQUFJLENBQUMsY0FBYyxZQUFZLEdBQUc7QUFDOUIsc0JBQWMsV0FBVztBQUN6QixhQUFLLDZCQUE2QixPQUFPO0FBQUEsTUFDN0M7QUFBQSxJQUNKO0FBQUEsSUFDQSw2QkFBNkIsU0FBUztBQUNsQyxZQUFNLEVBQUUsYUFBYSxXQUFXLGFBQWEsSUFBSTtBQUNqRCxZQUFNLG1CQUFtQixLQUFLLG9DQUFvQyxXQUFXO0FBQzdFLFlBQU0sV0FBVyxLQUFLLFNBQVMsV0FBVyxZQUFZO0FBQ3RELHVCQUFpQixPQUFPLFFBQVE7QUFDaEMsVUFBSSxpQkFBaUIsUUFBUTtBQUN6QixhQUFLLGtCQUFrQixPQUFPLFdBQVc7QUFBQSxJQUNqRDtBQUFBLElBQ0EsNkJBQTZCLFNBQVM7QUFDbEMsWUFBTSxFQUFFLGFBQWEsV0FBVyxhQUFhLElBQUk7QUFDakQsYUFBTyxLQUFLLG1CQUFtQixhQUFhLFdBQVcsWUFBWTtBQUFBLElBQ3ZFO0FBQUEsSUFDQSxtQkFBbUIsYUFBYSxXQUFXLGNBQWM7QUFDckQsWUFBTSxtQkFBbUIsS0FBSyxvQ0FBb0MsV0FBVztBQUM3RSxZQUFNLFdBQVcsS0FBSyxTQUFTLFdBQVcsWUFBWTtBQUN0RCxVQUFJLGdCQUFnQixpQkFBaUIsSUFBSSxRQUFRO0FBQ2pELFVBQUksQ0FBQyxlQUFlO0FBQ2hCLHdCQUFnQixLQUFLLG9CQUFvQixhQUFhLFdBQVcsWUFBWTtBQUM3RSx5QkFBaUIsSUFBSSxVQUFVLGFBQWE7QUFBQSxNQUNoRDtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxvQkFBb0IsYUFBYSxXQUFXLGNBQWM7QUFDdEQsWUFBTSxnQkFBZ0IsSUFBSSxjQUFjLGFBQWEsV0FBVyxZQUFZO0FBQzVFLFVBQUksS0FBSyxTQUFTO0FBQ2Qsc0JBQWMsUUFBUTtBQUFBLE1BQzFCO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLG9DQUFvQyxhQUFhO0FBQzdDLFVBQUksbUJBQW1CLEtBQUssa0JBQWtCLElBQUksV0FBVztBQUM3RCxVQUFJLENBQUMsa0JBQWtCO0FBQ25CLDJCQUFtQixvQkFBSSxJQUFJO0FBQzNCLGFBQUssa0JBQWtCLElBQUksYUFBYSxnQkFBZ0I7QUFBQSxNQUM1RDtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxTQUFTLFdBQVcsY0FBYztBQUM5QixZQUFNLFFBQVEsQ0FBQyxTQUFTO0FBQ3hCLGFBQU8sS0FBSyxZQUFZLEVBQ25CLEtBQUssRUFDTCxRQUFRLENBQUMsUUFBUTtBQUNsQixjQUFNLEtBQUssR0FBRyxhQUFhLEdBQUcsSUFBSSxLQUFLLEdBQUcsR0FBRyxHQUFHLEVBQUU7QUFBQSxNQUN0RCxDQUFDO0FBQ0QsYUFBTyxNQUFNLEtBQUssR0FBRztBQUFBLElBQ3pCO0FBQUEsRUFDSjtBQUVBLE1BQU0saUNBQWlDO0FBQUEsSUFDbkMsS0FBSyxFQUFFLE9BQU8sTUFBTSxHQUFHO0FBQ25CLFVBQUk7QUFDQSxjQUFNLGdCQUFnQjtBQUMxQixhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsUUFBUSxFQUFFLE9BQU8sTUFBTSxHQUFHO0FBQ3RCLFVBQUk7QUFDQSxjQUFNLGVBQWU7QUFDekIsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLEtBQUssRUFBRSxPQUFPLE9BQU8sUUFBUSxHQUFHO0FBQzVCLFVBQUksT0FBTztBQUNQLGVBQU8sWUFBWSxNQUFNO0FBQUEsTUFDN0IsT0FDSztBQUNELGVBQU87QUFBQSxNQUNYO0FBQUEsSUFDSjtBQUFBLEVBQ0o7QUFDQSxNQUFNLG9CQUFvQjtBQUMxQixXQUFTLDRCQUE0QixrQkFBa0I7QUFDbkQsVUFBTSxTQUFTLGlCQUFpQixLQUFLO0FBQ3JDLFVBQU0sVUFBVSxPQUFPLE1BQU0saUJBQWlCLEtBQUssQ0FBQztBQUNwRCxRQUFJLFlBQVksUUFBUSxDQUFDO0FBQ3pCLFFBQUksWUFBWSxRQUFRLENBQUM7QUFDekIsUUFBSSxhQUFhLENBQUMsQ0FBQyxXQUFXLFNBQVMsVUFBVSxFQUFFLFNBQVMsU0FBUyxHQUFHO0FBQ3BFLG1CQUFhLElBQUksU0FBUztBQUMxQixrQkFBWTtBQUFBLElBQ2hCO0FBQ0EsV0FBTztBQUFBLE1BQ0gsYUFBYSxpQkFBaUIsUUFBUSxDQUFDLENBQUM7QUFBQSxNQUN4QztBQUFBLE1BQ0EsY0FBYyxRQUFRLENBQUMsSUFBSSxrQkFBa0IsUUFBUSxDQUFDLENBQUMsSUFBSSxDQUFDO0FBQUEsTUFDNUQsWUFBWSxRQUFRLENBQUM7QUFBQSxNQUNyQixZQUFZLFFBQVEsQ0FBQztBQUFBLE1BQ3JCLFdBQVcsUUFBUSxDQUFDLEtBQUs7QUFBQSxJQUM3QjtBQUFBLEVBQ0o7QUFDQSxXQUFTLGlCQUFpQixpQkFBaUI7QUFDdkMsUUFBSSxtQkFBbUIsVUFBVTtBQUM3QixhQUFPO0FBQUEsSUFDWCxXQUNTLG1CQUFtQixZQUFZO0FBQ3BDLGFBQU87QUFBQSxJQUNYO0FBQUEsRUFDSjtBQUNBLFdBQVMsa0JBQWtCLGNBQWM7QUFDckMsV0FBTyxhQUNGLE1BQU0sR0FBRyxFQUNULE9BQU8sQ0FBQyxTQUFTLFVBQVUsT0FBTyxPQUFPLFNBQVMsRUFBRSxDQUFDLE1BQU0sUUFBUSxNQUFNLEVBQUUsQ0FBQyxHQUFHLENBQUMsS0FBSyxLQUFLLEtBQUssRUFBRSxDQUFDLEdBQUcsQ0FBQyxDQUFDO0FBQUEsRUFDaEg7QUFDQSxXQUFTLHFCQUFxQixhQUFhO0FBQ3ZDLFFBQUksZUFBZSxRQUFRO0FBQ3ZCLGFBQU87QUFBQSxJQUNYLFdBQ1MsZUFBZSxVQUFVO0FBQzlCLGFBQU87QUFBQSxJQUNYO0FBQUEsRUFDSjtBQUVBLFdBQVMsU0FBUyxPQUFPO0FBQ3JCLFdBQU8sTUFBTSxRQUFRLHVCQUF1QixDQUFDLEdBQUcsU0FBUyxLQUFLLFlBQVksQ0FBQztBQUFBLEVBQy9FO0FBQ0EsV0FBUyxrQkFBa0IsT0FBTztBQUM5QixXQUFPLFNBQVMsTUFBTSxRQUFRLE9BQU8sR0FBRyxFQUFFLFFBQVEsT0FBTyxHQUFHLENBQUM7QUFBQSxFQUNqRTtBQUNBLFdBQVMsV0FBVyxPQUFPO0FBQ3ZCLFdBQU8sTUFBTSxPQUFPLENBQUMsRUFBRSxZQUFZLElBQUksTUFBTSxNQUFNLENBQUM7QUFBQSxFQUN4RDtBQUNBLFdBQVMsVUFBVSxPQUFPO0FBQ3RCLFdBQU8sTUFBTSxRQUFRLFlBQVksQ0FBQyxHQUFHLFNBQVMsSUFBSSxLQUFLLFlBQVksQ0FBQyxFQUFFO0FBQUEsRUFDMUU7QUFDQSxXQUFTLFNBQVMsT0FBTztBQUNyQixXQUFPLE1BQU0sTUFBTSxTQUFTLEtBQUssQ0FBQztBQUFBLEVBQ3RDO0FBRUEsV0FBUyxZQUFZLFFBQVE7QUFDekIsV0FBTyxXQUFXLFFBQVEsV0FBVztBQUFBLEVBQ3pDO0FBQ0EsV0FBUyxZQUFZLFFBQVEsVUFBVTtBQUNuQyxXQUFPLE9BQU8sVUFBVSxlQUFlLEtBQUssUUFBUSxRQUFRO0FBQUEsRUFDaEU7QUFFQSxNQUFNLGVBQWUsQ0FBQyxRQUFRLFFBQVEsT0FBTyxPQUFPO0FBQ3BELE1BQU0sU0FBTixNQUFhO0FBQUEsSUFDVCxZQUFZLFNBQVMsT0FBTyxZQUFZLFFBQVE7QUFDNUMsV0FBSyxVQUFVO0FBQ2YsV0FBSyxRQUFRO0FBQ2IsV0FBSyxjQUFjLFdBQVcsZUFBZTtBQUM3QyxXQUFLLFlBQVksV0FBVyxhQUFhLDhCQUE4QixPQUFPLEtBQUssTUFBTSxvQkFBb0I7QUFDN0csV0FBSyxlQUFlLFdBQVcsZ0JBQWdCLENBQUM7QUFDaEQsV0FBSyxhQUFhLFdBQVcsY0FBYyxNQUFNLG9CQUFvQjtBQUNyRSxXQUFLLGFBQWEsV0FBVyxjQUFjLE1BQU0scUJBQXFCO0FBQ3RFLFdBQUssWUFBWSxXQUFXLGFBQWE7QUFDekMsV0FBSyxTQUFTO0FBQUEsSUFDbEI7QUFBQSxJQUNBLE9BQU8sU0FBUyxPQUFPLFFBQVE7QUFDM0IsYUFBTyxJQUFJLEtBQUssTUFBTSxTQUFTLE1BQU0sT0FBTyw0QkFBNEIsTUFBTSxPQUFPLEdBQUcsTUFBTTtBQUFBLElBQ2xHO0FBQUEsSUFDQSxXQUFXO0FBQ1AsWUFBTSxjQUFjLEtBQUssWUFBWSxJQUFJLEtBQUssU0FBUyxLQUFLO0FBQzVELFlBQU0sY0FBYyxLQUFLLGtCQUFrQixJQUFJLEtBQUssZUFBZSxLQUFLO0FBQ3hFLGFBQU8sR0FBRyxLQUFLLFNBQVMsR0FBRyxXQUFXLEdBQUcsV0FBVyxLQUFLLEtBQUssVUFBVSxJQUFJLEtBQUssVUFBVTtBQUFBLElBQy9GO0FBQUEsSUFDQSwwQkFBMEIsT0FBTztBQUM3QixVQUFJLENBQUMsS0FBSyxXQUFXO0FBQ2pCLGVBQU87QUFBQSxNQUNYO0FBQ0EsWUFBTSxVQUFVLEtBQUssVUFBVSxNQUFNLEdBQUc7QUFDeEMsVUFBSSxLQUFLLHNCQUFzQixPQUFPLE9BQU8sR0FBRztBQUM1QyxlQUFPO0FBQUEsTUFDWDtBQUNBLFlBQU0saUJBQWlCLFFBQVEsT0FBTyxDQUFDLFFBQVEsQ0FBQyxhQUFhLFNBQVMsR0FBRyxDQUFDLEVBQUUsQ0FBQztBQUM3RSxVQUFJLENBQUMsZ0JBQWdCO0FBQ2pCLGVBQU87QUFBQSxNQUNYO0FBQ0EsVUFBSSxDQUFDLFlBQVksS0FBSyxhQUFhLGNBQWMsR0FBRztBQUNoRCxjQUFNLGdDQUFnQyxLQUFLLFNBQVMsRUFBRTtBQUFBLE1BQzFEO0FBQ0EsYUFBTyxLQUFLLFlBQVksY0FBYyxFQUFFLFlBQVksTUFBTSxNQUFNLElBQUksWUFBWTtBQUFBLElBQ3BGO0FBQUEsSUFDQSx1QkFBdUIsT0FBTztBQUMxQixVQUFJLENBQUMsS0FBSyxXQUFXO0FBQ2pCLGVBQU87QUFBQSxNQUNYO0FBQ0EsWUFBTSxVQUFVLENBQUMsS0FBSyxTQUFTO0FBQy9CLFVBQUksS0FBSyxzQkFBc0IsT0FBTyxPQUFPLEdBQUc7QUFDNUMsZUFBTztBQUFBLE1BQ1g7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsWUFBTSxTQUFTLENBQUM7QUFDaEIsWUFBTSxVQUFVLElBQUksT0FBTyxTQUFTLEtBQUssVUFBVSxnQkFBZ0IsR0FBRztBQUN0RSxpQkFBVyxFQUFFLE1BQU0sTUFBTSxLQUFLLE1BQU0sS0FBSyxLQUFLLFFBQVEsVUFBVSxHQUFHO0FBQy9ELGNBQU0sUUFBUSxLQUFLLE1BQU0sT0FBTztBQUNoQyxjQUFNLE1BQU0sU0FBUyxNQUFNLENBQUM7QUFDNUIsWUFBSSxLQUFLO0FBQ0wsaUJBQU8sU0FBUyxHQUFHLENBQUMsSUFBSSxTQUFTLEtBQUs7QUFBQSxRQUMxQztBQUFBLE1BQ0o7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsSUFBSSxrQkFBa0I7QUFDbEIsYUFBTyxxQkFBcUIsS0FBSyxXQUFXO0FBQUEsSUFDaEQ7QUFBQSxJQUNBLElBQUksY0FBYztBQUNkLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLHNCQUFzQixPQUFPLFNBQVM7QUFDbEMsWUFBTSxDQUFDLE1BQU0sTUFBTSxLQUFLLEtBQUssSUFBSSxhQUFhLElBQUksQ0FBQyxhQUFhLFFBQVEsU0FBUyxRQUFRLENBQUM7QUFDMUYsYUFBTyxNQUFNLFlBQVksUUFBUSxNQUFNLFlBQVksUUFBUSxNQUFNLFdBQVcsT0FBTyxNQUFNLGFBQWE7QUFBQSxJQUMxRztBQUFBLEVBQ0o7QUFDQSxNQUFNLG9CQUFvQjtBQUFBLElBQ3RCLEdBQUcsTUFBTTtBQUFBLElBQ1QsUUFBUSxNQUFNO0FBQUEsSUFDZCxNQUFNLE1BQU07QUFBQSxJQUNaLFNBQVMsTUFBTTtBQUFBLElBQ2YsT0FBTyxDQUFDLE1BQU8sRUFBRSxhQUFhLE1BQU0sS0FBSyxXQUFXLFVBQVU7QUFBQSxJQUM5RCxRQUFRLE1BQU07QUFBQSxJQUNkLFVBQVUsTUFBTTtBQUFBLEVBQ3BCO0FBQ0EsV0FBUyw4QkFBOEIsU0FBUztBQUM1QyxVQUFNLFVBQVUsUUFBUSxRQUFRLFlBQVk7QUFDNUMsUUFBSSxXQUFXLG1CQUFtQjtBQUM5QixhQUFPLGtCQUFrQixPQUFPLEVBQUUsT0FBTztBQUFBLElBQzdDO0FBQUEsRUFDSjtBQUNBLFdBQVMsTUFBTSxTQUFTO0FBQ3BCLFVBQU0sSUFBSSxNQUFNLE9BQU87QUFBQSxFQUMzQjtBQUNBLFdBQVMsU0FBUyxPQUFPO0FBQ3JCLFFBQUk7QUFDQSxhQUFPLEtBQUssTUFBTSxLQUFLO0FBQUEsSUFDM0IsU0FDTyxLQUFLO0FBQ1IsYUFBTztBQUFBLElBQ1g7QUFBQSxFQUNKO0FBRUEsTUFBTSxVQUFOLE1BQWM7QUFBQSxJQUNWLFlBQVksU0FBUyxRQUFRO0FBQ3pCLFdBQUssVUFBVTtBQUNmLFdBQUssU0FBUztBQUFBLElBQ2xCO0FBQUEsSUFDQSxJQUFJLFFBQVE7QUFDUixhQUFPLEtBQUssT0FBTztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxJQUFJLGNBQWM7QUFDZCxhQUFPLEtBQUssT0FBTztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxJQUFJLGVBQWU7QUFDZixhQUFPLEtBQUssT0FBTztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxZQUFZLE9BQU87QUFDZixZQUFNLGNBQWMsS0FBSyxtQkFBbUIsS0FBSztBQUNqRCxVQUFJLEtBQUsscUJBQXFCLEtBQUssS0FBSyxLQUFLLG9CQUFvQixXQUFXLEdBQUc7QUFDM0UsYUFBSyxnQkFBZ0IsV0FBVztBQUFBLE1BQ3BDO0FBQUEsSUFDSjtBQUFBLElBQ0EsSUFBSSxZQUFZO0FBQ1osYUFBTyxLQUFLLE9BQU87QUFBQSxJQUN2QjtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsWUFBTSxTQUFTLEtBQUssV0FBVyxLQUFLLFVBQVU7QUFDOUMsVUFBSSxPQUFPLFVBQVUsWUFBWTtBQUM3QixlQUFPO0FBQUEsTUFDWDtBQUNBLFlBQU0sSUFBSSxNQUFNLFdBQVcsS0FBSyxNQUFNLGtDQUFrQyxLQUFLLFVBQVUsR0FBRztBQUFBLElBQzlGO0FBQUEsSUFDQSxvQkFBb0IsT0FBTztBQUN2QixZQUFNLEVBQUUsUUFBUSxJQUFJLEtBQUs7QUFDekIsWUFBTSxFQUFFLHdCQUF3QixJQUFJLEtBQUssUUFBUTtBQUNqRCxZQUFNLEVBQUUsV0FBVyxJQUFJLEtBQUs7QUFDNUIsVUFBSSxTQUFTO0FBQ2IsaUJBQVcsQ0FBQyxNQUFNLEtBQUssS0FBSyxPQUFPLFFBQVEsS0FBSyxZQUFZLEdBQUc7QUFDM0QsWUFBSSxRQUFRLHlCQUF5QjtBQUNqQyxnQkFBTSxTQUFTLHdCQUF3QixJQUFJO0FBQzNDLG1CQUFTLFVBQVUsT0FBTyxFQUFFLE1BQU0sT0FBTyxPQUFPLFNBQVMsV0FBVyxDQUFDO0FBQUEsUUFDekUsT0FDSztBQUNEO0FBQUEsUUFDSjtBQUFBLE1BQ0o7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsbUJBQW1CLE9BQU87QUFDdEIsYUFBTyxPQUFPLE9BQU8sT0FBTyxFQUFFLFFBQVEsS0FBSyxPQUFPLE9BQU8sQ0FBQztBQUFBLElBQzlEO0FBQUEsSUFDQSxnQkFBZ0IsT0FBTztBQUNuQixZQUFNLEVBQUUsUUFBUSxjQUFjLElBQUk7QUFDbEMsVUFBSTtBQUNBLGFBQUssT0FBTyxLQUFLLEtBQUssWUFBWSxLQUFLO0FBQ3ZDLGFBQUssUUFBUSxpQkFBaUIsS0FBSyxZQUFZLEVBQUUsT0FBTyxRQUFRLGVBQWUsUUFBUSxLQUFLLFdBQVcsQ0FBQztBQUFBLE1BQzVHLFNBQ09BLFFBQU87QUFDVixjQUFNLEVBQUUsWUFBWSxZQUFZLFNBQVMsTUFBTSxJQUFJO0FBQ25ELGNBQU0sU0FBUyxFQUFFLFlBQVksWUFBWSxTQUFTLE9BQU8sTUFBTTtBQUMvRCxhQUFLLFFBQVEsWUFBWUEsUUFBTyxvQkFBb0IsS0FBSyxNQUFNLEtBQUssTUFBTTtBQUFBLE1BQzlFO0FBQUEsSUFDSjtBQUFBLElBQ0EscUJBQXFCLE9BQU87QUFDeEIsWUFBTSxjQUFjLE1BQU07QUFDMUIsVUFBSSxpQkFBaUIsaUJBQWlCLEtBQUssT0FBTywwQkFBMEIsS0FBSyxHQUFHO0FBQ2hGLGVBQU87QUFBQSxNQUNYO0FBQ0EsVUFBSSxpQkFBaUIsY0FBYyxLQUFLLE9BQU8sdUJBQXVCLEtBQUssR0FBRztBQUMxRSxlQUFPO0FBQUEsTUFDWDtBQUNBLFVBQUksS0FBSyxZQUFZLGFBQWE7QUFDOUIsZUFBTztBQUFBLE1BQ1gsV0FDUyx1QkFBdUIsV0FBVyxLQUFLLFFBQVEsU0FBUyxXQUFXLEdBQUc7QUFDM0UsZUFBTyxLQUFLLE1BQU0sZ0JBQWdCLFdBQVc7QUFBQSxNQUNqRCxPQUNLO0FBQ0QsZUFBTyxLQUFLLE1BQU0sZ0JBQWdCLEtBQUssT0FBTyxPQUFPO0FBQUEsTUFDekQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssT0FBTztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFFBQVE7QUFDUixhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsRUFDSjtBQUVBLE1BQU0sa0JBQU4sTUFBc0I7QUFBQSxJQUNsQixZQUFZLFNBQVMsVUFBVTtBQUMzQixXQUFLLHVCQUF1QixFQUFFLFlBQVksTUFBTSxXQUFXLE1BQU0sU0FBUyxLQUFLO0FBQy9FLFdBQUssVUFBVTtBQUNmLFdBQUssVUFBVTtBQUNmLFdBQUssV0FBVztBQUNoQixXQUFLLFdBQVcsb0JBQUksSUFBSTtBQUN4QixXQUFLLG1CQUFtQixJQUFJLGlCQUFpQixDQUFDLGNBQWMsS0FBSyxpQkFBaUIsU0FBUyxDQUFDO0FBQUEsSUFDaEc7QUFBQSxJQUNBLFFBQVE7QUFDSixVQUFJLENBQUMsS0FBSyxTQUFTO0FBQ2YsYUFBSyxVQUFVO0FBQ2YsYUFBSyxpQkFBaUIsUUFBUSxLQUFLLFNBQVMsS0FBSyxvQkFBb0I7QUFDckUsYUFBSyxRQUFRO0FBQUEsTUFDakI7QUFBQSxJQUNKO0FBQUEsSUFDQSxNQUFNLFVBQVU7QUFDWixVQUFJLEtBQUssU0FBUztBQUNkLGFBQUssaUJBQWlCLFdBQVc7QUFDakMsYUFBSyxVQUFVO0FBQUEsTUFDbkI7QUFDQSxlQUFTO0FBQ1QsVUFBSSxDQUFDLEtBQUssU0FBUztBQUNmLGFBQUssaUJBQWlCLFFBQVEsS0FBSyxTQUFTLEtBQUssb0JBQW9CO0FBQ3JFLGFBQUssVUFBVTtBQUFBLE1BQ25CO0FBQUEsSUFDSjtBQUFBLElBQ0EsT0FBTztBQUNILFVBQUksS0FBSyxTQUFTO0FBQ2QsYUFBSyxpQkFBaUIsWUFBWTtBQUNsQyxhQUFLLGlCQUFpQixXQUFXO0FBQ2pDLGFBQUssVUFBVTtBQUFBLE1BQ25CO0FBQUEsSUFDSjtBQUFBLElBQ0EsVUFBVTtBQUNOLFVBQUksS0FBSyxTQUFTO0FBQ2QsY0FBTSxVQUFVLElBQUksSUFBSSxLQUFLLG9CQUFvQixDQUFDO0FBQ2xELG1CQUFXLFdBQVcsTUFBTSxLQUFLLEtBQUssUUFBUSxHQUFHO0FBQzdDLGNBQUksQ0FBQyxRQUFRLElBQUksT0FBTyxHQUFHO0FBQ3ZCLGlCQUFLLGNBQWMsT0FBTztBQUFBLFVBQzlCO0FBQUEsUUFDSjtBQUNBLG1CQUFXLFdBQVcsTUFBTSxLQUFLLE9BQU8sR0FBRztBQUN2QyxlQUFLLFdBQVcsT0FBTztBQUFBLFFBQzNCO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGlCQUFpQixXQUFXO0FBQ3hCLFVBQUksS0FBSyxTQUFTO0FBQ2QsbUJBQVcsWUFBWSxXQUFXO0FBQzlCLGVBQUssZ0JBQWdCLFFBQVE7QUFBQSxRQUNqQztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxnQkFBZ0IsVUFBVTtBQUN0QixVQUFJLFNBQVMsUUFBUSxjQUFjO0FBQy9CLGFBQUssdUJBQXVCLFNBQVMsUUFBUSxTQUFTLGFBQWE7QUFBQSxNQUN2RSxXQUNTLFNBQVMsUUFBUSxhQUFhO0FBQ25DLGFBQUssb0JBQW9CLFNBQVMsWUFBWTtBQUM5QyxhQUFLLGtCQUFrQixTQUFTLFVBQVU7QUFBQSxNQUM5QztBQUFBLElBQ0o7QUFBQSxJQUNBLHVCQUF1QixTQUFTLGVBQWU7QUFDM0MsVUFBSSxLQUFLLFNBQVMsSUFBSSxPQUFPLEdBQUc7QUFDNUIsWUFBSSxLQUFLLFNBQVMsMkJBQTJCLEtBQUssYUFBYSxPQUFPLEdBQUc7QUFDckUsZUFBSyxTQUFTLHdCQUF3QixTQUFTLGFBQWE7QUFBQSxRQUNoRSxPQUNLO0FBQ0QsZUFBSyxjQUFjLE9BQU87QUFBQSxRQUM5QjtBQUFBLE1BQ0osV0FDUyxLQUFLLGFBQWEsT0FBTyxHQUFHO0FBQ2pDLGFBQUssV0FBVyxPQUFPO0FBQUEsTUFDM0I7QUFBQSxJQUNKO0FBQUEsSUFDQSxvQkFBb0IsT0FBTztBQUN2QixpQkFBVyxRQUFRLE1BQU0sS0FBSyxLQUFLLEdBQUc7QUFDbEMsY0FBTSxVQUFVLEtBQUssZ0JBQWdCLElBQUk7QUFDekMsWUFBSSxTQUFTO0FBQ1QsZUFBSyxZQUFZLFNBQVMsS0FBSyxhQUFhO0FBQUEsUUFDaEQ7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0Esa0JBQWtCLE9BQU87QUFDckIsaUJBQVcsUUFBUSxNQUFNLEtBQUssS0FBSyxHQUFHO0FBQ2xDLGNBQU0sVUFBVSxLQUFLLGdCQUFnQixJQUFJO0FBQ3pDLFlBQUksV0FBVyxLQUFLLGdCQUFnQixPQUFPLEdBQUc7QUFDMUMsZUFBSyxZQUFZLFNBQVMsS0FBSyxVQUFVO0FBQUEsUUFDN0M7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsYUFBYSxTQUFTO0FBQ2xCLGFBQU8sS0FBSyxTQUFTLGFBQWEsT0FBTztBQUFBLElBQzdDO0FBQUEsSUFDQSxvQkFBb0IsT0FBTyxLQUFLLFNBQVM7QUFDckMsYUFBTyxLQUFLLFNBQVMsb0JBQW9CLElBQUk7QUFBQSxJQUNqRDtBQUFBLElBQ0EsWUFBWSxNQUFNLFdBQVc7QUFDekIsaUJBQVcsV0FBVyxLQUFLLG9CQUFvQixJQUFJLEdBQUc7QUFDbEQsa0JBQVUsS0FBSyxNQUFNLE9BQU87QUFBQSxNQUNoQztBQUFBLElBQ0o7QUFBQSxJQUNBLGdCQUFnQixNQUFNO0FBQ2xCLFVBQUksS0FBSyxZQUFZLEtBQUssY0FBYztBQUNwQyxlQUFPO0FBQUEsTUFDWDtBQUFBLElBQ0o7QUFBQSxJQUNBLGdCQUFnQixTQUFTO0FBQ3JCLFVBQUksUUFBUSxlQUFlLEtBQUssUUFBUSxhQUFhO0FBQ2pELGVBQU87QUFBQSxNQUNYLE9BQ0s7QUFDRCxlQUFPLEtBQUssUUFBUSxTQUFTLE9BQU87QUFBQSxNQUN4QztBQUFBLElBQ0o7QUFBQSxJQUNBLFdBQVcsU0FBUztBQUNoQixVQUFJLENBQUMsS0FBSyxTQUFTLElBQUksT0FBTyxHQUFHO0FBQzdCLFlBQUksS0FBSyxnQkFBZ0IsT0FBTyxHQUFHO0FBQy9CLGVBQUssU0FBUyxJQUFJLE9BQU87QUFDekIsY0FBSSxLQUFLLFNBQVMsZ0JBQWdCO0FBQzlCLGlCQUFLLFNBQVMsZUFBZSxPQUFPO0FBQUEsVUFDeEM7QUFBQSxRQUNKO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGNBQWMsU0FBUztBQUNuQixVQUFJLEtBQUssU0FBUyxJQUFJLE9BQU8sR0FBRztBQUM1QixhQUFLLFNBQVMsT0FBTyxPQUFPO0FBQzVCLFlBQUksS0FBSyxTQUFTLGtCQUFrQjtBQUNoQyxlQUFLLFNBQVMsaUJBQWlCLE9BQU87QUFBQSxRQUMxQztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLE1BQU0sb0JBQU4sTUFBd0I7QUFBQSxJQUNwQixZQUFZLFNBQVMsZUFBZSxVQUFVO0FBQzFDLFdBQUssZ0JBQWdCO0FBQ3JCLFdBQUssV0FBVztBQUNoQixXQUFLLGtCQUFrQixJQUFJLGdCQUFnQixTQUFTLElBQUk7QUFBQSxJQUM1RDtBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLGdCQUFnQjtBQUFBLElBQ2hDO0FBQUEsSUFDQSxJQUFJLFdBQVc7QUFDWCxhQUFPLElBQUksS0FBSyxhQUFhO0FBQUEsSUFDakM7QUFBQSxJQUNBLFFBQVE7QUFDSixXQUFLLGdCQUFnQixNQUFNO0FBQUEsSUFDL0I7QUFBQSxJQUNBLE1BQU0sVUFBVTtBQUNaLFdBQUssZ0JBQWdCLE1BQU0sUUFBUTtBQUFBLElBQ3ZDO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxnQkFBZ0IsS0FBSztBQUFBLElBQzlCO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxnQkFBZ0IsUUFBUTtBQUFBLElBQ2pDO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssZ0JBQWdCO0FBQUEsSUFDaEM7QUFBQSxJQUNBLGFBQWEsU0FBUztBQUNsQixhQUFPLFFBQVEsYUFBYSxLQUFLLGFBQWE7QUFBQSxJQUNsRDtBQUFBLElBQ0Esb0JBQW9CLE1BQU07QUFDdEIsWUFBTSxRQUFRLEtBQUssYUFBYSxJQUFJLElBQUksQ0FBQyxJQUFJLElBQUksQ0FBQztBQUNsRCxZQUFNLFVBQVUsTUFBTSxLQUFLLEtBQUssaUJBQWlCLEtBQUssUUFBUSxDQUFDO0FBQy9ELGFBQU8sTUFBTSxPQUFPLE9BQU87QUFBQSxJQUMvQjtBQUFBLElBQ0EsZUFBZSxTQUFTO0FBQ3BCLFVBQUksS0FBSyxTQUFTLHlCQUF5QjtBQUN2QyxhQUFLLFNBQVMsd0JBQXdCLFNBQVMsS0FBSyxhQUFhO0FBQUEsTUFDckU7QUFBQSxJQUNKO0FBQUEsSUFDQSxpQkFBaUIsU0FBUztBQUN0QixVQUFJLEtBQUssU0FBUywyQkFBMkI7QUFDekMsYUFBSyxTQUFTLDBCQUEwQixTQUFTLEtBQUssYUFBYTtBQUFBLE1BQ3ZFO0FBQUEsSUFDSjtBQUFBLElBQ0Esd0JBQXdCLFNBQVMsZUFBZTtBQUM1QyxVQUFJLEtBQUssU0FBUyxnQ0FBZ0MsS0FBSyxpQkFBaUIsZUFBZTtBQUNuRixhQUFLLFNBQVMsNkJBQTZCLFNBQVMsYUFBYTtBQUFBLE1BQ3JFO0FBQUEsSUFDSjtBQUFBLEVBQ0o7QUFFQSxXQUFTLElBQUksS0FBSyxLQUFLLE9BQU87QUFDMUIsVUFBTSxLQUFLLEdBQUcsRUFBRSxJQUFJLEtBQUs7QUFBQSxFQUM3QjtBQUNBLFdBQVMsSUFBSSxLQUFLLEtBQUssT0FBTztBQUMxQixVQUFNLEtBQUssR0FBRyxFQUFFLE9BQU8sS0FBSztBQUM1QixVQUFNLEtBQUssR0FBRztBQUFBLEVBQ2xCO0FBQ0EsV0FBUyxNQUFNLEtBQUssS0FBSztBQUNyQixRQUFJLFNBQVMsSUFBSSxJQUFJLEdBQUc7QUFDeEIsUUFBSSxDQUFDLFFBQVE7QUFDVCxlQUFTLG9CQUFJLElBQUk7QUFDakIsVUFBSSxJQUFJLEtBQUssTUFBTTtBQUFBLElBQ3ZCO0FBQ0EsV0FBTztBQUFBLEVBQ1g7QUFDQSxXQUFTLE1BQU0sS0FBSyxLQUFLO0FBQ3JCLFVBQU0sU0FBUyxJQUFJLElBQUksR0FBRztBQUMxQixRQUFJLFVBQVUsUUFBUSxPQUFPLFFBQVEsR0FBRztBQUNwQyxVQUFJLE9BQU8sR0FBRztBQUFBLElBQ2xCO0FBQUEsRUFDSjtBQUVBLE1BQU0sV0FBTixNQUFlO0FBQUEsSUFDWCxjQUFjO0FBQ1YsV0FBSyxjQUFjLG9CQUFJLElBQUk7QUFBQSxJQUMvQjtBQUFBLElBQ0EsSUFBSSxPQUFPO0FBQ1AsYUFBTyxNQUFNLEtBQUssS0FBSyxZQUFZLEtBQUssQ0FBQztBQUFBLElBQzdDO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxZQUFNLE9BQU8sTUFBTSxLQUFLLEtBQUssWUFBWSxPQUFPLENBQUM7QUFDakQsYUFBTyxLQUFLLE9BQU8sQ0FBQyxRQUFRLFFBQVEsT0FBTyxPQUFPLE1BQU0sS0FBSyxHQUFHLENBQUMsR0FBRyxDQUFDLENBQUM7QUFBQSxJQUMxRTtBQUFBLElBQ0EsSUFBSSxPQUFPO0FBQ1AsWUFBTSxPQUFPLE1BQU0sS0FBSyxLQUFLLFlBQVksT0FBTyxDQUFDO0FBQ2pELGFBQU8sS0FBSyxPQUFPLENBQUMsTUFBTSxRQUFRLE9BQU8sSUFBSSxNQUFNLENBQUM7QUFBQSxJQUN4RDtBQUFBLElBQ0EsSUFBSSxLQUFLLE9BQU87QUFDWixVQUFJLEtBQUssYUFBYSxLQUFLLEtBQUs7QUFBQSxJQUNwQztBQUFBLElBQ0EsT0FBTyxLQUFLLE9BQU87QUFDZixVQUFJLEtBQUssYUFBYSxLQUFLLEtBQUs7QUFBQSxJQUNwQztBQUFBLElBQ0EsSUFBSSxLQUFLLE9BQU87QUFDWixZQUFNLFNBQVMsS0FBSyxZQUFZLElBQUksR0FBRztBQUN2QyxhQUFPLFVBQVUsUUFBUSxPQUFPLElBQUksS0FBSztBQUFBLElBQzdDO0FBQUEsSUFDQSxPQUFPLEtBQUs7QUFDUixhQUFPLEtBQUssWUFBWSxJQUFJLEdBQUc7QUFBQSxJQUNuQztBQUFBLElBQ0EsU0FBUyxPQUFPO0FBQ1osWUFBTSxPQUFPLE1BQU0sS0FBSyxLQUFLLFlBQVksT0FBTyxDQUFDO0FBQ2pELGFBQU8sS0FBSyxLQUFLLENBQUMsUUFBUSxJQUFJLElBQUksS0FBSyxDQUFDO0FBQUEsSUFDNUM7QUFBQSxJQUNBLGdCQUFnQixLQUFLO0FBQ2pCLFlBQU0sU0FBUyxLQUFLLFlBQVksSUFBSSxHQUFHO0FBQ3ZDLGFBQU8sU0FBUyxNQUFNLEtBQUssTUFBTSxJQUFJLENBQUM7QUFBQSxJQUMxQztBQUFBLElBQ0EsZ0JBQWdCLE9BQU87QUFDbkIsYUFBTyxNQUFNLEtBQUssS0FBSyxXQUFXLEVBQzdCLE9BQU8sQ0FBQyxDQUFDLE1BQU0sTUFBTSxNQUFNLE9BQU8sSUFBSSxLQUFLLENBQUMsRUFDNUMsSUFBSSxDQUFDLENBQUMsS0FBSyxPQUFPLE1BQU0sR0FBRztBQUFBLElBQ3BDO0FBQUEsRUFDSjtBQTJCQSxNQUFNLG1CQUFOLE1BQXVCO0FBQUEsSUFDbkIsWUFBWSxTQUFTLFVBQVUsVUFBVSxTQUFTO0FBQzlDLFdBQUssWUFBWTtBQUNqQixXQUFLLFVBQVU7QUFDZixXQUFLLGtCQUFrQixJQUFJLGdCQUFnQixTQUFTLElBQUk7QUFDeEQsV0FBSyxXQUFXO0FBQ2hCLFdBQUssbUJBQW1CLElBQUksU0FBUztBQUFBLElBQ3pDO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssZ0JBQWdCO0FBQUEsSUFDaEM7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sS0FBSztBQUFBLElBQ2hCO0FBQUEsSUFDQSxJQUFJLFNBQVMsVUFBVTtBQUNuQixXQUFLLFlBQVk7QUFDakIsV0FBSyxRQUFRO0FBQUEsSUFDakI7QUFBQSxJQUNBLFFBQVE7QUFDSixXQUFLLGdCQUFnQixNQUFNO0FBQUEsSUFDL0I7QUFBQSxJQUNBLE1BQU0sVUFBVTtBQUNaLFdBQUssZ0JBQWdCLE1BQU0sUUFBUTtBQUFBLElBQ3ZDO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxnQkFBZ0IsS0FBSztBQUFBLElBQzlCO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxnQkFBZ0IsUUFBUTtBQUFBLElBQ2pDO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssZ0JBQWdCO0FBQUEsSUFDaEM7QUFBQSxJQUNBLGFBQWEsU0FBUztBQUNsQixZQUFNLEVBQUUsU0FBUyxJQUFJO0FBQ3JCLFVBQUksVUFBVTtBQUNWLGNBQU0sVUFBVSxRQUFRLFFBQVEsUUFBUTtBQUN4QyxZQUFJLEtBQUssU0FBUyxzQkFBc0I7QUFDcEMsaUJBQU8sV0FBVyxLQUFLLFNBQVMscUJBQXFCLFNBQVMsS0FBSyxPQUFPO0FBQUEsUUFDOUU7QUFDQSxlQUFPO0FBQUEsTUFDWCxPQUNLO0FBQ0QsZUFBTztBQUFBLE1BQ1g7QUFBQSxJQUNKO0FBQUEsSUFDQSxvQkFBb0IsTUFBTTtBQUN0QixZQUFNLEVBQUUsU0FBUyxJQUFJO0FBQ3JCLFVBQUksVUFBVTtBQUNWLGNBQU0sUUFBUSxLQUFLLGFBQWEsSUFBSSxJQUFJLENBQUMsSUFBSSxJQUFJLENBQUM7QUFDbEQsY0FBTSxVQUFVLE1BQU0sS0FBSyxLQUFLLGlCQUFpQixRQUFRLENBQUMsRUFBRSxPQUFPLENBQUNDLFdBQVUsS0FBSyxhQUFhQSxNQUFLLENBQUM7QUFDdEcsZUFBTyxNQUFNLE9BQU8sT0FBTztBQUFBLE1BQy9CLE9BQ0s7QUFDRCxlQUFPLENBQUM7QUFBQSxNQUNaO0FBQUEsSUFDSjtBQUFBLElBQ0EsZUFBZSxTQUFTO0FBQ3BCLFlBQU0sRUFBRSxTQUFTLElBQUk7QUFDckIsVUFBSSxVQUFVO0FBQ1YsYUFBSyxnQkFBZ0IsU0FBUyxRQUFRO0FBQUEsTUFDMUM7QUFBQSxJQUNKO0FBQUEsSUFDQSxpQkFBaUIsU0FBUztBQUN0QixZQUFNLFlBQVksS0FBSyxpQkFBaUIsZ0JBQWdCLE9BQU87QUFDL0QsaUJBQVcsWUFBWSxXQUFXO0FBQzlCLGFBQUssa0JBQWtCLFNBQVMsUUFBUTtBQUFBLE1BQzVDO0FBQUEsSUFDSjtBQUFBLElBQ0Esd0JBQXdCLFNBQVMsZ0JBQWdCO0FBQzdDLFlBQU0sRUFBRSxTQUFTLElBQUk7QUFDckIsVUFBSSxVQUFVO0FBQ1YsY0FBTSxVQUFVLEtBQUssYUFBYSxPQUFPO0FBQ3pDLGNBQU0sZ0JBQWdCLEtBQUssaUJBQWlCLElBQUksVUFBVSxPQUFPO0FBQ2pFLFlBQUksV0FBVyxDQUFDLGVBQWU7QUFDM0IsZUFBSyxnQkFBZ0IsU0FBUyxRQUFRO0FBQUEsUUFDMUMsV0FDUyxDQUFDLFdBQVcsZUFBZTtBQUNoQyxlQUFLLGtCQUFrQixTQUFTLFFBQVE7QUFBQSxRQUM1QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxnQkFBZ0IsU0FBUyxVQUFVO0FBQy9CLFdBQUssU0FBUyxnQkFBZ0IsU0FBUyxVQUFVLEtBQUssT0FBTztBQUM3RCxXQUFLLGlCQUFpQixJQUFJLFVBQVUsT0FBTztBQUFBLElBQy9DO0FBQUEsSUFDQSxrQkFBa0IsU0FBUyxVQUFVO0FBQ2pDLFdBQUssU0FBUyxrQkFBa0IsU0FBUyxVQUFVLEtBQUssT0FBTztBQUMvRCxXQUFLLGlCQUFpQixPQUFPLFVBQVUsT0FBTztBQUFBLElBQ2xEO0FBQUEsRUFDSjtBQUVBLE1BQU0sb0JBQU4sTUFBd0I7QUFBQSxJQUNwQixZQUFZLFNBQVMsVUFBVTtBQUMzQixXQUFLLFVBQVU7QUFDZixXQUFLLFdBQVc7QUFDaEIsV0FBSyxVQUFVO0FBQ2YsV0FBSyxZQUFZLG9CQUFJLElBQUk7QUFDekIsV0FBSyxtQkFBbUIsSUFBSSxpQkFBaUIsQ0FBQyxjQUFjLEtBQUssaUJBQWlCLFNBQVMsQ0FBQztBQUFBLElBQ2hHO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssU0FBUztBQUNmLGFBQUssVUFBVTtBQUNmLGFBQUssaUJBQWlCLFFBQVEsS0FBSyxTQUFTLEVBQUUsWUFBWSxNQUFNLG1CQUFtQixLQUFLLENBQUM7QUFDekYsYUFBSyxRQUFRO0FBQUEsTUFDakI7QUFBQSxJQUNKO0FBQUEsSUFDQSxPQUFPO0FBQ0gsVUFBSSxLQUFLLFNBQVM7QUFDZCxhQUFLLGlCQUFpQixZQUFZO0FBQ2xDLGFBQUssaUJBQWlCLFdBQVc7QUFDakMsYUFBSyxVQUFVO0FBQUEsTUFDbkI7QUFBQSxJQUNKO0FBQUEsSUFDQSxVQUFVO0FBQ04sVUFBSSxLQUFLLFNBQVM7QUFDZCxtQkFBVyxpQkFBaUIsS0FBSyxxQkFBcUI7QUFDbEQsZUFBSyxpQkFBaUIsZUFBZSxJQUFJO0FBQUEsUUFDN0M7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsaUJBQWlCLFdBQVc7QUFDeEIsVUFBSSxLQUFLLFNBQVM7QUFDZCxtQkFBVyxZQUFZLFdBQVc7QUFDOUIsZUFBSyxnQkFBZ0IsUUFBUTtBQUFBLFFBQ2pDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGdCQUFnQixVQUFVO0FBQ3RCLFlBQU0sZ0JBQWdCLFNBQVM7QUFDL0IsVUFBSSxlQUFlO0FBQ2YsYUFBSyxpQkFBaUIsZUFBZSxTQUFTLFFBQVE7QUFBQSxNQUMxRDtBQUFBLElBQ0o7QUFBQSxJQUNBLGlCQUFpQixlQUFlLFVBQVU7QUFDdEMsWUFBTSxNQUFNLEtBQUssU0FBUyw0QkFBNEIsYUFBYTtBQUNuRSxVQUFJLE9BQU8sTUFBTTtBQUNiLFlBQUksQ0FBQyxLQUFLLFVBQVUsSUFBSSxhQUFhLEdBQUc7QUFDcEMsZUFBSyxrQkFBa0IsS0FBSyxhQUFhO0FBQUEsUUFDN0M7QUFDQSxjQUFNLFFBQVEsS0FBSyxRQUFRLGFBQWEsYUFBYTtBQUNyRCxZQUFJLEtBQUssVUFBVSxJQUFJLGFBQWEsS0FBSyxPQUFPO0FBQzVDLGVBQUssc0JBQXNCLE9BQU8sS0FBSyxRQUFRO0FBQUEsUUFDbkQ7QUFDQSxZQUFJLFNBQVMsTUFBTTtBQUNmLGdCQUFNQyxZQUFXLEtBQUssVUFBVSxJQUFJLGFBQWE7QUFDakQsZUFBSyxVQUFVLE9BQU8sYUFBYTtBQUNuQyxjQUFJQTtBQUNBLGlCQUFLLG9CQUFvQixLQUFLLGVBQWVBLFNBQVE7QUFBQSxRQUM3RCxPQUNLO0FBQ0QsZUFBSyxVQUFVLElBQUksZUFBZSxLQUFLO0FBQUEsUUFDM0M7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0Esa0JBQWtCLEtBQUssZUFBZTtBQUNsQyxVQUFJLEtBQUssU0FBUyxtQkFBbUI7QUFDakMsYUFBSyxTQUFTLGtCQUFrQixLQUFLLGFBQWE7QUFBQSxNQUN0RDtBQUFBLElBQ0o7QUFBQSxJQUNBLHNCQUFzQixPQUFPLEtBQUssVUFBVTtBQUN4QyxVQUFJLEtBQUssU0FBUyx1QkFBdUI7QUFDckMsYUFBSyxTQUFTLHNCQUFzQixPQUFPLEtBQUssUUFBUTtBQUFBLE1BQzVEO0FBQUEsSUFDSjtBQUFBLElBQ0Esb0JBQW9CLEtBQUssZUFBZSxVQUFVO0FBQzlDLFVBQUksS0FBSyxTQUFTLHFCQUFxQjtBQUNuQyxhQUFLLFNBQVMsb0JBQW9CLEtBQUssZUFBZSxRQUFRO0FBQUEsTUFDbEU7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLHNCQUFzQjtBQUN0QixhQUFPLE1BQU0sS0FBSyxJQUFJLElBQUksS0FBSyxzQkFBc0IsT0FBTyxLQUFLLHNCQUFzQixDQUFDLENBQUM7QUFBQSxJQUM3RjtBQUFBLElBQ0EsSUFBSSx3QkFBd0I7QUFDeEIsYUFBTyxNQUFNLEtBQUssS0FBSyxRQUFRLFVBQVUsRUFBRSxJQUFJLENBQUMsY0FBYyxVQUFVLElBQUk7QUFBQSxJQUNoRjtBQUFBLElBQ0EsSUFBSSx5QkFBeUI7QUFDekIsYUFBTyxNQUFNLEtBQUssS0FBSyxVQUFVLEtBQUssQ0FBQztBQUFBLElBQzNDO0FBQUEsRUFDSjtBQUVBLE1BQU0sb0JBQU4sTUFBd0I7QUFBQSxJQUNwQixZQUFZLFNBQVMsZUFBZSxVQUFVO0FBQzFDLFdBQUssb0JBQW9CLElBQUksa0JBQWtCLFNBQVMsZUFBZSxJQUFJO0FBQzNFLFdBQUssV0FBVztBQUNoQixXQUFLLGtCQUFrQixJQUFJLFNBQVM7QUFBQSxJQUN4QztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLGtCQUFrQjtBQUFBLElBQ2xDO0FBQUEsSUFDQSxRQUFRO0FBQ0osV0FBSyxrQkFBa0IsTUFBTTtBQUFBLElBQ2pDO0FBQUEsSUFDQSxNQUFNLFVBQVU7QUFDWixXQUFLLGtCQUFrQixNQUFNLFFBQVE7QUFBQSxJQUN6QztBQUFBLElBQ0EsT0FBTztBQUNILFdBQUssa0JBQWtCLEtBQUs7QUFBQSxJQUNoQztBQUFBLElBQ0EsVUFBVTtBQUNOLFdBQUssa0JBQWtCLFFBQVE7QUFBQSxJQUNuQztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLGtCQUFrQjtBQUFBLElBQ2xDO0FBQUEsSUFDQSxJQUFJLGdCQUFnQjtBQUNoQixhQUFPLEtBQUssa0JBQWtCO0FBQUEsSUFDbEM7QUFBQSxJQUNBLHdCQUF3QixTQUFTO0FBQzdCLFdBQUssY0FBYyxLQUFLLHFCQUFxQixPQUFPLENBQUM7QUFBQSxJQUN6RDtBQUFBLElBQ0EsNkJBQTZCLFNBQVM7QUFDbEMsWUFBTSxDQUFDLGlCQUFpQixhQUFhLElBQUksS0FBSyx3QkFBd0IsT0FBTztBQUM3RSxXQUFLLGdCQUFnQixlQUFlO0FBQ3BDLFdBQUssY0FBYyxhQUFhO0FBQUEsSUFDcEM7QUFBQSxJQUNBLDBCQUEwQixTQUFTO0FBQy9CLFdBQUssZ0JBQWdCLEtBQUssZ0JBQWdCLGdCQUFnQixPQUFPLENBQUM7QUFBQSxJQUN0RTtBQUFBLElBQ0EsY0FBYyxRQUFRO0FBQ2xCLGFBQU8sUUFBUSxDQUFDLFVBQVUsS0FBSyxhQUFhLEtBQUssQ0FBQztBQUFBLElBQ3REO0FBQUEsSUFDQSxnQkFBZ0IsUUFBUTtBQUNwQixhQUFPLFFBQVEsQ0FBQyxVQUFVLEtBQUssZUFBZSxLQUFLLENBQUM7QUFBQSxJQUN4RDtBQUFBLElBQ0EsYUFBYSxPQUFPO0FBQ2hCLFdBQUssU0FBUyxhQUFhLEtBQUs7QUFDaEMsV0FBSyxnQkFBZ0IsSUFBSSxNQUFNLFNBQVMsS0FBSztBQUFBLElBQ2pEO0FBQUEsSUFDQSxlQUFlLE9BQU87QUFDbEIsV0FBSyxTQUFTLGVBQWUsS0FBSztBQUNsQyxXQUFLLGdCQUFnQixPQUFPLE1BQU0sU0FBUyxLQUFLO0FBQUEsSUFDcEQ7QUFBQSxJQUNBLHdCQUF3QixTQUFTO0FBQzdCLFlBQU0saUJBQWlCLEtBQUssZ0JBQWdCLGdCQUFnQixPQUFPO0FBQ25FLFlBQU0sZ0JBQWdCLEtBQUsscUJBQXFCLE9BQU87QUFDdkQsWUFBTSxzQkFBc0IsSUFBSSxnQkFBZ0IsYUFBYSxFQUFFLFVBQVUsQ0FBQyxDQUFDLGVBQWUsWUFBWSxNQUFNLENBQUMsZUFBZSxlQUFlLFlBQVksQ0FBQztBQUN4SixVQUFJLHVCQUF1QixJQUFJO0FBQzNCLGVBQU8sQ0FBQyxDQUFDLEdBQUcsQ0FBQyxDQUFDO0FBQUEsTUFDbEIsT0FDSztBQUNELGVBQU8sQ0FBQyxlQUFlLE1BQU0sbUJBQW1CLEdBQUcsY0FBYyxNQUFNLG1CQUFtQixDQUFDO0FBQUEsTUFDL0Y7QUFBQSxJQUNKO0FBQUEsSUFDQSxxQkFBcUIsU0FBUztBQUMxQixZQUFNLGdCQUFnQixLQUFLO0FBQzNCLFlBQU0sY0FBYyxRQUFRLGFBQWEsYUFBYSxLQUFLO0FBQzNELGFBQU8saUJBQWlCLGFBQWEsU0FBUyxhQUFhO0FBQUEsSUFDL0Q7QUFBQSxFQUNKO0FBQ0EsV0FBUyxpQkFBaUIsYUFBYSxTQUFTLGVBQWU7QUFDM0QsV0FBTyxZQUNGLEtBQUssRUFDTCxNQUFNLEtBQUssRUFDWCxPQUFPLENBQUMsWUFBWSxRQUFRLE1BQU0sRUFDbEMsSUFBSSxDQUFDLFNBQVMsV0FBVyxFQUFFLFNBQVMsZUFBZSxTQUFTLE1BQU0sRUFBRTtBQUFBLEVBQzdFO0FBQ0EsV0FBUyxJQUFJLE1BQU0sT0FBTztBQUN0QixVQUFNLFNBQVMsS0FBSyxJQUFJLEtBQUssUUFBUSxNQUFNLE1BQU07QUFDakQsV0FBTyxNQUFNLEtBQUssRUFBRSxPQUFPLEdBQUcsQ0FBQyxHQUFHLFVBQVUsQ0FBQyxLQUFLLEtBQUssR0FBRyxNQUFNLEtBQUssQ0FBQyxDQUFDO0FBQUEsRUFDM0U7QUFDQSxXQUFTLGVBQWUsTUFBTSxPQUFPO0FBQ2pDLFdBQU8sUUFBUSxTQUFTLEtBQUssU0FBUyxNQUFNLFNBQVMsS0FBSyxXQUFXLE1BQU07QUFBQSxFQUMvRTtBQUVBLE1BQU0sb0JBQU4sTUFBd0I7QUFBQSxJQUNwQixZQUFZLFNBQVMsZUFBZSxVQUFVO0FBQzFDLFdBQUssb0JBQW9CLElBQUksa0JBQWtCLFNBQVMsZUFBZSxJQUFJO0FBQzNFLFdBQUssV0FBVztBQUNoQixXQUFLLHNCQUFzQixvQkFBSSxRQUFRO0FBQ3ZDLFdBQUsseUJBQXlCLG9CQUFJLFFBQVE7QUFBQSxJQUM5QztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLGtCQUFrQjtBQUFBLElBQ2xDO0FBQUEsSUFDQSxRQUFRO0FBQ0osV0FBSyxrQkFBa0IsTUFBTTtBQUFBLElBQ2pDO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxrQkFBa0IsS0FBSztBQUFBLElBQ2hDO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxrQkFBa0IsUUFBUTtBQUFBLElBQ25DO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssa0JBQWtCO0FBQUEsSUFDbEM7QUFBQSxJQUNBLElBQUksZ0JBQWdCO0FBQ2hCLGFBQU8sS0FBSyxrQkFBa0I7QUFBQSxJQUNsQztBQUFBLElBQ0EsYUFBYSxPQUFPO0FBQ2hCLFlBQU0sRUFBRSxRQUFRLElBQUk7QUFDcEIsWUFBTSxFQUFFLE1BQU0sSUFBSSxLQUFLLHlCQUF5QixLQUFLO0FBQ3JELFVBQUksT0FBTztBQUNQLGFBQUssNkJBQTZCLE9BQU8sRUFBRSxJQUFJLE9BQU8sS0FBSztBQUMzRCxhQUFLLFNBQVMsb0JBQW9CLFNBQVMsS0FBSztBQUFBLE1BQ3BEO0FBQUEsSUFDSjtBQUFBLElBQ0EsZUFBZSxPQUFPO0FBQ2xCLFlBQU0sRUFBRSxRQUFRLElBQUk7QUFDcEIsWUFBTSxFQUFFLE1BQU0sSUFBSSxLQUFLLHlCQUF5QixLQUFLO0FBQ3JELFVBQUksT0FBTztBQUNQLGFBQUssNkJBQTZCLE9BQU8sRUFBRSxPQUFPLEtBQUs7QUFDdkQsYUFBSyxTQUFTLHNCQUFzQixTQUFTLEtBQUs7QUFBQSxNQUN0RDtBQUFBLElBQ0o7QUFBQSxJQUNBLHlCQUF5QixPQUFPO0FBQzVCLFVBQUksY0FBYyxLQUFLLG9CQUFvQixJQUFJLEtBQUs7QUFDcEQsVUFBSSxDQUFDLGFBQWE7QUFDZCxzQkFBYyxLQUFLLFdBQVcsS0FBSztBQUNuQyxhQUFLLG9CQUFvQixJQUFJLE9BQU8sV0FBVztBQUFBLE1BQ25EO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLDZCQUE2QixTQUFTO0FBQ2xDLFVBQUksZ0JBQWdCLEtBQUssdUJBQXVCLElBQUksT0FBTztBQUMzRCxVQUFJLENBQUMsZUFBZTtBQUNoQix3QkFBZ0Isb0JBQUksSUFBSTtBQUN4QixhQUFLLHVCQUF1QixJQUFJLFNBQVMsYUFBYTtBQUFBLE1BQzFEO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLFdBQVcsT0FBTztBQUNkLFVBQUk7QUFDQSxjQUFNLFFBQVEsS0FBSyxTQUFTLG1CQUFtQixLQUFLO0FBQ3BELGVBQU8sRUFBRSxNQUFNO0FBQUEsTUFDbkIsU0FDT0MsUUFBTztBQUNWLGVBQU8sRUFBRSxPQUFBQSxPQUFNO0FBQUEsTUFDbkI7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLE1BQU0sa0JBQU4sTUFBc0I7QUFBQSxJQUNsQixZQUFZLFNBQVMsVUFBVTtBQUMzQixXQUFLLFVBQVU7QUFDZixXQUFLLFdBQVc7QUFDaEIsV0FBSyxtQkFBbUIsb0JBQUksSUFBSTtBQUFBLElBQ3BDO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssbUJBQW1CO0FBQ3pCLGFBQUssb0JBQW9CLElBQUksa0JBQWtCLEtBQUssU0FBUyxLQUFLLGlCQUFpQixJQUFJO0FBQ3ZGLGFBQUssa0JBQWtCLE1BQU07QUFBQSxNQUNqQztBQUFBLElBQ0o7QUFBQSxJQUNBLE9BQU87QUFDSCxVQUFJLEtBQUssbUJBQW1CO0FBQ3hCLGFBQUssa0JBQWtCLEtBQUs7QUFDNUIsZUFBTyxLQUFLO0FBQ1osYUFBSyxxQkFBcUI7QUFBQSxNQUM5QjtBQUFBLElBQ0o7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksa0JBQWtCO0FBQ2xCLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksU0FBUztBQUNULGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sTUFBTSxLQUFLLEtBQUssaUJBQWlCLE9BQU8sQ0FBQztBQUFBLElBQ3BEO0FBQUEsSUFDQSxjQUFjLFFBQVE7QUFDbEIsWUFBTSxVQUFVLElBQUksUUFBUSxLQUFLLFNBQVMsTUFBTTtBQUNoRCxXQUFLLGlCQUFpQixJQUFJLFFBQVEsT0FBTztBQUN6QyxXQUFLLFNBQVMsaUJBQWlCLE9BQU87QUFBQSxJQUMxQztBQUFBLElBQ0EsaUJBQWlCLFFBQVE7QUFDckIsWUFBTSxVQUFVLEtBQUssaUJBQWlCLElBQUksTUFBTTtBQUNoRCxVQUFJLFNBQVM7QUFDVCxhQUFLLGlCQUFpQixPQUFPLE1BQU07QUFDbkMsYUFBSyxTQUFTLG9CQUFvQixPQUFPO0FBQUEsTUFDN0M7QUFBQSxJQUNKO0FBQUEsSUFDQSx1QkFBdUI7QUFDbkIsV0FBSyxTQUFTLFFBQVEsQ0FBQyxZQUFZLEtBQUssU0FBUyxvQkFBb0IsU0FBUyxJQUFJLENBQUM7QUFDbkYsV0FBSyxpQkFBaUIsTUFBTTtBQUFBLElBQ2hDO0FBQUEsSUFDQSxtQkFBbUIsT0FBTztBQUN0QixZQUFNLFNBQVMsT0FBTyxTQUFTLE9BQU8sS0FBSyxNQUFNO0FBQ2pELFVBQUksT0FBTyxjQUFjLEtBQUssWUFBWTtBQUN0QyxlQUFPO0FBQUEsTUFDWDtBQUFBLElBQ0o7QUFBQSxJQUNBLG9CQUFvQixTQUFTLFFBQVE7QUFDakMsV0FBSyxjQUFjLE1BQU07QUFBQSxJQUM3QjtBQUFBLElBQ0Esc0JBQXNCLFNBQVMsUUFBUTtBQUNuQyxXQUFLLGlCQUFpQixNQUFNO0FBQUEsSUFDaEM7QUFBQSxFQUNKO0FBRUEsTUFBTSxnQkFBTixNQUFvQjtBQUFBLElBQ2hCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssVUFBVTtBQUNmLFdBQUssV0FBVztBQUNoQixXQUFLLG9CQUFvQixJQUFJLGtCQUFrQixLQUFLLFNBQVMsSUFBSTtBQUNqRSxXQUFLLHFCQUFxQixLQUFLLFdBQVc7QUFBQSxJQUM5QztBQUFBLElBQ0EsUUFBUTtBQUNKLFdBQUssa0JBQWtCLE1BQU07QUFDN0IsV0FBSyx1Q0FBdUM7QUFBQSxJQUNoRDtBQUFBLElBQ0EsT0FBTztBQUNILFdBQUssa0JBQWtCLEtBQUs7QUFBQSxJQUNoQztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsNEJBQTRCLGVBQWU7QUFDdkMsVUFBSSxpQkFBaUIsS0FBSyxvQkFBb0I7QUFDMUMsZUFBTyxLQUFLLG1CQUFtQixhQUFhLEVBQUU7QUFBQSxNQUNsRDtBQUFBLElBQ0o7QUFBQSxJQUNBLGtCQUFrQixLQUFLLGVBQWU7QUFDbEMsWUFBTSxhQUFhLEtBQUssbUJBQW1CLGFBQWE7QUFDeEQsVUFBSSxDQUFDLEtBQUssU0FBUyxHQUFHLEdBQUc7QUFDckIsYUFBSyxzQkFBc0IsS0FBSyxXQUFXLE9BQU8sS0FBSyxTQUFTLEdBQUcsQ0FBQyxHQUFHLFdBQVcsT0FBTyxXQUFXLFlBQVksQ0FBQztBQUFBLE1BQ3JIO0FBQUEsSUFDSjtBQUFBLElBQ0Esc0JBQXNCLE9BQU8sTUFBTSxVQUFVO0FBQ3pDLFlBQU0sYUFBYSxLQUFLLHVCQUF1QixJQUFJO0FBQ25ELFVBQUksVUFBVTtBQUNWO0FBQ0osVUFBSSxhQUFhLE1BQU07QUFDbkIsbUJBQVcsV0FBVyxPQUFPLFdBQVcsWUFBWTtBQUFBLE1BQ3hEO0FBQ0EsV0FBSyxzQkFBc0IsTUFBTSxPQUFPLFFBQVE7QUFBQSxJQUNwRDtBQUFBLElBQ0Esb0JBQW9CLEtBQUssZUFBZSxVQUFVO0FBQzlDLFlBQU0sYUFBYSxLQUFLLHVCQUF1QixHQUFHO0FBQ2xELFVBQUksS0FBSyxTQUFTLEdBQUcsR0FBRztBQUNwQixhQUFLLHNCQUFzQixLQUFLLFdBQVcsT0FBTyxLQUFLLFNBQVMsR0FBRyxDQUFDLEdBQUcsUUFBUTtBQUFBLE1BQ25GLE9BQ0s7QUFDRCxhQUFLLHNCQUFzQixLQUFLLFdBQVcsT0FBTyxXQUFXLFlBQVksR0FBRyxRQUFRO0FBQUEsTUFDeEY7QUFBQSxJQUNKO0FBQUEsSUFDQSx5Q0FBeUM7QUFDckMsaUJBQVcsRUFBRSxLQUFLLE1BQU0sY0FBYyxPQUFPLEtBQUssS0FBSyxrQkFBa0I7QUFDckUsWUFBSSxnQkFBZ0IsVUFBYSxDQUFDLEtBQUssV0FBVyxLQUFLLElBQUksR0FBRyxHQUFHO0FBQzdELGVBQUssc0JBQXNCLE1BQU0sT0FBTyxZQUFZLEdBQUcsTUFBUztBQUFBLFFBQ3BFO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLHNCQUFzQixNQUFNLFVBQVUsYUFBYTtBQUMvQyxZQUFNLG9CQUFvQixHQUFHLElBQUk7QUFDakMsWUFBTSxnQkFBZ0IsS0FBSyxTQUFTLGlCQUFpQjtBQUNyRCxVQUFJLE9BQU8saUJBQWlCLFlBQVk7QUFDcEMsY0FBTSxhQUFhLEtBQUssdUJBQXVCLElBQUk7QUFDbkQsWUFBSTtBQUNBLGdCQUFNLFFBQVEsV0FBVyxPQUFPLFFBQVE7QUFDeEMsY0FBSSxXQUFXO0FBQ2YsY0FBSSxhQUFhO0FBQ2IsdUJBQVcsV0FBVyxPQUFPLFdBQVc7QUFBQSxVQUM1QztBQUNBLHdCQUFjLEtBQUssS0FBSyxVQUFVLE9BQU8sUUFBUTtBQUFBLFFBQ3JELFNBQ09BLFFBQU87QUFDVixjQUFJQSxrQkFBaUIsV0FBVztBQUM1QixZQUFBQSxPQUFNLFVBQVUsbUJBQW1CLEtBQUssUUFBUSxVQUFVLElBQUksV0FBVyxJQUFJLE9BQU9BLE9BQU0sT0FBTztBQUFBLFVBQ3JHO0FBQ0EsZ0JBQU1BO0FBQUEsUUFDVjtBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLG1CQUFtQjtBQUNuQixZQUFNLEVBQUUsbUJBQW1CLElBQUk7QUFDL0IsYUFBTyxPQUFPLEtBQUssa0JBQWtCLEVBQUUsSUFBSSxDQUFDLFFBQVEsbUJBQW1CLEdBQUcsQ0FBQztBQUFBLElBQy9FO0FBQUEsSUFDQSxJQUFJLHlCQUF5QjtBQUN6QixZQUFNLGNBQWMsQ0FBQztBQUNyQixhQUFPLEtBQUssS0FBSyxrQkFBa0IsRUFBRSxRQUFRLENBQUMsUUFBUTtBQUNsRCxjQUFNLGFBQWEsS0FBSyxtQkFBbUIsR0FBRztBQUM5QyxvQkFBWSxXQUFXLElBQUksSUFBSTtBQUFBLE1BQ25DLENBQUM7QUFDRCxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsU0FBUyxlQUFlO0FBQ3BCLFlBQU0sYUFBYSxLQUFLLHVCQUF1QixhQUFhO0FBQzVELFlBQU0sZ0JBQWdCLE1BQU0sV0FBVyxXQUFXLElBQUksQ0FBQztBQUN2RCxhQUFPLEtBQUssU0FBUyxhQUFhO0FBQUEsSUFDdEM7QUFBQSxFQUNKO0FBRUEsTUFBTSxpQkFBTixNQUFxQjtBQUFBLElBQ2pCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssVUFBVTtBQUNmLFdBQUssV0FBVztBQUNoQixXQUFLLGdCQUFnQixJQUFJLFNBQVM7QUFBQSxJQUN0QztBQUFBLElBQ0EsUUFBUTtBQUNKLFVBQUksQ0FBQyxLQUFLLG1CQUFtQjtBQUN6QixhQUFLLG9CQUFvQixJQUFJLGtCQUFrQixLQUFLLFNBQVMsS0FBSyxlQUFlLElBQUk7QUFDckYsYUFBSyxrQkFBa0IsTUFBTTtBQUFBLE1BQ2pDO0FBQUEsSUFDSjtBQUFBLElBQ0EsT0FBTztBQUNILFVBQUksS0FBSyxtQkFBbUI7QUFDeEIsYUFBSyxxQkFBcUI7QUFDMUIsYUFBSyxrQkFBa0IsS0FBSztBQUM1QixlQUFPLEtBQUs7QUFBQSxNQUNoQjtBQUFBLElBQ0o7QUFBQSxJQUNBLGFBQWEsRUFBRSxTQUFTLFNBQVMsS0FBSyxHQUFHO0FBQ3JDLFVBQUksS0FBSyxNQUFNLGdCQUFnQixPQUFPLEdBQUc7QUFDckMsYUFBSyxjQUFjLFNBQVMsSUFBSTtBQUFBLE1BQ3BDO0FBQUEsSUFDSjtBQUFBLElBQ0EsZUFBZSxFQUFFLFNBQVMsU0FBUyxLQUFLLEdBQUc7QUFDdkMsV0FBSyxpQkFBaUIsU0FBUyxJQUFJO0FBQUEsSUFDdkM7QUFBQSxJQUNBLGNBQWMsU0FBUyxNQUFNO0FBQ3pCLFVBQUk7QUFDSixVQUFJLENBQUMsS0FBSyxjQUFjLElBQUksTUFBTSxPQUFPLEdBQUc7QUFDeEMsYUFBSyxjQUFjLElBQUksTUFBTSxPQUFPO0FBQ3BDLFNBQUMsS0FBSyxLQUFLLHVCQUF1QixRQUFRLE9BQU8sU0FBUyxTQUFTLEdBQUcsTUFBTSxNQUFNLEtBQUssU0FBUyxnQkFBZ0IsU0FBUyxJQUFJLENBQUM7QUFBQSxNQUNsSTtBQUFBLElBQ0o7QUFBQSxJQUNBLGlCQUFpQixTQUFTLE1BQU07QUFDNUIsVUFBSTtBQUNKLFVBQUksS0FBSyxjQUFjLElBQUksTUFBTSxPQUFPLEdBQUc7QUFDdkMsYUFBSyxjQUFjLE9BQU8sTUFBTSxPQUFPO0FBQ3ZDLFNBQUMsS0FBSyxLQUFLLHVCQUF1QixRQUFRLE9BQU8sU0FBUyxTQUFTLEdBQUcsTUFBTSxNQUFNLEtBQUssU0FBUyxtQkFBbUIsU0FBUyxJQUFJLENBQUM7QUFBQSxNQUNySTtBQUFBLElBQ0o7QUFBQSxJQUNBLHVCQUF1QjtBQUNuQixpQkFBVyxRQUFRLEtBQUssY0FBYyxNQUFNO0FBQ3hDLG1CQUFXLFdBQVcsS0FBSyxjQUFjLGdCQUFnQixJQUFJLEdBQUc7QUFDNUQsZUFBSyxpQkFBaUIsU0FBUyxJQUFJO0FBQUEsUUFDdkM7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsSUFBSSxnQkFBZ0I7QUFDaEIsYUFBTyxRQUFRLEtBQUssUUFBUSxVQUFVO0FBQUEsSUFDMUM7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksUUFBUTtBQUNSLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxFQUNKO0FBRUEsV0FBUyxpQ0FBaUMsYUFBYSxjQUFjO0FBQ2pFLFVBQU0sWUFBWSwyQkFBMkIsV0FBVztBQUN4RCxXQUFPLE1BQU0sS0FBSyxVQUFVLE9BQU8sQ0FBQyxRQUFRQyxpQkFBZ0I7QUFDeEQsOEJBQXdCQSxjQUFhLFlBQVksRUFBRSxRQUFRLENBQUMsU0FBUyxPQUFPLElBQUksSUFBSSxDQUFDO0FBQ3JGLGFBQU87QUFBQSxJQUNYLEdBQUcsb0JBQUksSUFBSSxDQUFDLENBQUM7QUFBQSxFQUNqQjtBQUNBLFdBQVMsaUNBQWlDLGFBQWEsY0FBYztBQUNqRSxVQUFNLFlBQVksMkJBQTJCLFdBQVc7QUFDeEQsV0FBTyxVQUFVLE9BQU8sQ0FBQyxPQUFPQSxpQkFBZ0I7QUFDNUMsWUFBTSxLQUFLLEdBQUcsd0JBQXdCQSxjQUFhLFlBQVksQ0FBQztBQUNoRSxhQUFPO0FBQUEsSUFDWCxHQUFHLENBQUMsQ0FBQztBQUFBLEVBQ1Q7QUFDQSxXQUFTLDJCQUEyQixhQUFhO0FBQzdDLFVBQU0sWUFBWSxDQUFDO0FBQ25CLFdBQU8sYUFBYTtBQUNoQixnQkFBVSxLQUFLLFdBQVc7QUFDMUIsb0JBQWMsT0FBTyxlQUFlLFdBQVc7QUFBQSxJQUNuRDtBQUNBLFdBQU8sVUFBVSxRQUFRO0FBQUEsRUFDN0I7QUFDQSxXQUFTLHdCQUF3QixhQUFhLGNBQWM7QUFDeEQsVUFBTSxhQUFhLFlBQVksWUFBWTtBQUMzQyxXQUFPLE1BQU0sUUFBUSxVQUFVLElBQUksYUFBYSxDQUFDO0FBQUEsRUFDckQ7QUFDQSxXQUFTLHdCQUF3QixhQUFhLGNBQWM7QUFDeEQsVUFBTSxhQUFhLFlBQVksWUFBWTtBQUMzQyxXQUFPLGFBQWEsT0FBTyxLQUFLLFVBQVUsRUFBRSxJQUFJLENBQUMsUUFBUSxDQUFDLEtBQUssV0FBVyxHQUFHLENBQUMsQ0FBQyxJQUFJLENBQUM7QUFBQSxFQUN4RjtBQUVBLE1BQU0saUJBQU4sTUFBcUI7QUFBQSxJQUNqQixZQUFZLFNBQVMsVUFBVTtBQUMzQixXQUFLLFVBQVU7QUFDZixXQUFLLFVBQVU7QUFDZixXQUFLLFdBQVc7QUFDaEIsV0FBSyxnQkFBZ0IsSUFBSSxTQUFTO0FBQ2xDLFdBQUssdUJBQXVCLElBQUksU0FBUztBQUN6QyxXQUFLLHNCQUFzQixvQkFBSSxJQUFJO0FBQ25DLFdBQUssdUJBQXVCLG9CQUFJLElBQUk7QUFBQSxJQUN4QztBQUFBLElBQ0EsUUFBUTtBQUNKLFVBQUksQ0FBQyxLQUFLLFNBQVM7QUFDZixhQUFLLGtCQUFrQixRQUFRLENBQUMsZUFBZTtBQUMzQyxlQUFLLCtCQUErQixVQUFVO0FBQzlDLGVBQUssZ0NBQWdDLFVBQVU7QUFBQSxRQUNuRCxDQUFDO0FBQ0QsYUFBSyxVQUFVO0FBQ2YsYUFBSyxrQkFBa0IsUUFBUSxDQUFDLFlBQVksUUFBUSxRQUFRLENBQUM7QUFBQSxNQUNqRTtBQUFBLElBQ0o7QUFBQSxJQUNBLFVBQVU7QUFDTixXQUFLLG9CQUFvQixRQUFRLENBQUMsYUFBYSxTQUFTLFFBQVEsQ0FBQztBQUNqRSxXQUFLLHFCQUFxQixRQUFRLENBQUMsYUFBYSxTQUFTLFFBQVEsQ0FBQztBQUFBLElBQ3RFO0FBQUEsSUFDQSxPQUFPO0FBQ0gsVUFBSSxLQUFLLFNBQVM7QUFDZCxhQUFLLFVBQVU7QUFDZixhQUFLLHFCQUFxQjtBQUMxQixhQUFLLHNCQUFzQjtBQUMzQixhQUFLLHVCQUF1QjtBQUFBLE1BQ2hDO0FBQUEsSUFDSjtBQUFBLElBQ0Esd0JBQXdCO0FBQ3BCLFVBQUksS0FBSyxvQkFBb0IsT0FBTyxHQUFHO0FBQ25DLGFBQUssb0JBQW9CLFFBQVEsQ0FBQyxhQUFhLFNBQVMsS0FBSyxDQUFDO0FBQzlELGFBQUssb0JBQW9CLE1BQU07QUFBQSxNQUNuQztBQUFBLElBQ0o7QUFBQSxJQUNBLHlCQUF5QjtBQUNyQixVQUFJLEtBQUsscUJBQXFCLE9BQU8sR0FBRztBQUNwQyxhQUFLLHFCQUFxQixRQUFRLENBQUMsYUFBYSxTQUFTLEtBQUssQ0FBQztBQUMvRCxhQUFLLHFCQUFxQixNQUFNO0FBQUEsTUFDcEM7QUFBQSxJQUNKO0FBQUEsSUFDQSxnQkFBZ0IsU0FBUyxXQUFXLEVBQUUsV0FBVyxHQUFHO0FBQ2hELFlBQU0sU0FBUyxLQUFLLFVBQVUsU0FBUyxVQUFVO0FBQ2pELFVBQUksUUFBUTtBQUNSLGFBQUssY0FBYyxRQUFRLFNBQVMsVUFBVTtBQUFBLE1BQ2xEO0FBQUEsSUFDSjtBQUFBLElBQ0Esa0JBQWtCLFNBQVMsV0FBVyxFQUFFLFdBQVcsR0FBRztBQUNsRCxZQUFNLFNBQVMsS0FBSyxpQkFBaUIsU0FBUyxVQUFVO0FBQ3hELFVBQUksUUFBUTtBQUNSLGFBQUssaUJBQWlCLFFBQVEsU0FBUyxVQUFVO0FBQUEsTUFDckQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxxQkFBcUIsU0FBUyxFQUFFLFdBQVcsR0FBRztBQUMxQyxZQUFNLFdBQVcsS0FBSyxTQUFTLFVBQVU7QUFDekMsWUFBTSxZQUFZLEtBQUssVUFBVSxTQUFTLFVBQVU7QUFDcEQsWUFBTSxzQkFBc0IsUUFBUSxRQUFRLElBQUksS0FBSyxPQUFPLG1CQUFtQixLQUFLLFVBQVUsR0FBRztBQUNqRyxVQUFJLFVBQVU7QUFDVixlQUFPLGFBQWEsdUJBQXVCLFFBQVEsUUFBUSxRQUFRO0FBQUEsTUFDdkUsT0FDSztBQUNELGVBQU87QUFBQSxNQUNYO0FBQUEsSUFDSjtBQUFBLElBQ0Esd0JBQXdCLFVBQVUsZUFBZTtBQUM3QyxZQUFNLGFBQWEsS0FBSyxxQ0FBcUMsYUFBYTtBQUMxRSxVQUFJLFlBQVk7QUFDWixhQUFLLGdDQUFnQyxVQUFVO0FBQUEsTUFDbkQ7QUFBQSxJQUNKO0FBQUEsSUFDQSw2QkFBNkIsVUFBVSxlQUFlO0FBQ2xELFlBQU0sYUFBYSxLQUFLLHFDQUFxQyxhQUFhO0FBQzFFLFVBQUksWUFBWTtBQUNaLGFBQUssZ0NBQWdDLFVBQVU7QUFBQSxNQUNuRDtBQUFBLElBQ0o7QUFBQSxJQUNBLDBCQUEwQixVQUFVLGVBQWU7QUFDL0MsWUFBTSxhQUFhLEtBQUsscUNBQXFDLGFBQWE7QUFDMUUsVUFBSSxZQUFZO0FBQ1osYUFBSyxnQ0FBZ0MsVUFBVTtBQUFBLE1BQ25EO0FBQUEsSUFDSjtBQUFBLElBQ0EsY0FBYyxRQUFRLFNBQVMsWUFBWTtBQUN2QyxVQUFJO0FBQ0osVUFBSSxDQUFDLEtBQUsscUJBQXFCLElBQUksWUFBWSxPQUFPLEdBQUc7QUFDckQsYUFBSyxjQUFjLElBQUksWUFBWSxNQUFNO0FBQ3pDLGFBQUsscUJBQXFCLElBQUksWUFBWSxPQUFPO0FBQ2pELFNBQUMsS0FBSyxLQUFLLG9CQUFvQixJQUFJLFVBQVUsT0FBTyxRQUFRLE9BQU8sU0FBUyxTQUFTLEdBQUcsTUFBTSxNQUFNLEtBQUssU0FBUyxnQkFBZ0IsUUFBUSxTQUFTLFVBQVUsQ0FBQztBQUFBLE1BQ2xLO0FBQUEsSUFDSjtBQUFBLElBQ0EsaUJBQWlCLFFBQVEsU0FBUyxZQUFZO0FBQzFDLFVBQUk7QUFDSixVQUFJLEtBQUsscUJBQXFCLElBQUksWUFBWSxPQUFPLEdBQUc7QUFDcEQsYUFBSyxjQUFjLE9BQU8sWUFBWSxNQUFNO0FBQzVDLGFBQUsscUJBQXFCLE9BQU8sWUFBWSxPQUFPO0FBQ3BELFNBQUMsS0FBSyxLQUFLLG9CQUNOLElBQUksVUFBVSxPQUFPLFFBQVEsT0FBTyxTQUFTLFNBQVMsR0FBRyxNQUFNLE1BQU0sS0FBSyxTQUFTLG1CQUFtQixRQUFRLFNBQVMsVUFBVSxDQUFDO0FBQUEsTUFDM0k7QUFBQSxJQUNKO0FBQUEsSUFDQSx1QkFBdUI7QUFDbkIsaUJBQVcsY0FBYyxLQUFLLHFCQUFxQixNQUFNO0FBQ3JELG1CQUFXLFdBQVcsS0FBSyxxQkFBcUIsZ0JBQWdCLFVBQVUsR0FBRztBQUN6RSxxQkFBVyxVQUFVLEtBQUssY0FBYyxnQkFBZ0IsVUFBVSxHQUFHO0FBQ2pFLGlCQUFLLGlCQUFpQixRQUFRLFNBQVMsVUFBVTtBQUFBLFVBQ3JEO0FBQUEsUUFDSjtBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxnQ0FBZ0MsWUFBWTtBQUN4QyxZQUFNLFdBQVcsS0FBSyxvQkFBb0IsSUFBSSxVQUFVO0FBQ3hELFVBQUksVUFBVTtBQUNWLGlCQUFTLFdBQVcsS0FBSyxTQUFTLFVBQVU7QUFBQSxNQUNoRDtBQUFBLElBQ0o7QUFBQSxJQUNBLCtCQUErQixZQUFZO0FBQ3ZDLFlBQU0sV0FBVyxLQUFLLFNBQVMsVUFBVTtBQUN6QyxZQUFNLG1CQUFtQixJQUFJLGlCQUFpQixTQUFTLE1BQU0sVUFBVSxNQUFNLEVBQUUsV0FBVyxDQUFDO0FBQzNGLFdBQUssb0JBQW9CLElBQUksWUFBWSxnQkFBZ0I7QUFDekQsdUJBQWlCLE1BQU07QUFBQSxJQUMzQjtBQUFBLElBQ0EsZ0NBQWdDLFlBQVk7QUFDeEMsWUFBTSxnQkFBZ0IsS0FBSywyQkFBMkIsVUFBVTtBQUNoRSxZQUFNLG9CQUFvQixJQUFJLGtCQUFrQixLQUFLLE1BQU0sU0FBUyxlQUFlLElBQUk7QUFDdkYsV0FBSyxxQkFBcUIsSUFBSSxZQUFZLGlCQUFpQjtBQUMzRCx3QkFBa0IsTUFBTTtBQUFBLElBQzVCO0FBQUEsSUFDQSxTQUFTLFlBQVk7QUFDakIsYUFBTyxLQUFLLE1BQU0sUUFBUSx5QkFBeUIsVUFBVTtBQUFBLElBQ2pFO0FBQUEsSUFDQSwyQkFBMkIsWUFBWTtBQUNuQyxhQUFPLEtBQUssTUFBTSxPQUFPLHdCQUF3QixLQUFLLFlBQVksVUFBVTtBQUFBLElBQ2hGO0FBQUEsSUFDQSxxQ0FBcUMsZUFBZTtBQUNoRCxhQUFPLEtBQUssa0JBQWtCLEtBQUssQ0FBQyxlQUFlLEtBQUssMkJBQTJCLFVBQVUsTUFBTSxhQUFhO0FBQUEsSUFDcEg7QUFBQSxJQUNBLElBQUkscUJBQXFCO0FBQ3JCLFlBQU0sZUFBZSxJQUFJLFNBQVM7QUFDbEMsV0FBSyxPQUFPLFFBQVEsUUFBUSxDQUFDLFdBQVc7QUFDcEMsY0FBTSxjQUFjLE9BQU8sV0FBVztBQUN0QyxjQUFNLFVBQVUsaUNBQWlDLGFBQWEsU0FBUztBQUN2RSxnQkFBUSxRQUFRLENBQUMsV0FBVyxhQUFhLElBQUksUUFBUSxPQUFPLFVBQVUsQ0FBQztBQUFBLE1BQzNFLENBQUM7QUFDRCxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsSUFBSSxvQkFBb0I7QUFDcEIsYUFBTyxLQUFLLG1CQUFtQixnQkFBZ0IsS0FBSyxVQUFVO0FBQUEsSUFDbEU7QUFBQSxJQUNBLElBQUksaUNBQWlDO0FBQ2pDLGFBQU8sS0FBSyxtQkFBbUIsZ0JBQWdCLEtBQUssVUFBVTtBQUFBLElBQ2xFO0FBQUEsSUFDQSxJQUFJLG9CQUFvQjtBQUNwQixZQUFNLGNBQWMsS0FBSztBQUN6QixhQUFPLEtBQUssT0FBTyxTQUFTLE9BQU8sQ0FBQyxZQUFZLFlBQVksU0FBUyxRQUFRLFVBQVUsQ0FBQztBQUFBLElBQzVGO0FBQUEsSUFDQSxVQUFVLFNBQVMsWUFBWTtBQUMzQixhQUFPLENBQUMsQ0FBQyxLQUFLLFVBQVUsU0FBUyxVQUFVLEtBQUssQ0FBQyxDQUFDLEtBQUssaUJBQWlCLFNBQVMsVUFBVTtBQUFBLElBQy9GO0FBQUEsSUFDQSxVQUFVLFNBQVMsWUFBWTtBQUMzQixhQUFPLEtBQUssWUFBWSxxQ0FBcUMsU0FBUyxVQUFVO0FBQUEsSUFDcEY7QUFBQSxJQUNBLGlCQUFpQixTQUFTLFlBQVk7QUFDbEMsYUFBTyxLQUFLLGNBQWMsZ0JBQWdCLFVBQVUsRUFBRSxLQUFLLENBQUMsV0FBVyxPQUFPLFlBQVksT0FBTztBQUFBLElBQ3JHO0FBQUEsSUFDQSxJQUFJLFFBQVE7QUFDUixhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxJQUFJLGNBQWM7QUFDZCxhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxhQUFPLEtBQUssWUFBWTtBQUFBLElBQzVCO0FBQUEsRUFDSjtBQUVBLE1BQU0sVUFBTixNQUFjO0FBQUEsSUFDVixZQUFZLFFBQVEsT0FBTztBQUN2QixXQUFLLG1CQUFtQixDQUFDLGNBQWMsU0FBUyxDQUFDLE1BQU07QUFDbkQsY0FBTSxFQUFFLFlBQVksWUFBWSxRQUFRLElBQUk7QUFDNUMsaUJBQVMsT0FBTyxPQUFPLEVBQUUsWUFBWSxZQUFZLFFBQVEsR0FBRyxNQUFNO0FBQ2xFLGFBQUssWUFBWSxpQkFBaUIsS0FBSyxZQUFZLGNBQWMsTUFBTTtBQUFBLE1BQzNFO0FBQ0EsV0FBSyxTQUFTO0FBQ2QsV0FBSyxRQUFRO0FBQ2IsV0FBSyxhQUFhLElBQUksT0FBTyxzQkFBc0IsSUFBSTtBQUN2RCxXQUFLLGtCQUFrQixJQUFJLGdCQUFnQixNQUFNLEtBQUssVUFBVTtBQUNoRSxXQUFLLGdCQUFnQixJQUFJLGNBQWMsTUFBTSxLQUFLLFVBQVU7QUFDNUQsV0FBSyxpQkFBaUIsSUFBSSxlQUFlLE1BQU0sSUFBSTtBQUNuRCxXQUFLLGlCQUFpQixJQUFJLGVBQWUsTUFBTSxJQUFJO0FBQ25ELFVBQUk7QUFDQSxhQUFLLFdBQVcsV0FBVztBQUMzQixhQUFLLGlCQUFpQixZQUFZO0FBQUEsTUFDdEMsU0FDT0QsUUFBTztBQUNWLGFBQUssWUFBWUEsUUFBTyx5QkFBeUI7QUFBQSxNQUNyRDtBQUFBLElBQ0o7QUFBQSxJQUNBLFVBQVU7QUFDTixXQUFLLGdCQUFnQixNQUFNO0FBQzNCLFdBQUssY0FBYyxNQUFNO0FBQ3pCLFdBQUssZUFBZSxNQUFNO0FBQzFCLFdBQUssZUFBZSxNQUFNO0FBQzFCLFVBQUk7QUFDQSxhQUFLLFdBQVcsUUFBUTtBQUN4QixhQUFLLGlCQUFpQixTQUFTO0FBQUEsTUFDbkMsU0FDT0EsUUFBTztBQUNWLGFBQUssWUFBWUEsUUFBTyx1QkFBdUI7QUFBQSxNQUNuRDtBQUFBLElBQ0o7QUFBQSxJQUNBLFVBQVU7QUFDTixXQUFLLGVBQWUsUUFBUTtBQUFBLElBQ2hDO0FBQUEsSUFDQSxhQUFhO0FBQ1QsVUFBSTtBQUNBLGFBQUssV0FBVyxXQUFXO0FBQzNCLGFBQUssaUJBQWlCLFlBQVk7QUFBQSxNQUN0QyxTQUNPQSxRQUFPO0FBQ1YsYUFBSyxZQUFZQSxRQUFPLDBCQUEwQjtBQUFBLE1BQ3REO0FBQ0EsV0FBSyxlQUFlLEtBQUs7QUFDekIsV0FBSyxlQUFlLEtBQUs7QUFDekIsV0FBSyxjQUFjLEtBQUs7QUFDeEIsV0FBSyxnQkFBZ0IsS0FBSztBQUFBLElBQzlCO0FBQUEsSUFDQSxJQUFJLGNBQWM7QUFDZCxhQUFPLEtBQUssT0FBTztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssT0FBTztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxhQUFPLEtBQUssWUFBWTtBQUFBLElBQzVCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssWUFBWTtBQUFBLElBQzVCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLGdCQUFnQjtBQUNoQixhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxZQUFZQSxRQUFPLFNBQVMsU0FBUyxDQUFDLEdBQUc7QUFDckMsWUFBTSxFQUFFLFlBQVksWUFBWSxRQUFRLElBQUk7QUFDNUMsZUFBUyxPQUFPLE9BQU8sRUFBRSxZQUFZLFlBQVksUUFBUSxHQUFHLE1BQU07QUFDbEUsV0FBSyxZQUFZLFlBQVlBLFFBQU8sU0FBUyxPQUFPLElBQUksTUFBTTtBQUFBLElBQ2xFO0FBQUEsSUFDQSxnQkFBZ0IsU0FBUyxNQUFNO0FBQzNCLFdBQUssdUJBQXVCLEdBQUcsSUFBSSxtQkFBbUIsT0FBTztBQUFBLElBQ2pFO0FBQUEsSUFDQSxtQkFBbUIsU0FBUyxNQUFNO0FBQzlCLFdBQUssdUJBQXVCLEdBQUcsSUFBSSxzQkFBc0IsT0FBTztBQUFBLElBQ3BFO0FBQUEsSUFDQSxnQkFBZ0IsUUFBUSxTQUFTLE1BQU07QUFDbkMsV0FBSyx1QkFBdUIsR0FBRyxrQkFBa0IsSUFBSSxDQUFDLG1CQUFtQixRQUFRLE9BQU87QUFBQSxJQUM1RjtBQUFBLElBQ0EsbUJBQW1CLFFBQVEsU0FBUyxNQUFNO0FBQ3RDLFdBQUssdUJBQXVCLEdBQUcsa0JBQWtCLElBQUksQ0FBQyxzQkFBc0IsUUFBUSxPQUFPO0FBQUEsSUFDL0Y7QUFBQSxJQUNBLHVCQUF1QixlQUFlLE1BQU07QUFDeEMsWUFBTSxhQUFhLEtBQUs7QUFDeEIsVUFBSSxPQUFPLFdBQVcsVUFBVSxLQUFLLFlBQVk7QUFDN0MsbUJBQVcsVUFBVSxFQUFFLEdBQUcsSUFBSTtBQUFBLE1BQ2xDO0FBQUEsSUFDSjtBQUFBLEVBQ0o7QUFFQSxXQUFTLE1BQU0sYUFBYTtBQUN4QixXQUFPLE9BQU8sYUFBYSxxQkFBcUIsV0FBVyxDQUFDO0FBQUEsRUFDaEU7QUFDQSxXQUFTLE9BQU8sYUFBYSxZQUFZO0FBQ3JDLFVBQU0sb0JBQW9CLE9BQU8sV0FBVztBQUM1QyxVQUFNLG1CQUFtQixvQkFBb0IsWUFBWSxXQUFXLFVBQVU7QUFDOUUsV0FBTyxpQkFBaUIsa0JBQWtCLFdBQVcsZ0JBQWdCO0FBQ3JFLFdBQU87QUFBQSxFQUNYO0FBQ0EsV0FBUyxxQkFBcUIsYUFBYTtBQUN2QyxVQUFNLFlBQVksaUNBQWlDLGFBQWEsV0FBVztBQUMzRSxXQUFPLFVBQVUsT0FBTyxDQUFDLG1CQUFtQixhQUFhO0FBQ3JELFlBQU0sYUFBYSxTQUFTLFdBQVc7QUFDdkMsaUJBQVcsT0FBTyxZQUFZO0FBQzFCLGNBQU0sYUFBYSxrQkFBa0IsR0FBRyxLQUFLLENBQUM7QUFDOUMsMEJBQWtCLEdBQUcsSUFBSSxPQUFPLE9BQU8sWUFBWSxXQUFXLEdBQUcsQ0FBQztBQUFBLE1BQ3RFO0FBQ0EsYUFBTztBQUFBLElBQ1gsR0FBRyxDQUFDLENBQUM7QUFBQSxFQUNUO0FBQ0EsV0FBUyxvQkFBb0IsV0FBVyxZQUFZO0FBQ2hELFdBQU8sV0FBVyxVQUFVLEVBQUUsT0FBTyxDQUFDLGtCQUFrQixRQUFRO0FBQzVELFlBQU0sYUFBYSxzQkFBc0IsV0FBVyxZQUFZLEdBQUc7QUFDbkUsVUFBSSxZQUFZO0FBQ1osZUFBTyxPQUFPLGtCQUFrQixFQUFFLENBQUMsR0FBRyxHQUFHLFdBQVcsQ0FBQztBQUFBLE1BQ3pEO0FBQ0EsYUFBTztBQUFBLElBQ1gsR0FBRyxDQUFDLENBQUM7QUFBQSxFQUNUO0FBQ0EsV0FBUyxzQkFBc0IsV0FBVyxZQUFZLEtBQUs7QUFDdkQsVUFBTSxzQkFBc0IsT0FBTyx5QkFBeUIsV0FBVyxHQUFHO0FBQzFFLFVBQU0sa0JBQWtCLHVCQUF1QixXQUFXO0FBQzFELFFBQUksQ0FBQyxpQkFBaUI7QUFDbEIsWUFBTSxhQUFhLE9BQU8seUJBQXlCLFlBQVksR0FBRyxFQUFFO0FBQ3BFLFVBQUkscUJBQXFCO0FBQ3JCLG1CQUFXLE1BQU0sb0JBQW9CLE9BQU8sV0FBVztBQUN2RCxtQkFBVyxNQUFNLG9CQUFvQixPQUFPLFdBQVc7QUFBQSxNQUMzRDtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsRUFDSjtBQUNBLE1BQU0sY0FBYyxNQUFNO0FBQ3RCLFFBQUksT0FBTyxPQUFPLHlCQUF5QixZQUFZO0FBQ25ELGFBQU8sQ0FBQyxXQUFXLENBQUMsR0FBRyxPQUFPLG9CQUFvQixNQUFNLEdBQUcsR0FBRyxPQUFPLHNCQUFzQixNQUFNLENBQUM7QUFBQSxJQUN0RyxPQUNLO0FBQ0QsYUFBTyxPQUFPO0FBQUEsSUFDbEI7QUFBQSxFQUNKLEdBQUc7QUFDSCxNQUFNLFVBQVUsTUFBTTtBQUNsQixhQUFTLGtCQUFrQixhQUFhO0FBQ3BDLGVBQVMsV0FBVztBQUNoQixlQUFPLFFBQVEsVUFBVSxhQUFhLFdBQVcsVUFBVTtBQUFBLE1BQy9EO0FBQ0EsZUFBUyxZQUFZLE9BQU8sT0FBTyxZQUFZLFdBQVc7QUFBQSxRQUN0RCxhQUFhLEVBQUUsT0FBTyxTQUFTO0FBQUEsTUFDbkMsQ0FBQztBQUNELGNBQVEsZUFBZSxVQUFVLFdBQVc7QUFDNUMsYUFBTztBQUFBLElBQ1g7QUFDQSxhQUFTLHVCQUF1QjtBQUM1QixZQUFNLElBQUksV0FBWTtBQUNsQixhQUFLLEVBQUUsS0FBSyxJQUFJO0FBQUEsTUFDcEI7QUFDQSxZQUFNLElBQUksa0JBQWtCLENBQUM7QUFDN0IsUUFBRSxVQUFVLElBQUksV0FBWTtBQUFBLE1BQUU7QUFDOUIsYUFBTyxJQUFJLEVBQUU7QUFBQSxJQUNqQjtBQUNBLFFBQUk7QUFDQSwyQkFBcUI7QUFDckIsYUFBTztBQUFBLElBQ1gsU0FDT0EsUUFBTztBQUNWLGFBQU8sQ0FBQyxnQkFBZ0IsTUFBTSxpQkFBaUIsWUFBWTtBQUFBLE1BQzNEO0FBQUEsSUFDSjtBQUFBLEVBQ0osR0FBRztBQUVILFdBQVMsZ0JBQWdCLFlBQVk7QUFDakMsV0FBTztBQUFBLE1BQ0gsWUFBWSxXQUFXO0FBQUEsTUFDdkIsdUJBQXVCLE1BQU0sV0FBVyxxQkFBcUI7QUFBQSxJQUNqRTtBQUFBLEVBQ0o7QUFFQSxNQUFNLFNBQU4sTUFBYTtBQUFBLElBQ1QsWUFBWSxhQUFhLFlBQVk7QUFDakMsV0FBSyxjQUFjO0FBQ25CLFdBQUssYUFBYSxnQkFBZ0IsVUFBVTtBQUM1QyxXQUFLLGtCQUFrQixvQkFBSSxRQUFRO0FBQ25DLFdBQUssb0JBQW9CLG9CQUFJLElBQUk7QUFBQSxJQUNyQztBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLFdBQVc7QUFBQSxJQUMzQjtBQUFBLElBQ0EsSUFBSSx3QkFBd0I7QUFDeEIsYUFBTyxLQUFLLFdBQVc7QUFBQSxJQUMzQjtBQUFBLElBQ0EsSUFBSSxXQUFXO0FBQ1gsYUFBTyxNQUFNLEtBQUssS0FBSyxpQkFBaUI7QUFBQSxJQUM1QztBQUFBLElBQ0EsdUJBQXVCLE9BQU87QUFDMUIsWUFBTSxVQUFVLEtBQUsscUJBQXFCLEtBQUs7QUFDL0MsV0FBSyxrQkFBa0IsSUFBSSxPQUFPO0FBQ2xDLGNBQVEsUUFBUTtBQUFBLElBQ3BCO0FBQUEsSUFDQSwwQkFBMEIsT0FBTztBQUM3QixZQUFNLFVBQVUsS0FBSyxnQkFBZ0IsSUFBSSxLQUFLO0FBQzlDLFVBQUksU0FBUztBQUNULGFBQUssa0JBQWtCLE9BQU8sT0FBTztBQUNyQyxnQkFBUSxXQUFXO0FBQUEsTUFDdkI7QUFBQSxJQUNKO0FBQUEsSUFDQSxxQkFBcUIsT0FBTztBQUN4QixVQUFJLFVBQVUsS0FBSyxnQkFBZ0IsSUFBSSxLQUFLO0FBQzVDLFVBQUksQ0FBQyxTQUFTO0FBQ1Ysa0JBQVUsSUFBSSxRQUFRLE1BQU0sS0FBSztBQUNqQyxhQUFLLGdCQUFnQixJQUFJLE9BQU8sT0FBTztBQUFBLE1BQzNDO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxFQUNKO0FBRUEsTUFBTSxXQUFOLE1BQWU7QUFBQSxJQUNYLFlBQVksT0FBTztBQUNmLFdBQUssUUFBUTtBQUFBLElBQ2pCO0FBQUEsSUFDQSxJQUFJLE1BQU07QUFDTixhQUFPLEtBQUssS0FBSyxJQUFJLEtBQUssV0FBVyxJQUFJLENBQUM7QUFBQSxJQUM5QztBQUFBLElBQ0EsSUFBSSxNQUFNO0FBQ04sYUFBTyxLQUFLLE9BQU8sSUFBSSxFQUFFLENBQUM7QUFBQSxJQUM5QjtBQUFBLElBQ0EsT0FBTyxNQUFNO0FBQ1QsWUFBTSxjQUFjLEtBQUssS0FBSyxJQUFJLEtBQUssV0FBVyxJQUFJLENBQUMsS0FBSztBQUM1RCxhQUFPLFNBQVMsV0FBVztBQUFBLElBQy9CO0FBQUEsSUFDQSxpQkFBaUIsTUFBTTtBQUNuQixhQUFPLEtBQUssS0FBSyx1QkFBdUIsS0FBSyxXQUFXLElBQUksQ0FBQztBQUFBLElBQ2pFO0FBQUEsSUFDQSxXQUFXLE1BQU07QUFDYixhQUFPLEdBQUcsSUFBSTtBQUFBLElBQ2xCO0FBQUEsSUFDQSxJQUFJLE9BQU87QUFDUCxhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsRUFDSjtBQUVBLE1BQU0sVUFBTixNQUFjO0FBQUEsSUFDVixZQUFZLE9BQU87QUFDZixXQUFLLFFBQVE7QUFBQSxJQUNqQjtBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxLQUFLO0FBQ0wsWUFBTSxPQUFPLEtBQUssdUJBQXVCLEdBQUc7QUFDNUMsYUFBTyxLQUFLLFFBQVEsYUFBYSxJQUFJO0FBQUEsSUFDekM7QUFBQSxJQUNBLElBQUksS0FBSyxPQUFPO0FBQ1osWUFBTSxPQUFPLEtBQUssdUJBQXVCLEdBQUc7QUFDNUMsV0FBSyxRQUFRLGFBQWEsTUFBTSxLQUFLO0FBQ3JDLGFBQU8sS0FBSyxJQUFJLEdBQUc7QUFBQSxJQUN2QjtBQUFBLElBQ0EsSUFBSSxLQUFLO0FBQ0wsWUFBTSxPQUFPLEtBQUssdUJBQXVCLEdBQUc7QUFDNUMsYUFBTyxLQUFLLFFBQVEsYUFBYSxJQUFJO0FBQUEsSUFDekM7QUFBQSxJQUNBLE9BQU8sS0FBSztBQUNSLFVBQUksS0FBSyxJQUFJLEdBQUcsR0FBRztBQUNmLGNBQU0sT0FBTyxLQUFLLHVCQUF1QixHQUFHO0FBQzVDLGFBQUssUUFBUSxnQkFBZ0IsSUFBSTtBQUNqQyxlQUFPO0FBQUEsTUFDWCxPQUNLO0FBQ0QsZUFBTztBQUFBLE1BQ1g7QUFBQSxJQUNKO0FBQUEsSUFDQSx1QkFBdUIsS0FBSztBQUN4QixhQUFPLFFBQVEsS0FBSyxVQUFVLElBQUksVUFBVSxHQUFHLENBQUM7QUFBQSxJQUNwRDtBQUFBLEVBQ0o7QUFFQSxNQUFNLFFBQU4sTUFBWTtBQUFBLElBQ1IsWUFBWSxRQUFRO0FBQ2hCLFdBQUsscUJBQXFCLG9CQUFJLFFBQVE7QUFDdEMsV0FBSyxTQUFTO0FBQUEsSUFDbEI7QUFBQSxJQUNBLEtBQUssUUFBUSxLQUFLLFNBQVM7QUFDdkIsVUFBSSxhQUFhLEtBQUssbUJBQW1CLElBQUksTUFBTTtBQUNuRCxVQUFJLENBQUMsWUFBWTtBQUNiLHFCQUFhLG9CQUFJLElBQUk7QUFDckIsYUFBSyxtQkFBbUIsSUFBSSxRQUFRLFVBQVU7QUFBQSxNQUNsRDtBQUNBLFVBQUksQ0FBQyxXQUFXLElBQUksR0FBRyxHQUFHO0FBQ3RCLG1CQUFXLElBQUksR0FBRztBQUNsQixhQUFLLE9BQU8sS0FBSyxTQUFTLE1BQU07QUFBQSxNQUNwQztBQUFBLElBQ0o7QUFBQSxFQUNKO0FBRUEsV0FBUyw0QkFBNEIsZUFBZSxPQUFPO0FBQ3ZELFdBQU8sSUFBSSxhQUFhLE1BQU0sS0FBSztBQUFBLEVBQ3ZDO0FBRUEsTUFBTSxZQUFOLE1BQWdCO0FBQUEsSUFDWixZQUFZLE9BQU87QUFDZixXQUFLLFFBQVE7QUFBQSxJQUNqQjtBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxZQUFZO0FBQ1osYUFBTyxLQUFLLEtBQUssVUFBVSxLQUFLO0FBQUEsSUFDcEM7QUFBQSxJQUNBLFFBQVEsYUFBYTtBQUNqQixhQUFPLFlBQVksT0FBTyxDQUFDLFFBQVEsZUFBZSxVQUFVLEtBQUssV0FBVyxVQUFVLEtBQUssS0FBSyxpQkFBaUIsVUFBVSxHQUFHLE1BQVM7QUFBQSxJQUMzSTtBQUFBLElBQ0EsV0FBVyxhQUFhO0FBQ3BCLGFBQU8sWUFBWSxPQUFPLENBQUMsU0FBUyxlQUFlO0FBQUEsUUFDL0MsR0FBRztBQUFBLFFBQ0gsR0FBRyxLQUFLLGVBQWUsVUFBVTtBQUFBLFFBQ2pDLEdBQUcsS0FBSyxxQkFBcUIsVUFBVTtBQUFBLE1BQzNDLEdBQUcsQ0FBQyxDQUFDO0FBQUEsSUFDVDtBQUFBLElBQ0EsV0FBVyxZQUFZO0FBQ25CLFlBQU0sV0FBVyxLQUFLLHlCQUF5QixVQUFVO0FBQ3pELGFBQU8sS0FBSyxNQUFNLFlBQVksUUFBUTtBQUFBLElBQzFDO0FBQUEsSUFDQSxlQUFlLFlBQVk7QUFDdkIsWUFBTSxXQUFXLEtBQUsseUJBQXlCLFVBQVU7QUFDekQsYUFBTyxLQUFLLE1BQU0sZ0JBQWdCLFFBQVE7QUFBQSxJQUM5QztBQUFBLElBQ0EseUJBQXlCLFlBQVk7QUFDakMsWUFBTSxnQkFBZ0IsS0FBSyxPQUFPLHdCQUF3QixLQUFLLFVBQVU7QUFDekUsYUFBTyw0QkFBNEIsZUFBZSxVQUFVO0FBQUEsSUFDaEU7QUFBQSxJQUNBLGlCQUFpQixZQUFZO0FBQ3pCLFlBQU0sV0FBVyxLQUFLLCtCQUErQixVQUFVO0FBQy9ELGFBQU8sS0FBSyxVQUFVLEtBQUssTUFBTSxZQUFZLFFBQVEsR0FBRyxVQUFVO0FBQUEsSUFDdEU7QUFBQSxJQUNBLHFCQUFxQixZQUFZO0FBQzdCLFlBQU0sV0FBVyxLQUFLLCtCQUErQixVQUFVO0FBQy9ELGFBQU8sS0FBSyxNQUFNLGdCQUFnQixRQUFRLEVBQUUsSUFBSSxDQUFDLFlBQVksS0FBSyxVQUFVLFNBQVMsVUFBVSxDQUFDO0FBQUEsSUFDcEc7QUFBQSxJQUNBLCtCQUErQixZQUFZO0FBQ3ZDLFlBQU0sbUJBQW1CLEdBQUcsS0FBSyxVQUFVLElBQUksVUFBVTtBQUN6RCxhQUFPLDRCQUE0QixLQUFLLE9BQU8saUJBQWlCLGdCQUFnQjtBQUFBLElBQ3BGO0FBQUEsSUFDQSxVQUFVLFNBQVMsWUFBWTtBQUMzQixVQUFJLFNBQVM7QUFDVCxjQUFNLEVBQUUsV0FBVyxJQUFJO0FBQ3ZCLGNBQU0sZ0JBQWdCLEtBQUssT0FBTztBQUNsQyxjQUFNLHVCQUF1QixLQUFLLE9BQU8sd0JBQXdCLFVBQVU7QUFDM0UsYUFBSyxNQUFNLEtBQUssU0FBUyxVQUFVLFVBQVUsSUFBSSxrQkFBa0IsYUFBYSxLQUFLLFVBQVUsSUFBSSxVQUFVLFVBQVUsb0JBQW9CLEtBQUssVUFBVSxVQUMvSSxhQUFhLCtFQUErRTtBQUFBLE1BQzNHO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLElBQUksUUFBUTtBQUNSLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxFQUNKO0FBRUEsTUFBTSxZQUFOLE1BQWdCO0FBQUEsSUFDWixZQUFZLE9BQU8sbUJBQW1CO0FBQ2xDLFdBQUssUUFBUTtBQUNiLFdBQUssb0JBQW9CO0FBQUEsSUFDN0I7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksU0FBUztBQUNULGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksWUFBWTtBQUNaLGFBQU8sS0FBSyxLQUFLLFVBQVUsS0FBSztBQUFBLElBQ3BDO0FBQUEsSUFDQSxRQUFRLGFBQWE7QUFDakIsYUFBTyxZQUFZLE9BQU8sQ0FBQyxRQUFRLGVBQWUsVUFBVSxLQUFLLFdBQVcsVUFBVSxHQUFHLE1BQVM7QUFBQSxJQUN0RztBQUFBLElBQ0EsV0FBVyxhQUFhO0FBQ3BCLGFBQU8sWUFBWSxPQUFPLENBQUMsU0FBUyxlQUFlLENBQUMsR0FBRyxTQUFTLEdBQUcsS0FBSyxlQUFlLFVBQVUsQ0FBQyxHQUFHLENBQUMsQ0FBQztBQUFBLElBQzNHO0FBQUEsSUFDQSx5QkFBeUIsWUFBWTtBQUNqQyxZQUFNLGdCQUFnQixLQUFLLE9BQU8sd0JBQXdCLEtBQUssWUFBWSxVQUFVO0FBQ3JGLGFBQU8sS0FBSyxrQkFBa0IsYUFBYSxhQUFhO0FBQUEsSUFDNUQ7QUFBQSxJQUNBLFdBQVcsWUFBWTtBQUNuQixZQUFNLFdBQVcsS0FBSyx5QkFBeUIsVUFBVTtBQUN6RCxVQUFJO0FBQ0EsZUFBTyxLQUFLLFlBQVksVUFBVSxVQUFVO0FBQUEsSUFDcEQ7QUFBQSxJQUNBLGVBQWUsWUFBWTtBQUN2QixZQUFNLFdBQVcsS0FBSyx5QkFBeUIsVUFBVTtBQUN6RCxhQUFPLFdBQVcsS0FBSyxnQkFBZ0IsVUFBVSxVQUFVLElBQUksQ0FBQztBQUFBLElBQ3BFO0FBQUEsSUFDQSxZQUFZLFVBQVUsWUFBWTtBQUM5QixZQUFNLFdBQVcsS0FBSyxNQUFNLGNBQWMsUUFBUTtBQUNsRCxhQUFPLFNBQVMsT0FBTyxDQUFDLFlBQVksS0FBSyxlQUFlLFNBQVMsVUFBVSxVQUFVLENBQUMsRUFBRSxDQUFDO0FBQUEsSUFDN0Y7QUFBQSxJQUNBLGdCQUFnQixVQUFVLFlBQVk7QUFDbEMsWUFBTSxXQUFXLEtBQUssTUFBTSxjQUFjLFFBQVE7QUFDbEQsYUFBTyxTQUFTLE9BQU8sQ0FBQyxZQUFZLEtBQUssZUFBZSxTQUFTLFVBQVUsVUFBVSxDQUFDO0FBQUEsSUFDMUY7QUFBQSxJQUNBLGVBQWUsU0FBUyxVQUFVLFlBQVk7QUFDMUMsWUFBTSxzQkFBc0IsUUFBUSxhQUFhLEtBQUssTUFBTSxPQUFPLG1CQUFtQixLQUFLO0FBQzNGLGFBQU8sUUFBUSxRQUFRLFFBQVEsS0FBSyxvQkFBb0IsTUFBTSxHQUFHLEVBQUUsU0FBUyxVQUFVO0FBQUEsSUFDMUY7QUFBQSxFQUNKO0FBRUEsTUFBTSxRQUFOLE1BQU0sT0FBTTtBQUFBLElBQ1IsWUFBWSxRQUFRLFNBQVMsWUFBWSxRQUFRO0FBQzdDLFdBQUssVUFBVSxJQUFJLFVBQVUsSUFBSTtBQUNqQyxXQUFLLFVBQVUsSUFBSSxTQUFTLElBQUk7QUFDaEMsV0FBSyxPQUFPLElBQUksUUFBUSxJQUFJO0FBQzVCLFdBQUssa0JBQWtCLENBQUNFLGFBQVk7QUFDaEMsZUFBT0EsU0FBUSxRQUFRLEtBQUssa0JBQWtCLE1BQU0sS0FBSztBQUFBLE1BQzdEO0FBQ0EsV0FBSyxTQUFTO0FBQ2QsV0FBSyxVQUFVO0FBQ2YsV0FBSyxhQUFhO0FBQ2xCLFdBQUssUUFBUSxJQUFJLE1BQU0sTUFBTTtBQUM3QixXQUFLLFVBQVUsSUFBSSxVQUFVLEtBQUssZUFBZSxPQUFPO0FBQUEsSUFDNUQ7QUFBQSxJQUNBLFlBQVksVUFBVTtBQUNsQixhQUFPLEtBQUssUUFBUSxRQUFRLFFBQVEsSUFBSSxLQUFLLFVBQVUsS0FBSyxjQUFjLFFBQVEsRUFBRSxLQUFLLEtBQUssZUFBZTtBQUFBLElBQ2pIO0FBQUEsSUFDQSxnQkFBZ0IsVUFBVTtBQUN0QixhQUFPO0FBQUEsUUFDSCxHQUFJLEtBQUssUUFBUSxRQUFRLFFBQVEsSUFBSSxDQUFDLEtBQUssT0FBTyxJQUFJLENBQUM7QUFBQSxRQUN2RCxHQUFHLEtBQUssY0FBYyxRQUFRLEVBQUUsT0FBTyxLQUFLLGVBQWU7QUFBQSxNQUMvRDtBQUFBLElBQ0o7QUFBQSxJQUNBLGNBQWMsVUFBVTtBQUNwQixhQUFPLE1BQU0sS0FBSyxLQUFLLFFBQVEsaUJBQWlCLFFBQVEsQ0FBQztBQUFBLElBQzdEO0FBQUEsSUFDQSxJQUFJLHFCQUFxQjtBQUNyQixhQUFPLDRCQUE0QixLQUFLLE9BQU8scUJBQXFCLEtBQUssVUFBVTtBQUFBLElBQ3ZGO0FBQUEsSUFDQSxJQUFJLGtCQUFrQjtBQUNsQixhQUFPLEtBQUssWUFBWSxTQUFTO0FBQUEsSUFDckM7QUFBQSxJQUNBLElBQUksZ0JBQWdCO0FBQ2hCLGFBQU8sS0FBSyxrQkFDTixPQUNBLElBQUksT0FBTSxLQUFLLFFBQVEsU0FBUyxpQkFBaUIsS0FBSyxZQUFZLEtBQUssTUFBTSxNQUFNO0FBQUEsSUFDN0Y7QUFBQSxFQUNKO0FBRUEsTUFBTSxnQkFBTixNQUFvQjtBQUFBLElBQ2hCLFlBQVksU0FBUyxRQUFRLFVBQVU7QUFDbkMsV0FBSyxVQUFVO0FBQ2YsV0FBSyxTQUFTO0FBQ2QsV0FBSyxXQUFXO0FBQ2hCLFdBQUssb0JBQW9CLElBQUksa0JBQWtCLEtBQUssU0FBUyxLQUFLLHFCQUFxQixJQUFJO0FBQzNGLFdBQUssOEJBQThCLG9CQUFJLFFBQVE7QUFDL0MsV0FBSyx1QkFBdUIsb0JBQUksUUFBUTtBQUFBLElBQzVDO0FBQUEsSUFDQSxRQUFRO0FBQ0osV0FBSyxrQkFBa0IsTUFBTTtBQUFBLElBQ2pDO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxrQkFBa0IsS0FBSztBQUFBLElBQ2hDO0FBQUEsSUFDQSxJQUFJLHNCQUFzQjtBQUN0QixhQUFPLEtBQUssT0FBTztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxtQkFBbUIsT0FBTztBQUN0QixZQUFNLEVBQUUsU0FBUyxTQUFTLFdBQVcsSUFBSTtBQUN6QyxhQUFPLEtBQUssa0NBQWtDLFNBQVMsVUFBVTtBQUFBLElBQ3JFO0FBQUEsSUFDQSxrQ0FBa0MsU0FBUyxZQUFZO0FBQ25ELFlBQU0scUJBQXFCLEtBQUssa0NBQWtDLE9BQU87QUFDekUsVUFBSSxRQUFRLG1CQUFtQixJQUFJLFVBQVU7QUFDN0MsVUFBSSxDQUFDLE9BQU87QUFDUixnQkFBUSxLQUFLLFNBQVMsbUNBQW1DLFNBQVMsVUFBVTtBQUM1RSwyQkFBbUIsSUFBSSxZQUFZLEtBQUs7QUFBQSxNQUM1QztBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxvQkFBb0IsU0FBUyxPQUFPO0FBQ2hDLFlBQU0sa0JBQWtCLEtBQUsscUJBQXFCLElBQUksS0FBSyxLQUFLLEtBQUs7QUFDckUsV0FBSyxxQkFBcUIsSUFBSSxPQUFPLGNBQWM7QUFDbkQsVUFBSSxrQkFBa0IsR0FBRztBQUNyQixhQUFLLFNBQVMsZUFBZSxLQUFLO0FBQUEsTUFDdEM7QUFBQSxJQUNKO0FBQUEsSUFDQSxzQkFBc0IsU0FBUyxPQUFPO0FBQ2xDLFlBQU0saUJBQWlCLEtBQUsscUJBQXFCLElBQUksS0FBSztBQUMxRCxVQUFJLGdCQUFnQjtBQUNoQixhQUFLLHFCQUFxQixJQUFJLE9BQU8saUJBQWlCLENBQUM7QUFDdkQsWUFBSSxrQkFBa0IsR0FBRztBQUNyQixlQUFLLFNBQVMsa0JBQWtCLEtBQUs7QUFBQSxRQUN6QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxrQ0FBa0MsU0FBUztBQUN2QyxVQUFJLHFCQUFxQixLQUFLLDRCQUE0QixJQUFJLE9BQU87QUFDckUsVUFBSSxDQUFDLG9CQUFvQjtBQUNyQiw2QkFBcUIsb0JBQUksSUFBSTtBQUM3QixhQUFLLDRCQUE0QixJQUFJLFNBQVMsa0JBQWtCO0FBQUEsTUFDcEU7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLEVBQ0o7QUFFQSxNQUFNLFNBQU4sTUFBYTtBQUFBLElBQ1QsWUFBWSxhQUFhO0FBQ3JCLFdBQUssY0FBYztBQUNuQixXQUFLLGdCQUFnQixJQUFJLGNBQWMsS0FBSyxTQUFTLEtBQUssUUFBUSxJQUFJO0FBQ3RFLFdBQUsscUJBQXFCLElBQUksU0FBUztBQUN2QyxXQUFLLHNCQUFzQixvQkFBSSxJQUFJO0FBQUEsSUFDdkM7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxZQUFZO0FBQUEsSUFDNUI7QUFBQSxJQUNBLElBQUksU0FBUztBQUNULGFBQU8sS0FBSyxZQUFZO0FBQUEsSUFDNUI7QUFBQSxJQUNBLElBQUksU0FBUztBQUNULGFBQU8sS0FBSyxZQUFZO0FBQUEsSUFDNUI7QUFBQSxJQUNBLElBQUksc0JBQXNCO0FBQ3RCLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sTUFBTSxLQUFLLEtBQUssb0JBQW9CLE9BQU8sQ0FBQztBQUFBLElBQ3ZEO0FBQUEsSUFDQSxJQUFJLFdBQVc7QUFDWCxhQUFPLEtBQUssUUFBUSxPQUFPLENBQUMsVUFBVSxXQUFXLFNBQVMsT0FBTyxPQUFPLFFBQVEsR0FBRyxDQUFDLENBQUM7QUFBQSxJQUN6RjtBQUFBLElBQ0EsUUFBUTtBQUNKLFdBQUssY0FBYyxNQUFNO0FBQUEsSUFDN0I7QUFBQSxJQUNBLE9BQU87QUFDSCxXQUFLLGNBQWMsS0FBSztBQUFBLElBQzVCO0FBQUEsSUFDQSxlQUFlLFlBQVk7QUFDdkIsV0FBSyxpQkFBaUIsV0FBVyxVQUFVO0FBQzNDLFlBQU0sU0FBUyxJQUFJLE9BQU8sS0FBSyxhQUFhLFVBQVU7QUFDdEQsV0FBSyxjQUFjLE1BQU07QUFDekIsWUFBTSxZQUFZLFdBQVcsc0JBQXNCO0FBQ25ELFVBQUksV0FBVztBQUNYLGtCQUFVLEtBQUssV0FBVyx1QkFBdUIsV0FBVyxZQUFZLEtBQUssV0FBVztBQUFBLE1BQzVGO0FBQUEsSUFDSjtBQUFBLElBQ0EsaUJBQWlCLFlBQVk7QUFDekIsWUFBTSxTQUFTLEtBQUssb0JBQW9CLElBQUksVUFBVTtBQUN0RCxVQUFJLFFBQVE7QUFDUixhQUFLLGlCQUFpQixNQUFNO0FBQUEsTUFDaEM7QUFBQSxJQUNKO0FBQUEsSUFDQSxrQ0FBa0MsU0FBUyxZQUFZO0FBQ25ELFlBQU0sU0FBUyxLQUFLLG9CQUFvQixJQUFJLFVBQVU7QUFDdEQsVUFBSSxRQUFRO0FBQ1IsZUFBTyxPQUFPLFNBQVMsS0FBSyxDQUFDLFlBQVksUUFBUSxXQUFXLE9BQU87QUFBQSxNQUN2RTtBQUFBLElBQ0o7QUFBQSxJQUNBLDZDQUE2QyxTQUFTLFlBQVk7QUFDOUQsWUFBTSxRQUFRLEtBQUssY0FBYyxrQ0FBa0MsU0FBUyxVQUFVO0FBQ3RGLFVBQUksT0FBTztBQUNQLGFBQUssY0FBYyxvQkFBb0IsTUFBTSxTQUFTLEtBQUs7QUFBQSxNQUMvRCxPQUNLO0FBQ0QsZ0JBQVEsTUFBTSxrREFBa0QsVUFBVSxrQkFBa0IsT0FBTztBQUFBLE1BQ3ZHO0FBQUEsSUFDSjtBQUFBLElBQ0EsWUFBWUYsUUFBTyxTQUFTLFFBQVE7QUFDaEMsV0FBSyxZQUFZLFlBQVlBLFFBQU8sU0FBUyxNQUFNO0FBQUEsSUFDdkQ7QUFBQSxJQUNBLG1DQUFtQyxTQUFTLFlBQVk7QUFDcEQsYUFBTyxJQUFJLE1BQU0sS0FBSyxRQUFRLFNBQVMsWUFBWSxLQUFLLE1BQU07QUFBQSxJQUNsRTtBQUFBLElBQ0EsZUFBZSxPQUFPO0FBQ2xCLFdBQUssbUJBQW1CLElBQUksTUFBTSxZQUFZLEtBQUs7QUFDbkQsWUFBTSxTQUFTLEtBQUssb0JBQW9CLElBQUksTUFBTSxVQUFVO0FBQzVELFVBQUksUUFBUTtBQUNSLGVBQU8sdUJBQXVCLEtBQUs7QUFBQSxNQUN2QztBQUFBLElBQ0o7QUFBQSxJQUNBLGtCQUFrQixPQUFPO0FBQ3JCLFdBQUssbUJBQW1CLE9BQU8sTUFBTSxZQUFZLEtBQUs7QUFDdEQsWUFBTSxTQUFTLEtBQUssb0JBQW9CLElBQUksTUFBTSxVQUFVO0FBQzVELFVBQUksUUFBUTtBQUNSLGVBQU8sMEJBQTBCLEtBQUs7QUFBQSxNQUMxQztBQUFBLElBQ0o7QUFBQSxJQUNBLGNBQWMsUUFBUTtBQUNsQixXQUFLLG9CQUFvQixJQUFJLE9BQU8sWUFBWSxNQUFNO0FBQ3RELFlBQU0sU0FBUyxLQUFLLG1CQUFtQixnQkFBZ0IsT0FBTyxVQUFVO0FBQ3hFLGFBQU8sUUFBUSxDQUFDLFVBQVUsT0FBTyx1QkFBdUIsS0FBSyxDQUFDO0FBQUEsSUFDbEU7QUFBQSxJQUNBLGlCQUFpQixRQUFRO0FBQ3JCLFdBQUssb0JBQW9CLE9BQU8sT0FBTyxVQUFVO0FBQ2pELFlBQU0sU0FBUyxLQUFLLG1CQUFtQixnQkFBZ0IsT0FBTyxVQUFVO0FBQ3hFLGFBQU8sUUFBUSxDQUFDLFVBQVUsT0FBTywwQkFBMEIsS0FBSyxDQUFDO0FBQUEsSUFDckU7QUFBQSxFQUNKO0FBRUEsTUFBTSxnQkFBZ0I7QUFBQSxJQUNsQixxQkFBcUI7QUFBQSxJQUNyQixpQkFBaUI7QUFBQSxJQUNqQixpQkFBaUI7QUFBQSxJQUNqQix5QkFBeUIsQ0FBQyxlQUFlLFFBQVEsVUFBVTtBQUFBLElBQzNELHlCQUF5QixDQUFDLFlBQVksV0FBVyxRQUFRLFVBQVUsSUFBSSxNQUFNO0FBQUEsSUFDN0UsYUFBYSxPQUFPLE9BQU8sT0FBTyxPQUFPLEVBQUUsT0FBTyxTQUFTLEtBQUssT0FBTyxLQUFLLFVBQVUsT0FBTyxLQUFLLElBQUksV0FBVyxNQUFNLGFBQWEsTUFBTSxhQUFhLE9BQU8sY0FBYyxNQUFNLFFBQVEsS0FBSyxPQUFPLFNBQVMsVUFBVSxXQUFXLFdBQVcsR0FBRyxrQkFBa0IsNkJBQTZCLE1BQU0sRUFBRSxFQUFFLElBQUksQ0FBQyxNQUFNLENBQUMsR0FBRyxDQUFDLENBQUMsQ0FBQyxDQUFDLEdBQUcsa0JBQWtCLGFBQWEsTUFBTSxFQUFFLEVBQUUsSUFBSSxDQUFDLE1BQU0sQ0FBQyxHQUFHLENBQUMsQ0FBQyxDQUFDLENBQUM7QUFBQSxFQUNqWTtBQUNBLFdBQVMsa0JBQWtCLE9BQU87QUFDOUIsV0FBTyxNQUFNLE9BQU8sQ0FBQyxNQUFNLENBQUMsR0FBRyxDQUFDLE1BQU8sT0FBTyxPQUFPLE9BQU8sT0FBTyxDQUFDLEdBQUcsSUFBSSxHQUFHLEVBQUUsQ0FBQyxDQUFDLEdBQUcsRUFBRSxDQUFDLEdBQUksQ0FBQyxDQUFDO0FBQUEsRUFDbEc7QUFFQSxNQUFNLGNBQU4sTUFBa0I7QUFBQSxJQUNkLFlBQVksVUFBVSxTQUFTLGlCQUFpQixTQUFTLGVBQWU7QUFDcEUsV0FBSyxTQUFTO0FBQ2QsV0FBSyxRQUFRO0FBQ2IsV0FBSyxtQkFBbUIsQ0FBQyxZQUFZLGNBQWMsU0FBUyxDQUFDLE1BQU07QUFDL0QsWUFBSSxLQUFLLE9BQU87QUFDWixlQUFLLG9CQUFvQixZQUFZLGNBQWMsTUFBTTtBQUFBLFFBQzdEO0FBQUEsTUFDSjtBQUNBLFdBQUssVUFBVTtBQUNmLFdBQUssU0FBUztBQUNkLFdBQUssYUFBYSxJQUFJLFdBQVcsSUFBSTtBQUNyQyxXQUFLLFNBQVMsSUFBSSxPQUFPLElBQUk7QUFDN0IsV0FBSywwQkFBMEIsT0FBTyxPQUFPLENBQUMsR0FBRyw4QkFBOEI7QUFBQSxJQUNuRjtBQUFBLElBQ0EsT0FBTyxNQUFNLFNBQVMsUUFBUTtBQUMxQixZQUFNLGNBQWMsSUFBSSxLQUFLLFNBQVMsTUFBTTtBQUM1QyxrQkFBWSxNQUFNO0FBQ2xCLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxNQUFNLFFBQVE7QUFDVixZQUFNLFNBQVM7QUFDZixXQUFLLGlCQUFpQixlQUFlLFVBQVU7QUFDL0MsV0FBSyxXQUFXLE1BQU07QUFDdEIsV0FBSyxPQUFPLE1BQU07QUFDbEIsV0FBSyxpQkFBaUIsZUFBZSxPQUFPO0FBQUEsSUFDaEQ7QUFBQSxJQUNBLE9BQU87QUFDSCxXQUFLLGlCQUFpQixlQUFlLFVBQVU7QUFDL0MsV0FBSyxXQUFXLEtBQUs7QUFDckIsV0FBSyxPQUFPLEtBQUs7QUFDakIsV0FBSyxpQkFBaUIsZUFBZSxNQUFNO0FBQUEsSUFDL0M7QUFBQSxJQUNBLFNBQVMsWUFBWSx1QkFBdUI7QUFDeEMsV0FBSyxLQUFLLEVBQUUsWUFBWSxzQkFBc0IsQ0FBQztBQUFBLElBQ25EO0FBQUEsSUFDQSxxQkFBcUIsTUFBTSxRQUFRO0FBQy9CLFdBQUssd0JBQXdCLElBQUksSUFBSTtBQUFBLElBQ3pDO0FBQUEsSUFDQSxLQUFLLFNBQVMsTUFBTTtBQUNoQixZQUFNLGNBQWMsTUFBTSxRQUFRLElBQUksSUFBSSxPQUFPLENBQUMsTUFBTSxHQUFHLElBQUk7QUFDL0Qsa0JBQVksUUFBUSxDQUFDLGVBQWU7QUFDaEMsWUFBSSxXQUFXLHNCQUFzQixZQUFZO0FBQzdDLGVBQUssT0FBTyxlQUFlLFVBQVU7QUFBQSxRQUN6QztBQUFBLE1BQ0osQ0FBQztBQUFBLElBQ0w7QUFBQSxJQUNBLE9BQU8sU0FBUyxNQUFNO0FBQ2xCLFlBQU0sY0FBYyxNQUFNLFFBQVEsSUFBSSxJQUFJLE9BQU8sQ0FBQyxNQUFNLEdBQUcsSUFBSTtBQUMvRCxrQkFBWSxRQUFRLENBQUMsZUFBZSxLQUFLLE9BQU8saUJBQWlCLFVBQVUsQ0FBQztBQUFBLElBQ2hGO0FBQUEsSUFDQSxJQUFJLGNBQWM7QUFDZCxhQUFPLEtBQUssT0FBTyxTQUFTLElBQUksQ0FBQyxZQUFZLFFBQVEsVUFBVTtBQUFBLElBQ25FO0FBQUEsSUFDQSxxQ0FBcUMsU0FBUyxZQUFZO0FBQ3RELFlBQU0sVUFBVSxLQUFLLE9BQU8sa0NBQWtDLFNBQVMsVUFBVTtBQUNqRixhQUFPLFVBQVUsUUFBUSxhQUFhO0FBQUEsSUFDMUM7QUFBQSxJQUNBLFlBQVlBLFFBQU8sU0FBUyxRQUFRO0FBQ2hDLFVBQUk7QUFDSixXQUFLLE9BQU8sTUFBTTtBQUFBO0FBQUE7QUFBQTtBQUFBLEtBQWtCLFNBQVNBLFFBQU8sTUFBTTtBQUMxRCxPQUFDLEtBQUssT0FBTyxhQUFhLFFBQVEsT0FBTyxTQUFTLFNBQVMsR0FBRyxLQUFLLFFBQVEsU0FBUyxJQUFJLEdBQUcsR0FBR0EsTUFBSztBQUFBLElBQ3ZHO0FBQUEsSUFDQSxvQkFBb0IsWUFBWSxjQUFjLFNBQVMsQ0FBQyxHQUFHO0FBQ3ZELGVBQVMsT0FBTyxPQUFPLEVBQUUsYUFBYSxLQUFLLEdBQUcsTUFBTTtBQUNwRCxXQUFLLE9BQU8sZUFBZSxHQUFHLFVBQVUsS0FBSyxZQUFZLEVBQUU7QUFDM0QsV0FBSyxPQUFPLElBQUksWUFBWSxPQUFPLE9BQU8sQ0FBQyxHQUFHLE1BQU0sQ0FBQztBQUNyRCxXQUFLLE9BQU8sU0FBUztBQUFBLElBQ3pCO0FBQUEsRUFDSjtBQUNBLFdBQVMsV0FBVztBQUNoQixXQUFPLElBQUksUUFBUSxDQUFDLFlBQVk7QUFDNUIsVUFBSSxTQUFTLGNBQWMsV0FBVztBQUNsQyxpQkFBUyxpQkFBaUIsb0JBQW9CLE1BQU0sUUFBUSxDQUFDO0FBQUEsTUFDakUsT0FDSztBQUNELGdCQUFRO0FBQUEsTUFDWjtBQUFBLElBQ0osQ0FBQztBQUFBLEVBQ0w7QUFFQSxXQUFTLHdCQUF3QixhQUFhO0FBQzFDLFVBQU0sVUFBVSxpQ0FBaUMsYUFBYSxTQUFTO0FBQ3ZFLFdBQU8sUUFBUSxPQUFPLENBQUMsWUFBWSxvQkFBb0I7QUFDbkQsYUFBTyxPQUFPLE9BQU8sWUFBWSw2QkFBNkIsZUFBZSxDQUFDO0FBQUEsSUFDbEYsR0FBRyxDQUFDLENBQUM7QUFBQSxFQUNUO0FBQ0EsV0FBUyw2QkFBNkIsS0FBSztBQUN2QyxXQUFPO0FBQUEsTUFDSCxDQUFDLEdBQUcsR0FBRyxPQUFPLEdBQUc7QUFBQSxRQUNiLE1BQU07QUFDRixnQkFBTSxFQUFFLFFBQVEsSUFBSTtBQUNwQixjQUFJLFFBQVEsSUFBSSxHQUFHLEdBQUc7QUFDbEIsbUJBQU8sUUFBUSxJQUFJLEdBQUc7QUFBQSxVQUMxQixPQUNLO0FBQ0Qsa0JBQU0sWUFBWSxRQUFRLGlCQUFpQixHQUFHO0FBQzlDLGtCQUFNLElBQUksTUFBTSxzQkFBc0IsU0FBUyxHQUFHO0FBQUEsVUFDdEQ7QUFBQSxRQUNKO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxHQUFHLEdBQUcsU0FBUyxHQUFHO0FBQUEsUUFDZixNQUFNO0FBQ0YsaUJBQU8sS0FBSyxRQUFRLE9BQU8sR0FBRztBQUFBLFFBQ2xDO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxNQUFNLFdBQVcsR0FBRyxDQUFDLE9BQU8sR0FBRztBQUFBLFFBQzVCLE1BQU07QUFDRixpQkFBTyxLQUFLLFFBQVEsSUFBSSxHQUFHO0FBQUEsUUFDL0I7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLEVBQ0o7QUFFQSxXQUFTLHlCQUF5QixhQUFhO0FBQzNDLFVBQU0sVUFBVSxpQ0FBaUMsYUFBYSxTQUFTO0FBQ3ZFLFdBQU8sUUFBUSxPQUFPLENBQUMsWUFBWSxxQkFBcUI7QUFDcEQsYUFBTyxPQUFPLE9BQU8sWUFBWSw4QkFBOEIsZ0JBQWdCLENBQUM7QUFBQSxJQUNwRixHQUFHLENBQUMsQ0FBQztBQUFBLEVBQ1Q7QUFDQSxXQUFTLG9CQUFvQixZQUFZLFNBQVMsWUFBWTtBQUMxRCxXQUFPLFdBQVcsWUFBWSxxQ0FBcUMsU0FBUyxVQUFVO0FBQUEsRUFDMUY7QUFDQSxXQUFTLHFDQUFxQyxZQUFZLFNBQVMsWUFBWTtBQUMzRSxRQUFJLG1CQUFtQixvQkFBb0IsWUFBWSxTQUFTLFVBQVU7QUFDMUUsUUFBSTtBQUNBLGFBQU87QUFDWCxlQUFXLFlBQVksT0FBTyw2Q0FBNkMsU0FBUyxVQUFVO0FBQzlGLHVCQUFtQixvQkFBb0IsWUFBWSxTQUFTLFVBQVU7QUFDdEUsUUFBSTtBQUNBLGFBQU87QUFBQSxFQUNmO0FBQ0EsV0FBUyw4QkFBOEIsTUFBTTtBQUN6QyxVQUFNLGdCQUFnQixrQkFBa0IsSUFBSTtBQUM1QyxXQUFPO0FBQUEsTUFDSCxDQUFDLEdBQUcsYUFBYSxRQUFRLEdBQUc7QUFBQSxRQUN4QixNQUFNO0FBQ0YsZ0JBQU0sZ0JBQWdCLEtBQUssUUFBUSxLQUFLLElBQUk7QUFDNUMsZ0JBQU0sV0FBVyxLQUFLLFFBQVEseUJBQXlCLElBQUk7QUFDM0QsY0FBSSxlQUFlO0FBQ2Ysa0JBQU0sbUJBQW1CLHFDQUFxQyxNQUFNLGVBQWUsSUFBSTtBQUN2RixnQkFBSTtBQUNBLHFCQUFPO0FBQ1gsa0JBQU0sSUFBSSxNQUFNLGdFQUFnRSxJQUFJLG1DQUFtQyxLQUFLLFVBQVUsR0FBRztBQUFBLFVBQzdJO0FBQ0EsZ0JBQU0sSUFBSSxNQUFNLDJCQUEyQixJQUFJLDBCQUEwQixLQUFLLFVBQVUsdUVBQXVFLFFBQVEsSUFBSTtBQUFBLFFBQy9LO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxHQUFHLGFBQWEsU0FBUyxHQUFHO0FBQUEsUUFDekIsTUFBTTtBQUNGLGdCQUFNLFVBQVUsS0FBSyxRQUFRLFFBQVEsSUFBSTtBQUN6QyxjQUFJLFFBQVEsU0FBUyxHQUFHO0FBQ3BCLG1CQUFPLFFBQ0YsSUFBSSxDQUFDLGtCQUFrQjtBQUN4QixvQkFBTSxtQkFBbUIscUNBQXFDLE1BQU0sZUFBZSxJQUFJO0FBQ3ZGLGtCQUFJO0FBQ0EsdUJBQU87QUFDWCxzQkFBUSxLQUFLLGdFQUFnRSxJQUFJLG1DQUFtQyxLQUFLLFVBQVUsS0FBSyxhQUFhO0FBQUEsWUFDekosQ0FBQyxFQUNJLE9BQU8sQ0FBQyxlQUFlLFVBQVU7QUFBQSxVQUMxQztBQUNBLGlCQUFPLENBQUM7QUFBQSxRQUNaO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxHQUFHLGFBQWEsZUFBZSxHQUFHO0FBQUEsUUFDL0IsTUFBTTtBQUNGLGdCQUFNLGdCQUFnQixLQUFLLFFBQVEsS0FBSyxJQUFJO0FBQzVDLGdCQUFNLFdBQVcsS0FBSyxRQUFRLHlCQUF5QixJQUFJO0FBQzNELGNBQUksZUFBZTtBQUNmLG1CQUFPO0FBQUEsVUFDWCxPQUNLO0FBQ0Qsa0JBQU0sSUFBSSxNQUFNLDJCQUEyQixJQUFJLDBCQUEwQixLQUFLLFVBQVUsdUVBQXVFLFFBQVEsSUFBSTtBQUFBLFVBQy9LO0FBQUEsUUFDSjtBQUFBLE1BQ0o7QUFBQSxNQUNBLENBQUMsR0FBRyxhQUFhLGdCQUFnQixHQUFHO0FBQUEsUUFDaEMsTUFBTTtBQUNGLGlCQUFPLEtBQUssUUFBUSxRQUFRLElBQUk7QUFBQSxRQUNwQztBQUFBLE1BQ0o7QUFBQSxNQUNBLENBQUMsTUFBTSxXQUFXLGFBQWEsQ0FBQyxRQUFRLEdBQUc7QUFBQSxRQUN2QyxNQUFNO0FBQ0YsaUJBQU8sS0FBSyxRQUFRLElBQUksSUFBSTtBQUFBLFFBQ2hDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxFQUNKO0FBRUEsV0FBUyx5QkFBeUIsYUFBYTtBQUMzQyxVQUFNLFVBQVUsaUNBQWlDLGFBQWEsU0FBUztBQUN2RSxXQUFPLFFBQVEsT0FBTyxDQUFDLFlBQVkscUJBQXFCO0FBQ3BELGFBQU8sT0FBTyxPQUFPLFlBQVksOEJBQThCLGdCQUFnQixDQUFDO0FBQUEsSUFDcEYsR0FBRyxDQUFDLENBQUM7QUFBQSxFQUNUO0FBQ0EsV0FBUyw4QkFBOEIsTUFBTTtBQUN6QyxXQUFPO0FBQUEsTUFDSCxDQUFDLEdBQUcsSUFBSSxRQUFRLEdBQUc7QUFBQSxRQUNmLE1BQU07QUFDRixnQkFBTSxTQUFTLEtBQUssUUFBUSxLQUFLLElBQUk7QUFDckMsY0FBSSxRQUFRO0FBQ1IsbUJBQU87QUFBQSxVQUNYLE9BQ0s7QUFDRCxrQkFBTSxJQUFJLE1BQU0sMkJBQTJCLElBQUksVUFBVSxLQUFLLFVBQVUsY0FBYztBQUFBLFVBQzFGO0FBQUEsUUFDSjtBQUFBLE1BQ0o7QUFBQSxNQUNBLENBQUMsR0FBRyxJQUFJLFNBQVMsR0FBRztBQUFBLFFBQ2hCLE1BQU07QUFDRixpQkFBTyxLQUFLLFFBQVEsUUFBUSxJQUFJO0FBQUEsUUFDcEM7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLE1BQU0sV0FBVyxJQUFJLENBQUMsUUFBUSxHQUFHO0FBQUEsUUFDOUIsTUFBTTtBQUNGLGlCQUFPLEtBQUssUUFBUSxJQUFJLElBQUk7QUFBQSxRQUNoQztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLFdBQVMsd0JBQXdCLGFBQWE7QUFDMUMsVUFBTSx1QkFBdUIsaUNBQWlDLGFBQWEsUUFBUTtBQUNuRixVQUFNLHdCQUF3QjtBQUFBLE1BQzFCLG9CQUFvQjtBQUFBLFFBQ2hCLE1BQU07QUFDRixpQkFBTyxxQkFBcUIsT0FBTyxDQUFDLFFBQVEsd0JBQXdCO0FBQ2hFLGtCQUFNLGtCQUFrQix5QkFBeUIscUJBQXFCLEtBQUssVUFBVTtBQUNyRixrQkFBTSxnQkFBZ0IsS0FBSyxLQUFLLHVCQUF1QixnQkFBZ0IsR0FBRztBQUMxRSxtQkFBTyxPQUFPLE9BQU8sUUFBUSxFQUFFLENBQUMsYUFBYSxHQUFHLGdCQUFnQixDQUFDO0FBQUEsVUFDckUsR0FBRyxDQUFDLENBQUM7QUFBQSxRQUNUO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFDQSxXQUFPLHFCQUFxQixPQUFPLENBQUMsWUFBWSx3QkFBd0I7QUFDcEUsYUFBTyxPQUFPLE9BQU8sWUFBWSxpQ0FBaUMsbUJBQW1CLENBQUM7QUFBQSxJQUMxRixHQUFHLHFCQUFxQjtBQUFBLEVBQzVCO0FBQ0EsV0FBUyxpQ0FBaUMscUJBQXFCLFlBQVk7QUFDdkUsVUFBTSxhQUFhLHlCQUF5QixxQkFBcUIsVUFBVTtBQUMzRSxVQUFNLEVBQUUsS0FBSyxNQUFNLFFBQVEsTUFBTSxRQUFRLE1BQU0sSUFBSTtBQUNuRCxXQUFPO0FBQUEsTUFDSCxDQUFDLElBQUksR0FBRztBQUFBLFFBQ0osTUFBTTtBQUNGLGdCQUFNLFFBQVEsS0FBSyxLQUFLLElBQUksR0FBRztBQUMvQixjQUFJLFVBQVUsTUFBTTtBQUNoQixtQkFBTyxLQUFLLEtBQUs7QUFBQSxVQUNyQixPQUNLO0FBQ0QsbUJBQU8sV0FBVztBQUFBLFVBQ3RCO0FBQUEsUUFDSjtBQUFBLFFBQ0EsSUFBSSxPQUFPO0FBQ1AsY0FBSSxVQUFVLFFBQVc7QUFDckIsaUJBQUssS0FBSyxPQUFPLEdBQUc7QUFBQSxVQUN4QixPQUNLO0FBQ0QsaUJBQUssS0FBSyxJQUFJLEtBQUssTUFBTSxLQUFLLENBQUM7QUFBQSxVQUNuQztBQUFBLFFBQ0o7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLE1BQU0sV0FBVyxJQUFJLENBQUMsRUFBRSxHQUFHO0FBQUEsUUFDeEIsTUFBTTtBQUNGLGlCQUFPLEtBQUssS0FBSyxJQUFJLEdBQUcsS0FBSyxXQUFXO0FBQUEsUUFDNUM7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLEVBQ0o7QUFDQSxXQUFTLHlCQUF5QixDQUFDLE9BQU8sY0FBYyxHQUFHLFlBQVk7QUFDbkUsV0FBTyx5Q0FBeUM7QUFBQSxNQUM1QztBQUFBLE1BQ0E7QUFBQSxNQUNBO0FBQUEsSUFDSixDQUFDO0FBQUEsRUFDTDtBQUNBLFdBQVMsdUJBQXVCLFVBQVU7QUFDdEMsWUFBUSxVQUFVO0FBQUEsTUFDZCxLQUFLO0FBQ0QsZUFBTztBQUFBLE1BQ1gsS0FBSztBQUNELGVBQU87QUFBQSxNQUNYLEtBQUs7QUFDRCxlQUFPO0FBQUEsTUFDWCxLQUFLO0FBQ0QsZUFBTztBQUFBLE1BQ1gsS0FBSztBQUNELGVBQU87QUFBQSxJQUNmO0FBQUEsRUFDSjtBQUNBLFdBQVMsc0JBQXNCLGNBQWM7QUFDekMsWUFBUSxPQUFPLGNBQWM7QUFBQSxNQUN6QixLQUFLO0FBQ0QsZUFBTztBQUFBLE1BQ1gsS0FBSztBQUNELGVBQU87QUFBQSxNQUNYLEtBQUs7QUFDRCxlQUFPO0FBQUEsSUFDZjtBQUNBLFFBQUksTUFBTSxRQUFRLFlBQVk7QUFDMUIsYUFBTztBQUNYLFFBQUksT0FBTyxVQUFVLFNBQVMsS0FBSyxZQUFZLE1BQU07QUFDakQsYUFBTztBQUFBLEVBQ2Y7QUFDQSxXQUFTLHFCQUFxQixTQUFTO0FBQ25DLFVBQU0sRUFBRSxZQUFZLE9BQU8sV0FBVyxJQUFJO0FBQzFDLFVBQU0sVUFBVSxZQUFZLFdBQVcsSUFBSTtBQUMzQyxVQUFNLGFBQWEsWUFBWSxXQUFXLE9BQU87QUFDakQsVUFBTSxhQUFhLFdBQVc7QUFDOUIsVUFBTSxXQUFXLFdBQVcsQ0FBQztBQUM3QixVQUFNLGNBQWMsQ0FBQyxXQUFXO0FBQ2hDLFVBQU0saUJBQWlCLHVCQUF1QixXQUFXLElBQUk7QUFDN0QsVUFBTSx1QkFBdUIsc0JBQXNCLFFBQVEsV0FBVyxPQUFPO0FBQzdFLFFBQUk7QUFDQSxhQUFPO0FBQ1gsUUFBSTtBQUNBLGFBQU87QUFDWCxRQUFJLG1CQUFtQixzQkFBc0I7QUFDekMsWUFBTSxlQUFlLGFBQWEsR0FBRyxVQUFVLElBQUksS0FBSyxLQUFLO0FBQzdELFlBQU0sSUFBSSxNQUFNLHVEQUF1RCxZQUFZLGtDQUFrQyxjQUFjLHFDQUFxQyxXQUFXLE9BQU8saUJBQWlCLG9CQUFvQixJQUFJO0FBQUEsSUFDdk87QUFDQSxRQUFJO0FBQ0EsYUFBTztBQUFBLEVBQ2Y7QUFDQSxXQUFTLHlCQUF5QixTQUFTO0FBQ3ZDLFVBQU0sRUFBRSxZQUFZLE9BQU8sZUFBZSxJQUFJO0FBQzlDLFVBQU0sYUFBYSxFQUFFLFlBQVksT0FBTyxZQUFZLGVBQWU7QUFDbkUsVUFBTSxpQkFBaUIscUJBQXFCLFVBQVU7QUFDdEQsVUFBTSx1QkFBdUIsc0JBQXNCLGNBQWM7QUFDakUsVUFBTSxtQkFBbUIsdUJBQXVCLGNBQWM7QUFDOUQsVUFBTSxPQUFPLGtCQUFrQix3QkFBd0I7QUFDdkQsUUFBSTtBQUNBLGFBQU87QUFDWCxVQUFNLGVBQWUsYUFBYSxHQUFHLFVBQVUsSUFBSSxjQUFjLEtBQUs7QUFDdEUsVUFBTSxJQUFJLE1BQU0sdUJBQXVCLFlBQVksVUFBVSxLQUFLLFNBQVM7QUFBQSxFQUMvRTtBQUNBLFdBQVMsMEJBQTBCLGdCQUFnQjtBQUMvQyxVQUFNLFdBQVcsdUJBQXVCLGNBQWM7QUFDdEQsUUFBSTtBQUNBLGFBQU8sb0JBQW9CLFFBQVE7QUFDdkMsVUFBTSxhQUFhLFlBQVksZ0JBQWdCLFNBQVM7QUFDeEQsVUFBTSxVQUFVLFlBQVksZ0JBQWdCLE1BQU07QUFDbEQsVUFBTSxhQUFhO0FBQ25CLFFBQUk7QUFDQSxhQUFPLFdBQVc7QUFDdEIsUUFBSSxTQUFTO0FBQ1QsWUFBTSxFQUFFLEtBQUssSUFBSTtBQUNqQixZQUFNLG1CQUFtQix1QkFBdUIsSUFBSTtBQUNwRCxVQUFJO0FBQ0EsZUFBTyxvQkFBb0IsZ0JBQWdCO0FBQUEsSUFDbkQ7QUFDQSxXQUFPO0FBQUEsRUFDWDtBQUNBLFdBQVMseUNBQXlDLFNBQVM7QUFDdkQsVUFBTSxFQUFFLE9BQU8sZUFBZSxJQUFJO0FBQ2xDLFVBQU0sTUFBTSxHQUFHLFVBQVUsS0FBSyxDQUFDO0FBQy9CLFVBQU0sT0FBTyx5QkFBeUIsT0FBTztBQUM3QyxXQUFPO0FBQUEsTUFDSDtBQUFBLE1BQ0E7QUFBQSxNQUNBLE1BQU0sU0FBUyxHQUFHO0FBQUEsTUFDbEIsSUFBSSxlQUFlO0FBQ2YsZUFBTywwQkFBMEIsY0FBYztBQUFBLE1BQ25EO0FBQUEsTUFDQSxJQUFJLHdCQUF3QjtBQUN4QixlQUFPLHNCQUFzQixjQUFjLE1BQU07QUFBQSxNQUNyRDtBQUFBLE1BQ0EsUUFBUSxRQUFRLElBQUk7QUFBQSxNQUNwQixRQUFRLFFBQVEsSUFBSSxLQUFLLFFBQVE7QUFBQSxJQUNyQztBQUFBLEVBQ0o7QUFDQSxNQUFNLHNCQUFzQjtBQUFBLElBQ3hCLElBQUksUUFBUTtBQUNSLGFBQU8sQ0FBQztBQUFBLElBQ1o7QUFBQSxJQUNBLFNBQVM7QUFBQSxJQUNULFFBQVE7QUFBQSxJQUNSLElBQUksU0FBUztBQUNULGFBQU8sQ0FBQztBQUFBLElBQ1o7QUFBQSxJQUNBLFFBQVE7QUFBQSxFQUNaO0FBQ0EsTUFBTSxVQUFVO0FBQUEsSUFDWixNQUFNLE9BQU87QUFDVCxZQUFNLFFBQVEsS0FBSyxNQUFNLEtBQUs7QUFDOUIsVUFBSSxDQUFDLE1BQU0sUUFBUSxLQUFLLEdBQUc7QUFDdkIsY0FBTSxJQUFJLFVBQVUseURBQXlELEtBQUssY0FBYyxzQkFBc0IsS0FBSyxDQUFDLEdBQUc7QUFBQSxNQUNuSTtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxRQUFRLE9BQU87QUFDWCxhQUFPLEVBQUUsU0FBUyxPQUFPLE9BQU8sS0FBSyxFQUFFLFlBQVksS0FBSztBQUFBLElBQzVEO0FBQUEsSUFDQSxPQUFPLE9BQU87QUFDVixhQUFPLE9BQU8sTUFBTSxRQUFRLE1BQU0sRUFBRSxDQUFDO0FBQUEsSUFDekM7QUFBQSxJQUNBLE9BQU8sT0FBTztBQUNWLFlBQU0sU0FBUyxLQUFLLE1BQU0sS0FBSztBQUMvQixVQUFJLFdBQVcsUUFBUSxPQUFPLFVBQVUsWUFBWSxNQUFNLFFBQVEsTUFBTSxHQUFHO0FBQ3ZFLGNBQU0sSUFBSSxVQUFVLDBEQUEwRCxLQUFLLGNBQWMsc0JBQXNCLE1BQU0sQ0FBQyxHQUFHO0FBQUEsTUFDckk7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsT0FBTyxPQUFPO0FBQ1YsYUFBTztBQUFBLElBQ1g7QUFBQSxFQUNKO0FBQ0EsTUFBTSxVQUFVO0FBQUEsSUFDWixTQUFTO0FBQUEsSUFDVCxPQUFPO0FBQUEsSUFDUCxRQUFRO0FBQUEsRUFDWjtBQUNBLFdBQVMsVUFBVSxPQUFPO0FBQ3RCLFdBQU8sS0FBSyxVQUFVLEtBQUs7QUFBQSxFQUMvQjtBQUNBLFdBQVMsWUFBWSxPQUFPO0FBQ3hCLFdBQU8sR0FBRyxLQUFLO0FBQUEsRUFDbkI7QUFFQSxNQUFNLGFBQU4sTUFBaUI7QUFBQSxJQUNiLFlBQVksU0FBUztBQUNqQixXQUFLLFVBQVU7QUFBQSxJQUNuQjtBQUFBLElBQ0EsV0FBVyxhQUFhO0FBQ3BCLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxPQUFPLFVBQVUsYUFBYSxjQUFjO0FBQ3hDO0FBQUEsSUFDSjtBQUFBLElBQ0EsSUFBSSxjQUFjO0FBQ2QsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxRQUFRO0FBQ1IsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxPQUFPO0FBQ1AsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsYUFBYTtBQUFBLElBQ2I7QUFBQSxJQUNBLFVBQVU7QUFBQSxJQUNWO0FBQUEsSUFDQSxhQUFhO0FBQUEsSUFDYjtBQUFBLElBQ0EsU0FBUyxXQUFXLEVBQUUsU0FBUyxLQUFLLFNBQVMsU0FBUyxDQUFDLEdBQUcsU0FBUyxLQUFLLFlBQVksVUFBVSxNQUFNLGFBQWEsS0FBTSxJQUFJLENBQUMsR0FBRztBQUMzSCxZQUFNLE9BQU8sU0FBUyxHQUFHLE1BQU0sSUFBSSxTQUFTLEtBQUs7QUFDakQsWUFBTSxRQUFRLElBQUksWUFBWSxNQUFNLEVBQUUsUUFBUSxTQUFTLFdBQVcsQ0FBQztBQUNuRSxhQUFPLGNBQWMsS0FBSztBQUMxQixhQUFPO0FBQUEsSUFDWDtBQUFBLEVBQ0o7QUFDQSxhQUFXLFlBQVk7QUFBQSxJQUNuQjtBQUFBLElBQ0E7QUFBQSxJQUNBO0FBQUEsSUFDQTtBQUFBLEVBQ0o7QUFDQSxhQUFXLFVBQVUsQ0FBQztBQUN0QixhQUFXLFVBQVUsQ0FBQztBQUN0QixhQUFXLFNBQVMsQ0FBQzs7O0FDLytFckIsTUFBTyw2QkFBUCxjQUE2QixXQUFXO0FBQUEsSUFXdEMsVUFBVTtBQUNSLGNBQVEsSUFBSSx1Q0FBdUM7QUFBQSxRQUNqRCxXQUFXLEtBQUs7QUFBQSxRQUNoQixZQUFZLEtBQUs7QUFBQSxNQUNuQixDQUFDO0FBQUEsSUFDSDtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxPQUFPLE9BQU87QUFDWixZQUFNLGVBQWU7QUFFckIsY0FBUSxJQUFJLGlCQUFpQjtBQUU3QixZQUFNLFNBQVMsTUFBTTtBQUdyQixXQUFLLGdCQUFnQixRQUFRLElBQUk7QUFHakMsWUFBTSxjQUFjLFNBQVMsZUFBZSxnQkFBZ0I7QUFDNUQsWUFBTSxTQUFTLGNBQWMsWUFBWSxRQUFRO0FBR2pELFlBQU0sY0FBYyxTQUFTLGNBQWMsbUJBQW1CO0FBQzlELFlBQU0sV0FBVyxjQUFjLElBQUksU0FBUyxXQUFXLElBQUksSUFBSSxTQUFTO0FBR3hFLFlBQU0sU0FBUztBQUFBLFFBQ2IsTUFBTTtBQUFBLFFBQ04sT0FBTztBQUFBLFFBQ1AsT0FBTyxLQUFLO0FBQUEsUUFDWixRQUFRLEtBQUs7QUFBQSxRQUNiLFlBQVksS0FBSztBQUFBLFFBQ2pCLE1BQU07QUFBQSxRQUNOLFVBQVUsS0FBSztBQUFBLE1BQ2pCO0FBR0EsZUFBUyxDQUFDLEtBQUssS0FBSyxLQUFLLFNBQVMsUUFBUSxHQUFHO0FBQzNDLFlBQUksQ0FBQyxPQUFPLEdBQUcsS0FBSyxRQUFRLFNBQVMsUUFBUSxNQUFNO0FBQ2pELGlCQUFPLEdBQUcsSUFBSTtBQUFBLFFBQ2hCO0FBQUEsTUFDRjtBQUVBLGNBQVEsSUFBSSw0QkFBNEIsTUFBTTtBQUc5QyxXQUFLLFdBQVcsTUFBTTtBQUFBLElBQ3hCO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU1BLFdBQVcsUUFBUTtBQUNqQixZQUFNLE9BQU8sU0FBUyxjQUFjLE1BQU07QUFDMUMsV0FBSyxTQUFTO0FBQ2QsV0FBSyxTQUFTLEtBQUs7QUFDbkIsV0FBSyxNQUFNLFVBQVU7QUFHckIsYUFBTyxRQUFRLE1BQU0sRUFBRSxRQUFRLENBQUMsQ0FBQyxNQUFNLEtBQUssTUFBTTtBQUNoRCxjQUFNLFFBQVEsU0FBUyxjQUFjLE9BQU87QUFDNUMsY0FBTSxPQUFPO0FBQ2IsY0FBTSxPQUFPO0FBQ2IsY0FBTSxRQUFRO0FBQ2QsYUFBSyxZQUFZLEtBQUs7QUFBQSxNQUN4QixDQUFDO0FBR0QsZUFBUyxLQUFLLFlBQVksSUFBSTtBQUc5QixpQkFBVyxNQUFNO0FBQ2YsYUFBSyxPQUFPO0FBQUEsTUFDZCxHQUFHLEdBQUc7QUFBQSxJQUNSO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBT0EsZ0JBQWdCLFFBQVEsV0FBVztBQUNqQyxVQUFJLFdBQVc7QUFFYixlQUFPLFFBQVEsZUFBZSxPQUFPO0FBR3JDLGVBQU8sV0FBVztBQUNsQixlQUFPLFlBQVk7QUFBQTtBQUFBO0FBQUE7QUFBQSxNQUlyQixPQUFPO0FBRUwsZUFBTyxXQUFXO0FBQ2xCLFlBQUksT0FBTyxRQUFRLGNBQWM7QUFDL0IsaUJBQU8sWUFBWSxPQUFPLFFBQVE7QUFBQSxRQUNwQztBQUFBLE1BQ0Y7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU1BLFlBQVlHLFFBQU87QUFDakIsY0FBUSxNQUFNLGtCQUFrQkEsTUFBSztBQUdyQyxZQUFNLHNFQUFzRTtBQUc1RSxVQUFJLEtBQUssaUJBQWlCO0FBQ3hCLGFBQUssZ0JBQWdCLEtBQUssY0FBYyxLQUFLO0FBQUEsTUFDL0M7QUFBQSxJQUNGO0FBQUEsRUFDRjtBQW5JRSxnQkFESyw0QkFDRSxVQUFTO0FBQUEsSUFDZCxXQUFXO0FBQUEsSUFDWCxZQUFZO0FBQUEsSUFDWixVQUFVO0FBQUEsSUFDVixXQUFXO0FBQUEsSUFDWCxXQUFXO0FBQUEsRUFDYjtBQUVBLGdCQVRLLDRCQVNFLFdBQVUsQ0FBQyxRQUFROzs7QUNUNUIsTUFBTyxrQ0FBUCxjQUE2QixXQUFXO0FBQUEsSUFRdEMsVUFBVTtBQUNSLGNBQVEsSUFBSSxxQ0FBcUM7QUFBQSxRQUMvQyxtQkFBbUIsQ0FBQyxDQUFDLEtBQUs7QUFBQSxRQUMxQixpQkFBaUIsQ0FBQyxDQUFDLEtBQUs7QUFBQSxRQUN4QixnQkFBZ0IsS0FBSyxzQkFBc0IsS0FBSyxvQkFBb0IsVUFBVSxHQUFHLEVBQUUsSUFBSSxRQUFRO0FBQUEsUUFDL0Ysb0JBQW9CLEtBQUssb0JBQW9CLEtBQUssa0JBQWtCLFNBQVM7QUFBQSxNQUMvRSxDQUFDO0FBR0QsWUFBTSxZQUFZLEtBQUssUUFBUSxhQUFhLGlCQUFpQjtBQUM3RCxVQUFJLFdBQVc7QUFDYixnQkFBUSxJQUFJLGVBQWUsU0FBUztBQUFBLE1BQ3RDO0FBR0EsVUFBSSxDQUFDLEtBQUsscUJBQXFCO0FBQzdCLGdCQUFRLE1BQU0sdUNBQXVDO0FBQ3JELGFBQUssVUFBVSxxREFBcUQ7QUFDcEU7QUFBQSxNQUNGO0FBb0JBLFdBQUssaUJBQWlCO0FBQUEsSUFDeEI7QUFBQSxJQUVBLGFBQWE7QUFFWCxVQUFJLEtBQUssZ0JBQWdCO0FBQ3ZCLGFBQUssZUFBZSxRQUFRO0FBQUEsTUFDOUI7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxNQUFNLG1CQUFtQjtBQUV2QixVQUFJLE9BQU8sV0FBVyxhQUFhO0FBQ2pDLGdCQUFRLElBQUksa0NBQWtDO0FBQzlDLGNBQU0sS0FBSyxjQUFjO0FBQUEsTUFDM0I7QUFFQSxVQUFJO0FBRUYsYUFBSyxTQUFTLE9BQU8sS0FBSyxtQkFBbUI7QUFHN0MsY0FBTSxhQUFhO0FBQUEsVUFDakIsT0FBTztBQUFBLFVBQ1AsV0FBVztBQUFBLFlBQ1QsY0FBYztBQUFBLFlBQ2QsaUJBQWlCO0FBQUEsWUFDakIsV0FBVztBQUFBLFlBQ1gsWUFBWTtBQUFBLFlBQ1osY0FBYztBQUFBLFVBQ2hCO0FBQUEsUUFDRjtBQUVBLGFBQUssV0FBVyxLQUFLLE9BQU8sU0FBUztBQUFBLFVBQ25DO0FBQUEsUUFDRixDQUFDO0FBRUQsY0FBTSxPQUFPLEtBQUssU0FBUyxPQUFPLE1BQU07QUFDeEMsYUFBSyxNQUFNLGVBQWU7QUFFMUIsZ0JBQVEsSUFBSSxpREFBaUQ7QUFBQSxNQUUvRCxTQUFTQyxRQUFPO0FBQ2QsZ0JBQVEsTUFBTSxnQ0FBZ0NBLE1BQUs7QUFDbkQsYUFBSyxVQUFVLDZEQUE2RDtBQUFBLE1BQzlFO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxnQkFBZ0I7QUFDZCxhQUFPLElBQUksUUFBUSxDQUFDLFlBQVk7QUFDOUIsY0FBTSxjQUFjLE1BQU07QUFDeEIsY0FBSSxPQUFPLFdBQVcsYUFBYTtBQUNqQyxvQkFBUTtBQUFBLFVBQ1YsT0FBTztBQUNMLHVCQUFXLGFBQWEsR0FBRztBQUFBLFVBQzdCO0FBQUEsUUFDRjtBQUNBLG9CQUFZO0FBQUEsTUFDZCxDQUFDO0FBQUEsSUFDSDtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsY0FBYztBQUNaLFVBQUksS0FBSyxrQkFBa0I7QUFDekIsYUFBSyxjQUFjLE1BQU0sVUFBVTtBQUFBLE1BQ3JDO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxVQUFVLFNBQVM7QUFDakIsWUFBTSxXQUFXLFNBQVMsZUFBZSxnQkFBZ0I7QUFDekQsVUFBSSxZQUFZLEtBQUssdUJBQXVCO0FBQzFDLGlCQUFTLE1BQU0sVUFBVTtBQUN6QixhQUFLLG1CQUFtQixjQUFjO0FBQUEsTUFDeEM7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxZQUFZO0FBQ1YsWUFBTSxXQUFXLFNBQVMsZUFBZSxnQkFBZ0I7QUFDekQsVUFBSSxVQUFVO0FBQ1osaUJBQVMsTUFBTSxVQUFVO0FBQ3pCLFlBQUksS0FBSyx1QkFBdUI7QUFDOUIsZUFBSyxtQkFBbUIsY0FBYztBQUFBLFFBQ3hDO0FBQUEsTUFDRjtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLGNBQWM7QUFDWixVQUFJLEtBQUssa0JBQWtCO0FBQ3pCLGFBQUssY0FBYyxNQUFNLFVBQVU7QUFBQSxNQUNyQztBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsWUFBWTtBQUNWLGFBQU8sS0FBSztBQUFBLElBQ2Q7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsY0FBYztBQUNaLGFBQU8sS0FBSztBQUFBLElBQ2Q7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFPQSxNQUFNLGNBQWMsT0FBTztBQUN6QixZQUFNLGVBQWU7QUFFckIsVUFBSSxDQUFDLEtBQUssVUFBVSxDQUFDLEtBQUssVUFBVTtBQUNsQyxhQUFLLFVBQVUsd0RBQXdEO0FBQ3ZFO0FBQUEsTUFDRjtBQUVBLFdBQUssWUFBWTtBQUNqQixXQUFLLFVBQVU7QUFFZixVQUFJO0FBRUYsY0FBTSxVQUFVLE9BQU8sU0FBUyxTQUFTLE9BQU8sU0FBUyxTQUFTLE1BQU0sWUFBWSxFQUFFLENBQUM7QUFDdkYsY0FBTSxZQUFZLFVBQVU7QUFFNUIsZ0JBQVEsSUFBSSx1Q0FBdUMsU0FBUztBQUc1RCxjQUFNLEVBQUUsT0FBQUEsT0FBTSxJQUFJLE1BQU0sS0FBSyxPQUFPLGVBQWU7QUFBQSxVQUNqRCxVQUFVLEtBQUs7QUFBQSxVQUNmLGVBQWU7QUFBQSxZQUNiLFlBQVk7QUFBQSxVQUNkO0FBQUEsUUFDRixDQUFDO0FBSUQsWUFBSUEsUUFBTztBQUNULGtCQUFRLE1BQU0sK0JBQStCQSxNQUFLO0FBR2xELGNBQUlBLE9BQU0sU0FBUyxnQkFBZ0JBLE9BQU0sU0FBUyxvQkFBb0I7QUFDcEUsaUJBQUssVUFBVUEsT0FBTSxPQUFPO0FBQUEsVUFDOUIsT0FBTztBQUNMLGlCQUFLLFVBQVUsaURBQWlEO0FBQUEsVUFDbEU7QUFBQSxRQUNGO0FBQUEsTUFFRixTQUFTQSxRQUFPO0FBQ2QsZ0JBQVEsTUFBTSw2QkFBNkJBLE1BQUs7QUFDaEQsYUFBSyxVQUFVLDhDQUE4QztBQUFBLE1BQy9ELFVBQUU7QUFDQSxhQUFLLFlBQVk7QUFBQSxNQUNuQjtBQUFBLElBQ0Y7QUFBQSxFQUNGO0FBaE9FLGdCQURLLGlDQUNFLFVBQVM7QUFBQSxJQUNkLGdCQUFnQjtBQUFBLElBQ2hCLGNBQWM7QUFBQSxFQUNoQjtBQUVBLGdCQU5LLGlDQU1FLFdBQVUsQ0FBQyxnQkFBZ0IsU0FBUzs7O0FDVjdDLFNBQU8sV0FBVyxZQUFZLE1BQU07QUFHcEMsV0FBUyxTQUFTLFdBQVcsMEJBQWdCO0FBQzdDLFdBQVMsU0FBUyxnQkFBZ0IsK0JBQXFCO0FBR3ZELE1BQUksTUFBd0M7QUFDMUMsYUFBUyxRQUFRO0FBQ2pCLFlBQVEsSUFBSSx5REFBeUQsU0FBUyxPQUFPLG1CQUFtQjtBQUFBLEVBQzFHO0FBRUEsVUFBUSxJQUFJLDRDQUE0QzsiLAogICJuYW1lcyI6IFsiZXJyb3IiLCAibWF0Y2giLCAib2xkVmFsdWUiLCAiZXJyb3IiLCAiY29uc3RydWN0b3IiLCAiZWxlbWVudCIsICJlcnJvciIsICJlcnJvciJdCn0K
