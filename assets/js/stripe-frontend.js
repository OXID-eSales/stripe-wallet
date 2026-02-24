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
    fetch2(map, key).add(value);
  }
  function del(map, key, value) {
    fetch2(map, key).delete(value);
    prune(map, key);
  }
  function fetch2(map, key) {
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
      var _a2;
      if (!this.targetsByName.has(name, element)) {
        this.targetsByName.add(name, element);
        (_a2 = this.tokenListObserver) === null || _a2 === void 0 ? void 0 : _a2.pause(() => this.delegate.targetConnected(element, name));
      }
    }
    disconnectTarget(element, name) {
      var _a2;
      if (this.targetsByName.has(name, element)) {
        this.targetsByName.delete(name, element);
        (_a2 = this.tokenListObserver) === null || _a2 === void 0 ? void 0 : _a2.pause(() => this.delegate.targetDisconnected(element, name));
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
      var _a2;
      if (!this.outletElementsByName.has(outletName, element)) {
        this.outletsByName.add(outletName, outlet);
        this.outletElementsByName.add(outletName, element);
        (_a2 = this.selectorObserverMap.get(outletName)) === null || _a2 === void 0 ? void 0 : _a2.pause(() => this.delegate.outletConnected(outlet, element, outletName));
      }
    }
    disconnectOutlet(outlet, element, outletName) {
      var _a2;
      if (this.outletElementsByName.has(outletName, element)) {
        this.outletsByName.delete(outletName, outlet);
        this.outletElementsByName.delete(outletName, element);
        (_a2 = this.selectorObserverMap.get(outletName)) === null || _a2 === void 0 ? void 0 : _a2.pause(() => this.delegate.outletDisconnected(outlet, element, outletName));
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
      var _a2;
      this.logger.error(`%s

%o

%o`, message, error2, detail);
      (_a2 = window.onerror) === null || _a2 === void 0 ? void 0 : _a2.call(window, message, "", 0, 0, error2);
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

  // resources/build/js/controllers/stripe_order_controller.js
  var stripe_order_controller_default = class extends Controller {
    connect() {
      var _a2, _b;
      console.log("Stripe Order controller connected", {
        hasPublishableKey: !!this.publishableKeyValue,
        publishableKey: this.publishableKeyValue ? this.publishableKeyValue.substring(0, 10) + "..." : "missing"
      });
      const debugInfo = this.element.getAttribute("data-debug-info");
      if (debugInfo) {
        console.log("Debug info:", debugInfo);
      }
      if (!this.publishableKeyValue) {
        console.error("Stripe publishable key not configured");
        this.showError(((_b = (_a2 = window.oStripe) == null ? void 0 : _a2.i18n) == null ? void 0 : _b.CONFIG_ERROR) || "Stripe configuration error. Please contact support.");
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
      var _a2, _b;
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
        this.card = this.elements.create("card");
        this.card.mount("#card-element");
        console.log("Stripe Payment Element initialized successfully");
      } catch (error2) {
        console.error("Failed to initialize Stripe:", error2);
        this.showError(((_b = (_a2 = window.oStripe) == null ? void 0 : _a2.i18n) == null ? void 0 : _b.INIT_FAILED) || "Failed to initialize payment form. Please refresh the page.");
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
  };
  __publicField(stripe_order_controller_default, "values", {
    publishableKey: String,
    clientSecret: String
  });
  __publicField(stripe_order_controller_default, "targets", ["errorMessage", "loading"]);

  // resources/build/js/controllers/order_submit_controller.js
  var order_submit_controller_default = class extends Controller {
    /**
     * Called when controller is connected to DOM
     */
    connect() {
      console.log("Order Submit controller connected");
      console.log("Button element:", this.element);
    }
    /**
     * Called when controller is disconnected from DOM
     */
    disconnect() {
      console.log("Order Submit controller disconnected");
    }
    /**
     * Get the stripe-order controller instance
     * @returns {Controller|null}
     */
    getStripeOrderController() {
      const cardElement = document.getElementById("card-element");
      if (!cardElement) {
        console.error("Card element not found");
        return null;
      }
      const controller = this.application.getControllerForElementAndIdentifier(
        cardElement,
        "stripe-order"
      );
      if (!controller) {
        console.error("Stripe order controller not found on card element");
        return null;
      }
      console.log("Found stripe-order controller:", controller);
      return controller;
    }
    /**
     * Handle order submit button click
     * Routes to appropriate payment flow based on payment type
     * @param {Event} event - The click event
     */
    async handleSubmit(event) {
      var _a2, _b;
      event.preventDefault();
      console.log("Order submit button clicked", {
        buttonId: this.element.id,
        paymentType: this.paymentTypeValue,
        timestamp: (/* @__PURE__ */ new Date()).toISOString()
      });
      this.showLoading();
      try {
        if (this.paymentTypeValue === "wallet") {
          await this.handleStripeCheckout();
        } else {
          await this.handlePaymentIntent();
        }
      } catch (error2) {
        console.error("Order submission failed", error2);
        this.showError(error2.message || ((_b = (_a2 = window.oStripe) == null ? void 0 : _a2.i18n) == null ? void 0 : _b.PAYMENT_FAILED) || "Payment processing failed");
      } finally {
        this.hideLoading();
      }
    }
    /**
     * Handle Stripe Checkout flow (hosted payment page)
     * Used for wallet payments (Apple Pay, Google Pay)
     */
    async handleStripeCheckout() {
      var _a2, _b, _c, _d, _e, _f, _g, _h, _i, _j;
      if (!window.Stripe) {
        throw new Error(((_b = (_a2 = window.oStripe) == null ? void 0 : _a2.i18n) == null ? void 0 : _b.JS_NOT_LOADED) || "Stripe.js not loaded");
      }
      if (!this.hasPublishableKeyValue || !this.publishableKeyValue) {
        throw new Error(((_d = (_c = window.oStripe) == null ? void 0 : _c.i18n) == null ? void 0 : _d.KEY_NOT_CONFIGURED) || "Stripe publishable key not configured");
      }
      const stripe = Stripe(this.publishableKeyValue);
      this.setStatus(((_f = (_e = window.oStripe) == null ? void 0 : _e.i18n) == null ? void 0 : _f.CREATING_SESSION) || "Creating checkout session...");
      const response = await fetch(this.urlValue, {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          capture: "automatic"
          // Can be made configurable
        }),
        credentials: "same-origin"
      });
      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(errorData.error || ((_h = (_g = window.oStripe) == null ? void 0 : _g.i18n) == null ? void 0 : _h.SESSION_FAILED) || "Failed to create checkout session");
      }
      const data = await response.json();
      if (!data.id) {
        throw new Error(((_j = (_i = window.oStripe) == null ? void 0 : _i.i18n) == null ? void 0 : _j.SESSION_INVALID) || "Invalid checkout session response");
      }
      console.log("Checkout Session created:", data.id, "URL:", data.url);
      console.log("Debug info:", data._debug);
      if (data.url) {
        window.location.href = data.url;
        return;
      }
      const { error: error2 } = await stripe.redirectToCheckout({
        sessionId: data.id
      });
      if (error2) {
        throw error2;
      }
    }
    /**
     * Handle Payment Intent flow (card element)
     * Used for card payments
     */
    async handlePaymentIntent() {
      var _a2, _b, _c, _d, _e, _f;
      const stripeOrderController = this.getStripeOrderController();
      if (!stripeOrderController) {
        throw new Error(((_b = (_a2 = window.oStripe) == null ? void 0 : _a2.i18n) == null ? void 0 : _b.CONTROLLER_NOT_FOUND) || "Stripe payment controller not found. Please refresh the page.");
      }
      if (!stripeOrderController.card || !stripeOrderController.stripe) {
        console.error("Payment form not ready:", {
          hasCard: !!stripeOrderController.card,
          hasStripe: !!stripeOrderController.stripe
        });
        throw new Error(((_d = (_c = window.oStripe) == null ? void 0 : _c.i18n) == null ? void 0 : _d.FORM_NOT_READY) || "Payment form not initialized. Please refresh the page.");
      }
      console.log("Stripe controller ready:", {
        hasCard: !!stripeOrderController.card,
        hasStripe: !!stripeOrderController.stripe
      });
      const paymentIntentResponse = await this.handlePayment();
      const clientSecret = paymentIntentResponse.clientSecret;
      const confirmPaymentResponse = await stripeOrderController.stripe.confirmCardPayment(clientSecret, {
        payment_method: {
          card: stripeOrderController.card
        }
      });
      if (confirmPaymentResponse.error) {
        throw new Error(confirmPaymentResponse.error.message);
      } else if (confirmPaymentResponse.paymentIntent && confirmPaymentResponse.paymentIntent.status === "succeeded") {
        console.log("Payment succeeded", confirmPaymentResponse.paymentIntent);
      } else {
        throw new Error(((_f = (_e = window.oStripe) == null ? void 0 : _e.i18n) == null ? void 0 : _f.PAYMENT_NOT_COMPLETED) || "Payment not completed");
      }
    }
    /**
     * Fetch payment intent creation URL and return response
     * @returns {Promise<Object>} Payment intent response with clientSecret, amount, currency
     * @throws {Error} If fetch fails or response is not ok
     */
    async handlePayment() {
      var _a2, _b, _c, _d;
      if (!this.hasUrlValue) {
        throw new Error(((_b = (_a2 = window.oStripe) == null ? void 0 : _a2.i18n) == null ? void 0 : _b.URL_NOT_CONFIGURED) || "Payment URL is not configured");
      }
      console.log("Creating payment intent via URL:", this.urlValue);
      const response = await fetch(this.urlValue, {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        credentials: "same-origin"
      });
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      const responseData = await response.json();
      if (responseData.error) {
        throw new Error(responseData.error);
      }
      if (!responseData.success || !responseData.clientSecret) {
        throw new Error(((_d = (_c = window.oStripe) == null ? void 0 : _c.i18n) == null ? void 0 : _d.INTENT_INVALID) || "Invalid payment intent response");
      }
      return responseData;
    }
    /**
     * Show loading state on button
     */
    showLoading() {
      var _a2, _b;
      this.element.disabled = true;
      this.originalText = this.element.textContent;
      this.element.textContent = ((_b = (_a2 = window.oStripe) == null ? void 0 : _a2.i18n) == null ? void 0 : _b.PROCESSING) || "Processing...";
    }
    /**
     * Hide loading state on button
     */
    hideLoading() {
      this.element.disabled = false;
      if (this.originalText) {
        this.element.textContent = this.originalText;
      }
    }
    /**
     * Set status message
     * @param {string} message - Status message to display
     */
    setStatus(message) {
      if (this.hasStatusTarget) {
        this.statusTarget.textContent = message;
        this.statusTarget.className = "mt-2 text-center text-muted";
      }
      console.log("Status:", message);
    }
    /**
     * Show error message
     * @param {string} message - Error message to display
     */
    showError(message) {
      if (this.hasStatusTarget) {
        this.statusTarget.textContent = message;
        this.statusTarget.className = "mt-2 text-center text-danger";
      } else {
        alert("Error: " + message);
      }
    }
  };
  __publicField(order_submit_controller_default, "targets", ["status"]);
  __publicField(order_submit_controller_default, "values", {
    url: String,
    paymentType: String,
    publishableKey: String
  });

  // resources/build/js/controllers/agb_validation_controller.js
  var agb_validation_controller_default = class extends Controller {
    /**
     * Initialize the controller when connected to the DOM
     */
    connect() {
      console.log("AGB Validation controller connected", {
        enabled: this.enabledValue,
        hasCheckbox: this.hasCheckboxTarget,
        hasSubmitButtons: this.hasSubmitButtonTarget
      });
      if (this.enabledValue) {
        this.updateButtonStates();
      }
    }
    /**
     * Handle checkbox state changes
     */
    checkboxChanged() {
      if (this.enabledValue) {
        this.updateButtonStates();
      }
    }
    /**
     * Update the disabled state of all submit buttons based on checkbox state
     */
    updateButtonStates() {
      if (!this.hasCheckboxTarget || !this.hasSubmitButtonTarget) {
        return;
      }
      const isChecked = this.checkboxTarget.checked;
      this.submitButtonTargets.forEach((button) => {
        var _a2, _b;
        button.disabled = !isChecked;
        if (isChecked) {
          button.classList.remove("disabled");
          button.removeAttribute("title");
        } else {
          button.classList.add("disabled");
          button.setAttribute("title", ((_b = (_a2 = window.oStripe) == null ? void 0 : _a2.i18n) == null ? void 0 : _b.AGB_REQUIRED) || "Please accept the terms and conditions");
        }
      });
    }
    /**
     * Handle form submission attempts
     * @param {Event} event - The submit event
     */
    handleSubmit(event) {
      if (!this.enabledValue) {
        return true;
      }
      if (!this.hasCheckboxTarget || !this.checkboxTarget.checked) {
        event.preventDefault();
        event.stopPropagation();
        if (this.hasCheckboxTarget) {
          const checkboxWrapper = this.checkboxTarget.closest(".form-check");
          if (checkboxWrapper) {
            checkboxWrapper.classList.add("border", "border-danger", "p-2", "rounded");
            setTimeout(() => {
              checkboxWrapper.classList.remove("border", "border-danger", "p-2", "rounded");
            }, 3e3);
          }
        }
        return false;
      }
      return true;
    }
  };
  __publicField(agb_validation_controller_default, "targets", ["checkbox", "submitButton"]);
  __publicField(agb_validation_controller_default, "values", {
    enabled: Boolean
  });

  // ../onepage-checkout/resources/build/js/utils/event_bus.js
  var EventBus = class _EventBus {
    constructor() {
      if (_EventBus.instance) {
        return _EventBus.instance;
      }
      this.listeners = /* @__PURE__ */ new Map();
      this.debug = false;
      this.eventHistory = [];
      this.maxHistorySize = 100;
      _EventBus.instance = this;
    }
    /**
     * Włącz/wyłącz tryb debug
     */
    setDebug(enabled) {
      this.debug = enabled;
    }
    /**
     * Zarejestruj listener dla eventu
     * @param {string} eventName - Nazwa eventu (np. 'oe:basket:updated')
     * @param {function} handler - Funkcja handlera (data) => void
     * @returns {function} Funkcja do usunięcia listenera
     */
    on(eventName, handler) {
      if (!this.listeners.has(eventName)) {
        this.listeners.set(eventName, /* @__PURE__ */ new Set());
      }
      this.listeners.get(eventName).add(handler);
      if (this.debug) {
        console.log(`[EventBus] Registered listener for "${eventName}"`, {
          listenersCount: this.listeners.get(eventName).size
        });
      }
      return () => this.off(eventName, handler);
    }
    /**
     * Zarejestruj listener, który wykona się tylko raz
     * @param {string} eventName - Nazwa eventu
     * @param {function} handler - Funkcja handlera
     * @returns {function} Funkcja do usunięcia listenera
     */
    once(eventName, handler) {
      const onceHandler = (data) => {
        handler(data);
        this.off(eventName, onceHandler);
      };
      return this.on(eventName, onceHandler);
    }
    /**
     * Usuń listener
     * @param {string} eventName - Nazwa eventu
     * @param {function} handler - Funkcja handlera do usunięcia
     */
    off(eventName, handler) {
      if (!this.listeners.has(eventName)) {
        return;
      }
      const handlers = this.listeners.get(eventName);
      handlers.delete(handler);
      if (handlers.size === 0) {
        this.listeners.delete(eventName);
      }
      if (this.debug) {
        console.log(`[EventBus] Removed listener for "${eventName}"`, {
          listenersCount: handlers.size
        });
      }
    }
    /**
     * Usuń wszystkie listenery dla danego eventu
     * @param {string} eventName - Nazwa eventu
     */
    offAll(eventName) {
      if (this.listeners.has(eventName)) {
        this.listeners.delete(eventName);
        if (this.debug) {
          console.log(`[EventBus] Removed all listeners for "${eventName}"`);
        }
      }
    }
    /**
     * Wyemituj event
     * @param {string} eventName - Nazwa eventu
     * @param {*} data - Dane do przekazania
     */
    emit(eventName, data = {}) {
      const timestamp = Date.now();
      this.eventHistory.push({
        eventName,
        data,
        timestamp,
        listenersCount: this.listeners.has(eventName) ? this.listeners.get(eventName).size : 0
      });
      if (this.eventHistory.length > this.maxHistorySize) {
        this.eventHistory.shift();
      }
      if (this.debug) {
        console.log(`[EventBus] Event emitted: "${eventName}"`, {
          data,
          listenersCount: this.listeners.has(eventName) ? this.listeners.get(eventName).size : 0,
          timestamp: new Date(timestamp).toISOString()
        });
      }
      if (this.listeners.has(eventName)) {
        const handlers = Array.from(this.listeners.get(eventName));
        handlers.forEach((handler) => {
          try {
            handler(data);
          } catch (error2) {
            console.error(`[EventBus] Error in handler for "${eventName}":`, error2);
          }
        });
      } else if (this.debug) {
        console.warn(`[EventBus] No listeners for "${eventName}"`);
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
          this.emit(eventName, data);
          resolve();
        }, 0);
      });
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
        this.emit(eventName, data);
      }, delay);
    }
    /**
     * Poczekaj na event (zwraca Promise)
     * Przydatne w testach i async flows
     * @param {string} eventName - Nazwa eventu
     * @param {number} timeout - Timeout w ms (opcjonalny)
     * @returns {Promise} Promise który resolve'uje się z danymi eventu
     */
    waitFor(eventName, timeout = 5e3) {
      return new Promise((resolve, reject) => {
        const timer = timeout > 0 ? setTimeout(() => {
          this.off(eventName, handler);
          reject(new Error(`[EventBus] Timeout waiting for event "${eventName}"`));
        }, timeout) : null;
        const handler = (data) => {
          if (timer)
            clearTimeout(timer);
          resolve(data);
        };
        this.once(eventName, handler);
      });
    }
    /**
     * Sprawdź czy są listenery dla danego eventu
     * @param {string} eventName - Nazwa eventu
     * @returns {boolean}
     */
    hasListeners(eventName) {
      return this.listeners.has(eventName) && this.listeners.get(eventName).size > 0;
    }
    /**
     * Pobierz liczbę listenerów dla eventu
     * @param {string} eventName - Nazwa eventu
     * @returns {number}
     */
    getListenersCount(eventName) {
      return this.listeners.has(eventName) ? this.listeners.get(eventName).size : 0;
    }
    /**
     * Pobierz wszystkie zarejestrowane eventy
     * @returns {string[]}
     */
    getRegisteredEvents() {
      return Array.from(this.listeners.keys());
    }
    /**
     * Pobierz historię eventów
     * @param {number} limit - Limit eventów do zwrócenia (opcjonalny)
     * @returns {Array}
     */
    getEventHistory(limit = 50) {
      return this.eventHistory.slice(-limit);
    }
    /**
     * Wyczyść historię eventów
     */
    clearHistory() {
      this.eventHistory = [];
    }
    /**
     * Wyczyść wszystkie listenery (użyj ostrożnie!)
     */
    clearAll() {
      this.listeners.clear();
      if (this.debug) {
        console.log("[EventBus] All listeners cleared");
      }
    }
    /**
     * Wypisz statystyki EventBus
     */
    printStats() {
      console.group("[EventBus] Statistics");
      console.log("Registered events:", this.getRegisteredEvents());
      console.log("Total listeners:", Array.from(this.listeners.values()).reduce((sum, set) => sum + set.size, 0));
      console.log("Event history size:", this.eventHistory.length);
      console.group("Listeners per event:");
      this.listeners.forEach((handlers, eventName) => {
        console.log(`  ${eventName}: ${handlers.size}`);
      });
      console.groupEnd();
      console.group("Recent events:");
      this.getEventHistory(10).forEach((event) => {
        console.log(`  ${event.eventName} (${event.listenersCount} listeners) - ${new Date(event.timestamp).toLocaleTimeString()}`);
      });
      console.groupEnd();
      console.groupEnd();
    }
  };
  var eventBus = new EventBus();
  var _a;
  if (typeof window !== "undefined" && ((_a = window.location) == null ? void 0 : _a.hostname) === "localhost") {
    eventBus.setDebug(true);
  }
  if (typeof window !== "undefined") {
    window.eventBus = eventBus;
  }

  // ../onepage-checkout/resources/build/js/mixins/event_bus_mixin.js
  function withEventBus(BaseController) {
    return class extends BaseController {
      constructor(...args) {
        super(...args);
        this._eventBusListeners = [];
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
        const { once = false } = options;
        const boundHandler = handler.bind(this);
        const controllerName = this.identifier || this.constructor.name;
        const debugHandler = (data) => {
          if (eventBus.debug) {
            console.log(`[${controllerName}] Received event "${eventName}"`, data);
          }
          boundHandler(data);
        };
        const removeListener = once ? eventBus.once(eventName, debugHandler) : eventBus.on(eventName, debugHandler);
        this._eventBusListeners.push({ eventName, handler: debugHandler, removeListener });
        return removeListener;
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
        return this.listen(eventName, handler, { once: true });
      }
      /**
       * Wyemituj event przez EventBus
       *
       * @param {string} eventName - Nazwa eventu
       * @param {*} data - Dane do przekazania
       */
      broadcast(eventName, data = {}) {
        const controllerName = this.identifier || this.constructor.name;
        if (eventBus.debug) {
          console.log(`[${controllerName}] Broadcasting event "${eventName}"`, data);
        }
        eventBus.emit(eventName, data);
      }
      /**
       * Wyemituj event asynchronicznie
       *
       * @param {string} eventName - Nazwa eventu
       * @param {*} data - Dane do przekazania
       * @returns {Promise}
       */
      async broadcastAsync(eventName, data = {}) {
        return eventBus.emitAsync(eventName, data);
      }
      /**
       * Poczekaj na event
       * Przydatne w async flows
       *
       * @param {string} eventName - Nazwa eventu
       * @param {number} timeout - Timeout w ms
       * @returns {Promise}
       */
      async waitForEvent(eventName, timeout = 5e3) {
        return eventBus.waitFor(eventName, timeout);
      }
      /**
       * Usuń konkretny listener
       *
       * @param {string} eventName - Nazwa eventu
       * @param {function} handler - Handler do usunięcia
       */
      stopListening(eventName, handler) {
        eventBus.off(eventName, handler);
        this._eventBusListeners = this._eventBusListeners.filter(
          (listener) => !(listener.eventName === eventName && listener.handler === handler)
        );
      }
      /**
       * Usuń wszystkie listenery tego kontrolera
       * Automatycznie wywoływane w disconnect()
       */
      stopListeningAll() {
        this._eventBusListeners.forEach(({ removeListener }) => {
          removeListener();
        });
        this._eventBusListeners = [];
        if (eventBus.debug) {
          const controllerName = this.identifier || this.constructor.name;
          console.log(`[${controllerName}] All EventBus listeners removed`);
        }
      }
      /**
       * Override disconnect() żeby automatycznie czyścić listenery
       */
      disconnect() {
        this.stopListeningAll();
        if (super.disconnect) {
          super.disconnect();
        }
      }
    };
  }

  // resources/build/js/controllers/onepage_stripe_controller.js
  var onepage_stripe_controller_default = class extends withEventBus(Controller) {
    /**
     * Stimulus lifecycle: Controller connected to DOM
     */
    connect() {
      console.log("[OnePageStripeController] Connected");
      this.listen("oe:payment:method-selected", this.handleMethodSelected.bind(this));
      this.listen("oe:payment:confirm-requested", this.handleConfirmRequest.bind(this));
      this.stripe = null;
      this.elements = null;
      this.paymentElement = null;
      this.currentContractId = null;
      this.currentOrderId = null;
    }
    /**
     * Stimulus lifecycle: Controller disconnected from DOM
     */
    disconnect() {
      console.log("[OnePageStripeController] Disconnected");
      if (this.paymentElement) {
        this.paymentElement.destroy();
        this.paymentElement = null;
      }
      this.elements = null;
      this.stripe = null;
    }
    /**
     * Handle oe:payment:method-selected event
     *
     * Event Detail:
     * {
     *   paymentMethodId: string,  // e.g., 'oxidstripe', 'paypal'
     *   paymentMethodTitle: string // e.g., 'Credit Card (Stripe)'
     * }
     *
     * Responsibility:
     * - Check if paymentMethodId matches Stripe
     * - Show Stripe UI if match
     * - Hide Stripe UI if no match
     */
    async handleMethodSelected(event) {
      const { paymentMethodId } = event.detail;
      console.log("[OnePageStripeController] Payment method selected:", paymentMethodId);
      if (!this.isStripeMethod(paymentMethodId)) {
        this.hideStripeUI();
        return;
      }
      this.showStripeUI();
      if (!this.stripe) {
        await this.loadStripeSDK();
      }
      await this.initializePaymentElement();
    }
    /**
     * Handle oe:payment:confirm-requested event
     *
     * Event Detail:
     * {
     *   contractId: string,       // PaymentContract ID
     *   clientSecret: string,     // Stripe client secret (from PaymentIntent)
     *   paymentMethodId: string,  // e.g., 'oxidstripe'
     *   returnUrl: string         // URL to redirect after SCA
     * }
     *
     * Responsibility:
     * - Check if paymentMethodId matches Stripe
     * - Process payment with Stripe SDK
     * - Emit oe:payment:confirmed or oe:payment:failed
     */
    async handleConfirmRequest(event) {
      const { paymentMethodId, clientSecret, contractId, orderId } = event.detail;
      console.log("[OnePageStripeController] Confirm request:", {
        paymentMethodId,
        clientSecret: clientSecret ? "***" : "missing",
        contractId,
        orderId
      });
      if (!this.isStripeMethod(paymentMethodId)) {
        return;
      }
      this.currentContractId = contractId;
      this.currentOrderId = orderId;
      this.showLoader();
      this.hideError();
      try {
        const result = await this.confirmPayment(clientSecret);
        console.log("[OnePageStripeController] Payment confirmed:", result);
        this.broadcastPaymentConfirmed(result);
      } catch (error2) {
        console.error("[OnePageStripeController] Payment failed:", error2);
        this.showError(error2.message);
        this.broadcastPaymentFailed(error2);
      } finally {
        this.hideLoader();
      }
    }
    /**
     * Check if payment method ID belongs to Stripe
     */
    isStripeMethod(paymentMethodId) {
      if (!paymentMethodId) {
        return false;
      }
      const stripePaymentMethods = [
        "oxidstripe",
        "oxidstripe_card",
        "oxidstripe_wallet"
      ];
      return stripePaymentMethods.some(
        (method) => paymentMethodId.toLowerCase().includes(method.toLowerCase())
      );
    }
    /**
     * Load Stripe.js SDK dynamically
     */
    async loadStripeSDK() {
      if (window.Stripe) {
        this.stripe = window.Stripe(this.publishableKeyValue);
        return;
      }
      console.log("[OnePageStripeController] Loading Stripe.js SDK...");
      await new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src = "https://js.stripe.com/v3/";
        script.async = true;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
      });
      this.stripe = window.Stripe(this.publishableKeyValue);
      console.log("[OnePageStripeController] Stripe.js SDK loaded");
    }
    /**
     * Initialize Stripe Payment Element
     */
    async initializePaymentElement() {
      if (!this.stripe) {
        console.error("[OnePageStripeController] Stripe SDK not loaded");
        return;
      }
      if (this.paymentElement) {
        return;
      }
      console.log("[OnePageStripeController] Initializing Payment Element...");
      this.elements = this.stripe.elements({
        mode: "payment",
        amount: 1e3,
        // Placeholder, will be updated with real client secret
        currency: "eur",
        appearance: {
          theme: "stripe"
        }
      });
      this.paymentElement = this.elements.create("payment");
      this.paymentElement.mount(this.elementTarget);
      console.log("[OnePageStripeController] Payment Element initialized");
    }
    /**
     * Confirm payment with Stripe SDK
     *
     * @param {string} clientSecret - Stripe PaymentIntent client secret
     * @returns {Promise<Object>} - Payment result
     */
    async confirmPayment(clientSecret) {
      var _a2, _b;
      if (!this.stripe || !this.elements) {
        throw new Error("Stripe SDK not initialized");
      }
      console.log("[OnePageStripeController] Confirming payment with Stripe...");
      this.elements.update({
        clientSecret
      });
      const result = await this.stripe.confirmPayment({
        elements: this.elements,
        confirmParams: {
          return_url: this.returnUrlValue || window.location.origin + "/order"
        },
        redirect: "if_required"
        // Only redirect if 3D Secure is needed
      });
      if (result.error) {
        throw new Error(result.error.message || "Payment confirmation failed");
      }
      if (((_a2 = result.paymentIntent) == null ? void 0 : _a2.status) === "succeeded") {
        return {
          paymentIntentId: result.paymentIntent.id,
          status: result.paymentIntent.status,
          amount: result.paymentIntent.amount,
          currency: result.paymentIntent.currency
        };
      }
      throw new Error(`Payment not confirmed. Status: ${((_b = result.paymentIntent) == null ? void 0 : _b.status) || "unknown"}`);
    }
    /**
     * Broadcast oe:payment:confirmed event
     */
    broadcastPaymentConfirmed(paymentResult) {
      this.broadcast("oe:payment:confirmed", {
        provider: "stripe",
        contractId: this.currentContractId,
        orderId: this.currentOrderId,
        transactionId: paymentResult.paymentIntentId,
        metadata: paymentResult
      });
      console.log("[OnePageStripeController] Payment confirmed event dispatched");
    }
    /**
     * Broadcast oe:payment:failed event
     */
    broadcastPaymentFailed(error2) {
      this.broadcast("oe:payment:failed", {
        provider: "stripe",
        contractId: this.currentContractId,
        orderId: this.currentOrderId,
        error: error2.message || "Payment failed",
        errorCode: error2.code || "STRIPE_ERROR"
      });
      console.log("[OnePageStripeController] Payment failed event dispatched");
    }
    /**
     * UI Helper: Show Stripe UI
     * Shows the entire Stripe provider wrapper (not just the payment element)
     */
    showStripeUI() {
      this.element.style.display = "block";
      if (this.hasElementTarget) {
        this.elementTarget.style.display = "block";
      }
    }
    /**
     * UI Helper: Hide Stripe UI
     * Hides the entire Stripe provider wrapper
     */
    hideStripeUI() {
      this.element.style.display = "none";
    }
    /**
     * UI Helper: Show loader
     */
    showLoader() {
      if (this.hasLoaderTarget) {
        this.loaderTarget.style.display = "block";
      }
    }
    /**
     * UI Helper: Hide loader
     */
    hideLoader() {
      if (this.hasLoaderTarget) {
        this.loaderTarget.style.display = "none";
      }
    }
    /**
     * UI Helper: Show error message
     */
    showError(message) {
      if (this.hasErrorTarget) {
        this.errorTarget.textContent = message;
        this.errorTarget.style.display = "block";
      }
    }
    /**
     * UI Helper: Hide error message
     */
    hideError() {
      if (this.hasErrorTarget) {
        this.errorTarget.style.display = "none";
        this.errorTarget.textContent = "";
      }
    }
  };
  __publicField(onepage_stripe_controller_default, "values", {
    publishableKey: String,
    mode: String,
    returnUrl: String
  });
  __publicField(onepage_stripe_controller_default, "targets", ["element", "loader", "error"]);

  // resources/build/js/app.js
  window.Stimulus = Application.start();
  Stimulus.register("stripe-order", stripe_order_controller_default);
  Stimulus.register("order-submit", order_submit_controller_default);
  Stimulus.register("agb-validation", agb_validation_controller_default);
  Stimulus.register("onepage-stripe", onepage_stripe_controller_default);
  if (true) {
    Stimulus.debug = true;
    console.log("Stripe Module: Stimulus initialized with controllers:", Stimulus.router.modulesByIdentifier);
  }
  console.log("Stripe Module: JavaScript loaded and ready");
})();
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsiLi4vLi4vbm9kZV9tb2R1bGVzL0Bob3R3aXJlZC9zdGltdWx1cy9kaXN0L3N0aW11bHVzLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9jb250cm9sbGVycy9zdHJpcGVfb3JkZXJfY29udHJvbGxlci5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvY29udHJvbGxlcnMvb3JkZXJfc3VibWl0X2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL2NvbnRyb2xsZXJzL2FnYl92YWxpZGF0aW9uX2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vLi4vb25lcGFnZS1jaGVja291dC9yZXNvdXJjZXMvYnVpbGQvanMvdXRpbHMvZXZlbnRfYnVzLmpzIiwgIi4uLy4uLy4uL29uZXBhZ2UtY2hlY2tvdXQvcmVzb3VyY2VzL2J1aWxkL2pzL21peGlucy9ldmVudF9idXNfbWl4aW4uanMiLCAiLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL2NvbnRyb2xsZXJzL29uZXBhZ2Vfc3RyaXBlX2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL2FwcC5qcyJdLAogICJzb3VyY2VzQ29udGVudCI6IFsiLypcblN0aW11bHVzIDMuMi4xXG5Db3B5cmlnaHQgXHUwMEE5IDIwMjMgQmFzZWNhbXAsIExMQ1xuICovXG5jbGFzcyBFdmVudExpc3RlbmVyIHtcbiAgICBjb25zdHJ1Y3RvcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpIHtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldCA9IGV2ZW50VGFyZ2V0O1xuICAgICAgICB0aGlzLmV2ZW50TmFtZSA9IGV2ZW50TmFtZTtcbiAgICAgICAgdGhpcy5ldmVudE9wdGlvbnMgPSBldmVudE9wdGlvbnM7XG4gICAgICAgIHRoaXMudW5vcmRlcmVkQmluZGluZ3MgPSBuZXcgU2V0KCk7XG4gICAgfVxuICAgIGNvbm5lY3QoKSB7XG4gICAgICAgIHRoaXMuZXZlbnRUYXJnZXQuYWRkRXZlbnRMaXN0ZW5lcih0aGlzLmV2ZW50TmFtZSwgdGhpcywgdGhpcy5ldmVudE9wdGlvbnMpO1xuICAgIH1cbiAgICBkaXNjb25uZWN0KCkge1xuICAgICAgICB0aGlzLmV2ZW50VGFyZ2V0LnJlbW92ZUV2ZW50TGlzdGVuZXIodGhpcy5ldmVudE5hbWUsIHRoaXMsIHRoaXMuZXZlbnRPcHRpb25zKTtcbiAgICB9XG4gICAgYmluZGluZ0Nvbm5lY3RlZChiaW5kaW5nKSB7XG4gICAgICAgIHRoaXMudW5vcmRlcmVkQmluZGluZ3MuYWRkKGJpbmRpbmcpO1xuICAgIH1cbiAgICBiaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcpIHtcbiAgICAgICAgdGhpcy51bm9yZGVyZWRCaW5kaW5ncy5kZWxldGUoYmluZGluZyk7XG4gICAgfVxuICAgIGhhbmRsZUV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IGV4dGVuZGVkRXZlbnQgPSBleHRlbmRFdmVudChldmVudCk7XG4gICAgICAgIGZvciAoY29uc3QgYmluZGluZyBvZiB0aGlzLmJpbmRpbmdzKSB7XG4gICAgICAgICAgICBpZiAoZXh0ZW5kZWRFdmVudC5pbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWQpIHtcbiAgICAgICAgICAgICAgICBicmVhaztcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgIGJpbmRpbmcuaGFuZGxlRXZlbnQoZXh0ZW5kZWRFdmVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgaGFzQmluZGluZ3MoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnVub3JkZXJlZEJpbmRpbmdzLnNpemUgPiAwO1xuICAgIH1cbiAgICBnZXQgYmluZGluZ3MoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMudW5vcmRlcmVkQmluZGluZ3MpLnNvcnQoKGxlZnQsIHJpZ2h0KSA9PiB7XG4gICAgICAgICAgICBjb25zdCBsZWZ0SW5kZXggPSBsZWZ0LmluZGV4LCByaWdodEluZGV4ID0gcmlnaHQuaW5kZXg7XG4gICAgICAgICAgICByZXR1cm4gbGVmdEluZGV4IDwgcmlnaHRJbmRleCA/IC0xIDogbGVmdEluZGV4ID4gcmlnaHRJbmRleCA/IDEgOiAwO1xuICAgICAgICB9KTtcbiAgICB9XG59XG5mdW5jdGlvbiBleHRlbmRFdmVudChldmVudCkge1xuICAgIGlmIChcImltbWVkaWF0ZVByb3BhZ2F0aW9uU3RvcHBlZFwiIGluIGV2ZW50KSB7XG4gICAgICAgIHJldHVybiBldmVudDtcbiAgICB9XG4gICAgZWxzZSB7XG4gICAgICAgIGNvbnN0IHsgc3RvcEltbWVkaWF0ZVByb3BhZ2F0aW9uIH0gPSBldmVudDtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24oZXZlbnQsIHtcbiAgICAgICAgICAgIGltbWVkaWF0ZVByb3BhZ2F0aW9uU3RvcHBlZDogZmFsc2UsXG4gICAgICAgICAgICBzdG9wSW1tZWRpYXRlUHJvcGFnYXRpb24oKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5pbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWQgPSB0cnVlO1xuICAgICAgICAgICAgICAgIHN0b3BJbW1lZGlhdGVQcm9wYWdhdGlvbi5jYWxsKHRoaXMpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSk7XG4gICAgfVxufVxuXG5jbGFzcyBEaXNwYXRjaGVyIHtcbiAgICBjb25zdHJ1Y3RvcihhcHBsaWNhdGlvbikge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uID0gYXBwbGljYXRpb247XG4gICAgICAgIHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMgPSBuZXcgTWFwKCk7XG4gICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IHRydWU7XG4gICAgICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJzLmZvckVhY2goKGV2ZW50TGlzdGVuZXIpID0+IGV2ZW50TGlzdGVuZXIuY29ubmVjdCgpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgICAgIHRoaXMuZXZlbnRMaXN0ZW5lcnMuZm9yRWFjaCgoZXZlbnRMaXN0ZW5lcikgPT4gZXZlbnRMaXN0ZW5lci5kaXNjb25uZWN0KCkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBldmVudExpc3RlbmVycygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5ldmVudExpc3RlbmVyTWFwcy52YWx1ZXMoKSkucmVkdWNlKChsaXN0ZW5lcnMsIG1hcCkgPT4gbGlzdGVuZXJzLmNvbmNhdChBcnJheS5mcm9tKG1hcC52YWx1ZXMoKSkpLCBbXSk7XG4gICAgfVxuICAgIGJpbmRpbmdDb25uZWN0ZWQoYmluZGluZykge1xuICAgICAgICB0aGlzLmZldGNoRXZlbnRMaXN0ZW5lckZvckJpbmRpbmcoYmluZGluZykuYmluZGluZ0Nvbm5lY3RlZChiaW5kaW5nKTtcbiAgICB9XG4gICAgYmluZGluZ0Rpc2Nvbm5lY3RlZChiaW5kaW5nLCBjbGVhckV2ZW50TGlzdGVuZXJzID0gZmFsc2UpIHtcbiAgICAgICAgdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJGb3JCaW5kaW5nKGJpbmRpbmcpLmJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZyk7XG4gICAgICAgIGlmIChjbGVhckV2ZW50TGlzdGVuZXJzKVxuICAgICAgICAgICAgdGhpcy5jbGVhckV2ZW50TGlzdGVuZXJzRm9yQmluZGluZyhiaW5kaW5nKTtcbiAgICB9XG4gICAgaGFuZGxlRXJyb3IoZXJyb3IsIG1lc3NhZ2UsIGRldGFpbCA9IHt9KSB7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24uaGFuZGxlRXJyb3IoZXJyb3IsIGBFcnJvciAke21lc3NhZ2V9YCwgZGV0YWlsKTtcbiAgICB9XG4gICAgY2xlYXJFdmVudExpc3RlbmVyc0ZvckJpbmRpbmcoYmluZGluZykge1xuICAgICAgICBjb25zdCBldmVudExpc3RlbmVyID0gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJGb3JCaW5kaW5nKGJpbmRpbmcpO1xuICAgICAgICBpZiAoIWV2ZW50TGlzdGVuZXIuaGFzQmluZGluZ3MoKSkge1xuICAgICAgICAgICAgZXZlbnRMaXN0ZW5lci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLnJlbW92ZU1hcHBlZEV2ZW50TGlzdGVuZXJGb3IoYmluZGluZyk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVtb3ZlTWFwcGVkRXZlbnRMaXN0ZW5lckZvcihiaW5kaW5nKSB7XG4gICAgICAgIGNvbnN0IHsgZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zIH0gPSBiaW5kaW5nO1xuICAgICAgICBjb25zdCBldmVudExpc3RlbmVyTWFwID0gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJNYXBGb3JFdmVudFRhcmdldChldmVudFRhcmdldCk7XG4gICAgICAgIGNvbnN0IGNhY2hlS2V5ID0gdGhpcy5jYWNoZUtleShldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgIGV2ZW50TGlzdGVuZXJNYXAuZGVsZXRlKGNhY2hlS2V5KTtcbiAgICAgICAgaWYgKGV2ZW50TGlzdGVuZXJNYXAuc2l6ZSA9PSAwKVxuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVyTWFwcy5kZWxldGUoZXZlbnRUYXJnZXQpO1xuICAgIH1cbiAgICBmZXRjaEV2ZW50TGlzdGVuZXJGb3JCaW5kaW5nKGJpbmRpbmcpIHtcbiAgICAgICAgY29uc3QgeyBldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMgfSA9IGJpbmRpbmc7XG4gICAgICAgIHJldHVybiB0aGlzLmZldGNoRXZlbnRMaXN0ZW5lcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpO1xuICAgIH1cbiAgICBmZXRjaEV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKSB7XG4gICAgICAgIGNvbnN0IGV2ZW50TGlzdGVuZXJNYXAgPSB0aGlzLmZldGNoRXZlbnRMaXN0ZW5lck1hcEZvckV2ZW50VGFyZ2V0KGV2ZW50VGFyZ2V0KTtcbiAgICAgICAgY29uc3QgY2FjaGVLZXkgPSB0aGlzLmNhY2hlS2V5KGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICAgICAgbGV0IGV2ZW50TGlzdGVuZXIgPSBldmVudExpc3RlbmVyTWFwLmdldChjYWNoZUtleSk7XG4gICAgICAgIGlmICghZXZlbnRMaXN0ZW5lcikge1xuICAgICAgICAgICAgZXZlbnRMaXN0ZW5lciA9IHRoaXMuY3JlYXRlRXZlbnRMaXN0ZW5lcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpO1xuICAgICAgICAgICAgZXZlbnRMaXN0ZW5lck1hcC5zZXQoY2FjaGVLZXksIGV2ZW50TGlzdGVuZXIpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudExpc3RlbmVyO1xuICAgIH1cbiAgICBjcmVhdGVFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICBjb25zdCBldmVudExpc3RlbmVyID0gbmV3IEV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgZXZlbnRMaXN0ZW5lci5jb25uZWN0KCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGV2ZW50TGlzdGVuZXI7XG4gICAgfVxuICAgIGZldGNoRXZlbnRMaXN0ZW5lck1hcEZvckV2ZW50VGFyZ2V0KGV2ZW50VGFyZ2V0KSB7XG4gICAgICAgIGxldCBldmVudExpc3RlbmVyTWFwID0gdGhpcy5ldmVudExpc3RlbmVyTWFwcy5nZXQoZXZlbnRUYXJnZXQpO1xuICAgICAgICBpZiAoIWV2ZW50TGlzdGVuZXJNYXApIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXJNYXAgPSBuZXcgTWFwKCk7XG4gICAgICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJNYXBzLnNldChldmVudFRhcmdldCwgZXZlbnRMaXN0ZW5lck1hcCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGV2ZW50TGlzdGVuZXJNYXA7XG4gICAgfVxuICAgIGNhY2hlS2V5KGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKSB7XG4gICAgICAgIGNvbnN0IHBhcnRzID0gW2V2ZW50TmFtZV07XG4gICAgICAgIE9iamVjdC5rZXlzKGV2ZW50T3B0aW9ucylcbiAgICAgICAgICAgIC5zb3J0KClcbiAgICAgICAgICAgIC5mb3JFYWNoKChrZXkpID0+IHtcbiAgICAgICAgICAgIHBhcnRzLnB1c2goYCR7ZXZlbnRPcHRpb25zW2tleV0gPyBcIlwiIDogXCIhXCJ9JHtrZXl9YCk7XG4gICAgICAgIH0pO1xuICAgICAgICByZXR1cm4gcGFydHMuam9pbihcIjpcIik7XG4gICAgfVxufVxuXG5jb25zdCBkZWZhdWx0QWN0aW9uRGVzY3JpcHRvckZpbHRlcnMgPSB7XG4gICAgc3RvcCh7IGV2ZW50LCB2YWx1ZSB9KSB7XG4gICAgICAgIGlmICh2YWx1ZSlcbiAgICAgICAgICAgIGV2ZW50LnN0b3BQcm9wYWdhdGlvbigpO1xuICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICB9LFxuICAgIHByZXZlbnQoeyBldmVudCwgdmFsdWUgfSkge1xuICAgICAgICBpZiAodmFsdWUpXG4gICAgICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpO1xuICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICB9LFxuICAgIHNlbGYoeyBldmVudCwgdmFsdWUsIGVsZW1lbnQgfSkge1xuICAgICAgICBpZiAodmFsdWUpIHtcbiAgICAgICAgICAgIHJldHVybiBlbGVtZW50ID09PSBldmVudC50YXJnZXQ7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgIH0sXG59O1xuY29uc3QgZGVzY3JpcHRvclBhdHRlcm4gPSAvXig/Oig/OihbXi5dKz8pXFwrKT8oLis/KSg/OlxcLiguKz8pKT8oPzpAKHdpbmRvd3xkb2N1bWVudCkpPy0+KT8oLis/KSg/OiMoW146XSs/KSkoPzo6KC4rKSk/JC87XG5mdW5jdGlvbiBwYXJzZUFjdGlvbkRlc2NyaXB0b3JTdHJpbmcoZGVzY3JpcHRvclN0cmluZykge1xuICAgIGNvbnN0IHNvdXJjZSA9IGRlc2NyaXB0b3JTdHJpbmcudHJpbSgpO1xuICAgIGNvbnN0IG1hdGNoZXMgPSBzb3VyY2UubWF0Y2goZGVzY3JpcHRvclBhdHRlcm4pIHx8IFtdO1xuICAgIGxldCBldmVudE5hbWUgPSBtYXRjaGVzWzJdO1xuICAgIGxldCBrZXlGaWx0ZXIgPSBtYXRjaGVzWzNdO1xuICAgIGlmIChrZXlGaWx0ZXIgJiYgIVtcImtleWRvd25cIiwgXCJrZXl1cFwiLCBcImtleXByZXNzXCJdLmluY2x1ZGVzKGV2ZW50TmFtZSkpIHtcbiAgICAgICAgZXZlbnROYW1lICs9IGAuJHtrZXlGaWx0ZXJ9YDtcbiAgICAgICAga2V5RmlsdGVyID0gXCJcIjtcbiAgICB9XG4gICAgcmV0dXJuIHtcbiAgICAgICAgZXZlbnRUYXJnZXQ6IHBhcnNlRXZlbnRUYXJnZXQobWF0Y2hlc1s0XSksXG4gICAgICAgIGV2ZW50TmFtZSxcbiAgICAgICAgZXZlbnRPcHRpb25zOiBtYXRjaGVzWzddID8gcGFyc2VFdmVudE9wdGlvbnMobWF0Y2hlc1s3XSkgOiB7fSxcbiAgICAgICAgaWRlbnRpZmllcjogbWF0Y2hlc1s1XSxcbiAgICAgICAgbWV0aG9kTmFtZTogbWF0Y2hlc1s2XSxcbiAgICAgICAga2V5RmlsdGVyOiBtYXRjaGVzWzFdIHx8IGtleUZpbHRlcixcbiAgICB9O1xufVxuZnVuY3Rpb24gcGFyc2VFdmVudFRhcmdldChldmVudFRhcmdldE5hbWUpIHtcbiAgICBpZiAoZXZlbnRUYXJnZXROYW1lID09IFwid2luZG93XCIpIHtcbiAgICAgICAgcmV0dXJuIHdpbmRvdztcbiAgICB9XG4gICAgZWxzZSBpZiAoZXZlbnRUYXJnZXROYW1lID09IFwiZG9jdW1lbnRcIikge1xuICAgICAgICByZXR1cm4gZG9jdW1lbnQ7XG4gICAgfVxufVxuZnVuY3Rpb24gcGFyc2VFdmVudE9wdGlvbnMoZXZlbnRPcHRpb25zKSB7XG4gICAgcmV0dXJuIGV2ZW50T3B0aW9uc1xuICAgICAgICAuc3BsaXQoXCI6XCIpXG4gICAgICAgIC5yZWR1Y2UoKG9wdGlvbnMsIHRva2VuKSA9PiBPYmplY3QuYXNzaWduKG9wdGlvbnMsIHsgW3Rva2VuLnJlcGxhY2UoL14hLywgXCJcIildOiAhL14hLy50ZXN0KHRva2VuKSB9KSwge30pO1xufVxuZnVuY3Rpb24gc3RyaW5naWZ5RXZlbnRUYXJnZXQoZXZlbnRUYXJnZXQpIHtcbiAgICBpZiAoZXZlbnRUYXJnZXQgPT0gd2luZG93KSB7XG4gICAgICAgIHJldHVybiBcIndpbmRvd1wiO1xuICAgIH1cbiAgICBlbHNlIGlmIChldmVudFRhcmdldCA9PSBkb2N1bWVudCkge1xuICAgICAgICByZXR1cm4gXCJkb2N1bWVudFwiO1xuICAgIH1cbn1cblxuZnVuY3Rpb24gY2FtZWxpemUodmFsdWUpIHtcbiAgICByZXR1cm4gdmFsdWUucmVwbGFjZSgvKD86W18tXSkoW2EtejAtOV0pL2csIChfLCBjaGFyKSA9PiBjaGFyLnRvVXBwZXJDYXNlKCkpO1xufVxuZnVuY3Rpb24gbmFtZXNwYWNlQ2FtZWxpemUodmFsdWUpIHtcbiAgICByZXR1cm4gY2FtZWxpemUodmFsdWUucmVwbGFjZSgvLS0vZywgXCItXCIpLnJlcGxhY2UoL19fL2csIFwiX1wiKSk7XG59XG5mdW5jdGlvbiBjYXBpdGFsaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLmNoYXJBdCgwKS50b1VwcGVyQ2FzZSgpICsgdmFsdWUuc2xpY2UoMSk7XG59XG5mdW5jdGlvbiBkYXNoZXJpemUodmFsdWUpIHtcbiAgICByZXR1cm4gdmFsdWUucmVwbGFjZSgvKFtBLVpdKS9nLCAoXywgY2hhcikgPT4gYC0ke2NoYXIudG9Mb3dlckNhc2UoKX1gKTtcbn1cbmZ1bmN0aW9uIHRva2VuaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLm1hdGNoKC9bXlxcc10rL2cpIHx8IFtdO1xufVxuXG5mdW5jdGlvbiBpc1NvbWV0aGluZyhvYmplY3QpIHtcbiAgICByZXR1cm4gb2JqZWN0ICE9PSBudWxsICYmIG9iamVjdCAhPT0gdW5kZWZpbmVkO1xufVxuZnVuY3Rpb24gaGFzUHJvcGVydHkob2JqZWN0LCBwcm9wZXJ0eSkge1xuICAgIHJldHVybiBPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwob2JqZWN0LCBwcm9wZXJ0eSk7XG59XG5cbmNvbnN0IGFsbE1vZGlmaWVycyA9IFtcIm1ldGFcIiwgXCJjdHJsXCIsIFwiYWx0XCIsIFwic2hpZnRcIl07XG5jbGFzcyBBY3Rpb24ge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGluZGV4LCBkZXNjcmlwdG9yLCBzY2hlbWEpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5pbmRleCA9IGluZGV4O1xuICAgICAgICB0aGlzLmV2ZW50VGFyZ2V0ID0gZGVzY3JpcHRvci5ldmVudFRhcmdldCB8fCBlbGVtZW50O1xuICAgICAgICB0aGlzLmV2ZW50TmFtZSA9IGRlc2NyaXB0b3IuZXZlbnROYW1lIHx8IGdldERlZmF1bHRFdmVudE5hbWVGb3JFbGVtZW50KGVsZW1lbnQpIHx8IGVycm9yKFwibWlzc2luZyBldmVudCBuYW1lXCIpO1xuICAgICAgICB0aGlzLmV2ZW50T3B0aW9ucyA9IGRlc2NyaXB0b3IuZXZlbnRPcHRpb25zIHx8IHt9O1xuICAgICAgICB0aGlzLmlkZW50aWZpZXIgPSBkZXNjcmlwdG9yLmlkZW50aWZpZXIgfHwgZXJyb3IoXCJtaXNzaW5nIGlkZW50aWZpZXJcIik7XG4gICAgICAgIHRoaXMubWV0aG9kTmFtZSA9IGRlc2NyaXB0b3IubWV0aG9kTmFtZSB8fCBlcnJvcihcIm1pc3NpbmcgbWV0aG9kIG5hbWVcIik7XG4gICAgICAgIHRoaXMua2V5RmlsdGVyID0gZGVzY3JpcHRvci5rZXlGaWx0ZXIgfHwgXCJcIjtcbiAgICAgICAgdGhpcy5zY2hlbWEgPSBzY2hlbWE7XG4gICAgfVxuICAgIHN0YXRpYyBmb3JUb2tlbih0b2tlbiwgc2NoZW1hKSB7XG4gICAgICAgIHJldHVybiBuZXcgdGhpcyh0b2tlbi5lbGVtZW50LCB0b2tlbi5pbmRleCwgcGFyc2VBY3Rpb25EZXNjcmlwdG9yU3RyaW5nKHRva2VuLmNvbnRlbnQpLCBzY2hlbWEpO1xuICAgIH1cbiAgICB0b1N0cmluZygpIHtcbiAgICAgICAgY29uc3QgZXZlbnRGaWx0ZXIgPSB0aGlzLmtleUZpbHRlciA/IGAuJHt0aGlzLmtleUZpbHRlcn1gIDogXCJcIjtcbiAgICAgICAgY29uc3QgZXZlbnRUYXJnZXQgPSB0aGlzLmV2ZW50VGFyZ2V0TmFtZSA/IGBAJHt0aGlzLmV2ZW50VGFyZ2V0TmFtZX1gIDogXCJcIjtcbiAgICAgICAgcmV0dXJuIGAke3RoaXMuZXZlbnROYW1lfSR7ZXZlbnRGaWx0ZXJ9JHtldmVudFRhcmdldH0tPiR7dGhpcy5pZGVudGlmaWVyfSMke3RoaXMubWV0aG9kTmFtZX1gO1xuICAgIH1cbiAgICBzaG91bGRJZ25vcmVLZXlib2FyZEV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGlmICghdGhpcy5rZXlGaWx0ZXIpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBjb25zdCBmaWx0ZXJzID0gdGhpcy5rZXlGaWx0ZXIuc3BsaXQoXCIrXCIpO1xuICAgICAgICBpZiAodGhpcy5rZXlGaWx0ZXJEaXNzYXRpc2ZpZWQoZXZlbnQsIGZpbHRlcnMpKSB7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgICAgICBjb25zdCBzdGFuZGFyZEZpbHRlciA9IGZpbHRlcnMuZmlsdGVyKChrZXkpID0+ICFhbGxNb2RpZmllcnMuaW5jbHVkZXMoa2V5KSlbMF07XG4gICAgICAgIGlmICghc3RhbmRhcmRGaWx0ZXIpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBpZiAoIWhhc1Byb3BlcnR5KHRoaXMua2V5TWFwcGluZ3MsIHN0YW5kYXJkRmlsdGVyKSkge1xuICAgICAgICAgICAgZXJyb3IoYGNvbnRhaW5zIHVua25vd24ga2V5IGZpbHRlcjogJHt0aGlzLmtleUZpbHRlcn1gKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gdGhpcy5rZXlNYXBwaW5nc1tzdGFuZGFyZEZpbHRlcl0udG9Mb3dlckNhc2UoKSAhPT0gZXZlbnQua2V5LnRvTG93ZXJDYXNlKCk7XG4gICAgfVxuICAgIHNob3VsZElnbm9yZU1vdXNlRXZlbnQoZXZlbnQpIHtcbiAgICAgICAgaWYgKCF0aGlzLmtleUZpbHRlcikge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGNvbnN0IGZpbHRlcnMgPSBbdGhpcy5rZXlGaWx0ZXJdO1xuICAgICAgICBpZiAodGhpcy5rZXlGaWx0ZXJEaXNzYXRpc2ZpZWQoZXZlbnQsIGZpbHRlcnMpKSB7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgfVxuICAgIGdldCBwYXJhbXMoKSB7XG4gICAgICAgIGNvbnN0IHBhcmFtcyA9IHt9O1xuICAgICAgICBjb25zdCBwYXR0ZXJuID0gbmV3IFJlZ0V4cChgXmRhdGEtJHt0aGlzLmlkZW50aWZpZXJ9LSguKyktcGFyYW0kYCwgXCJpXCIpO1xuICAgICAgICBmb3IgKGNvbnN0IHsgbmFtZSwgdmFsdWUgfSBvZiBBcnJheS5mcm9tKHRoaXMuZWxlbWVudC5hdHRyaWJ1dGVzKSkge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2ggPSBuYW1lLm1hdGNoKHBhdHRlcm4pO1xuICAgICAgICAgICAgY29uc3Qga2V5ID0gbWF0Y2ggJiYgbWF0Y2hbMV07XG4gICAgICAgICAgICBpZiAoa2V5KSB7XG4gICAgICAgICAgICAgICAgcGFyYW1zW2NhbWVsaXplKGtleSldID0gdHlwZWNhc3QodmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgICAgIHJldHVybiBwYXJhbXM7XG4gICAgfVxuICAgIGdldCBldmVudFRhcmdldE5hbWUoKSB7XG4gICAgICAgIHJldHVybiBzdHJpbmdpZnlFdmVudFRhcmdldCh0aGlzLmV2ZW50VGFyZ2V0KTtcbiAgICB9XG4gICAgZ2V0IGtleU1hcHBpbmdzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY2hlbWEua2V5TWFwcGluZ3M7XG4gICAgfVxuICAgIGtleUZpbHRlckRpc3NhdGlzZmllZChldmVudCwgZmlsdGVycykge1xuICAgICAgICBjb25zdCBbbWV0YSwgY3RybCwgYWx0LCBzaGlmdF0gPSBhbGxNb2RpZmllcnMubWFwKChtb2RpZmllcikgPT4gZmlsdGVycy5pbmNsdWRlcyhtb2RpZmllcikpO1xuICAgICAgICByZXR1cm4gZXZlbnQubWV0YUtleSAhPT0gbWV0YSB8fCBldmVudC5jdHJsS2V5ICE9PSBjdHJsIHx8IGV2ZW50LmFsdEtleSAhPT0gYWx0IHx8IGV2ZW50LnNoaWZ0S2V5ICE9PSBzaGlmdDtcbiAgICB9XG59XG5jb25zdCBkZWZhdWx0RXZlbnROYW1lcyA9IHtcbiAgICBhOiAoKSA9PiBcImNsaWNrXCIsXG4gICAgYnV0dG9uOiAoKSA9PiBcImNsaWNrXCIsXG4gICAgZm9ybTogKCkgPT4gXCJzdWJtaXRcIixcbiAgICBkZXRhaWxzOiAoKSA9PiBcInRvZ2dsZVwiLFxuICAgIGlucHV0OiAoZSkgPT4gKGUuZ2V0QXR0cmlidXRlKFwidHlwZVwiKSA9PSBcInN1Ym1pdFwiID8gXCJjbGlja1wiIDogXCJpbnB1dFwiKSxcbiAgICBzZWxlY3Q6ICgpID0+IFwiY2hhbmdlXCIsXG4gICAgdGV4dGFyZWE6ICgpID0+IFwiaW5wdXRcIixcbn07XG5mdW5jdGlvbiBnZXREZWZhdWx0RXZlbnROYW1lRm9yRWxlbWVudChlbGVtZW50KSB7XG4gICAgY29uc3QgdGFnTmFtZSA9IGVsZW1lbnQudGFnTmFtZS50b0xvd2VyQ2FzZSgpO1xuICAgIGlmICh0YWdOYW1lIGluIGRlZmF1bHRFdmVudE5hbWVzKSB7XG4gICAgICAgIHJldHVybiBkZWZhdWx0RXZlbnROYW1lc1t0YWdOYW1lXShlbGVtZW50KTtcbiAgICB9XG59XG5mdW5jdGlvbiBlcnJvcihtZXNzYWdlKSB7XG4gICAgdGhyb3cgbmV3IEVycm9yKG1lc3NhZ2UpO1xufVxuZnVuY3Rpb24gdHlwZWNhc3QodmFsdWUpIHtcbiAgICB0cnkge1xuICAgICAgICByZXR1cm4gSlNPTi5wYXJzZSh2YWx1ZSk7XG4gICAgfVxuICAgIGNhdGNoIChvX08pIHtcbiAgICAgICAgcmV0dXJuIHZhbHVlO1xuICAgIH1cbn1cblxuY2xhc3MgQmluZGluZyB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgYWN0aW9uKSB7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMuYWN0aW9uID0gYWN0aW9uO1xuICAgIH1cbiAgICBnZXQgaW5kZXgoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFjdGlvbi5pbmRleDtcbiAgICB9XG4gICAgZ2V0IGV2ZW50VGFyZ2V0KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uZXZlbnRUYXJnZXQ7XG4gICAgfVxuICAgIGdldCBldmVudE9wdGlvbnMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFjdGlvbi5ldmVudE9wdGlvbnM7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGhhbmRsZUV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IGFjdGlvbkV2ZW50ID0gdGhpcy5wcmVwYXJlQWN0aW9uRXZlbnQoZXZlbnQpO1xuICAgICAgICBpZiAodGhpcy53aWxsQmVJbnZva2VkQnlFdmVudChldmVudCkgJiYgdGhpcy5hcHBseUV2ZW50TW9kaWZpZXJzKGFjdGlvbkV2ZW50KSkge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VXaXRoRXZlbnQoYWN0aW9uRXZlbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBldmVudE5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFjdGlvbi5ldmVudE5hbWU7XG4gICAgfVxuICAgIGdldCBtZXRob2QoKSB7XG4gICAgICAgIGNvbnN0IG1ldGhvZCA9IHRoaXMuY29udHJvbGxlclt0aGlzLm1ldGhvZE5hbWVdO1xuICAgICAgICBpZiAodHlwZW9mIG1ldGhvZCA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgICAgIHJldHVybiBtZXRob2Q7XG4gICAgICAgIH1cbiAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBBY3Rpb24gXCIke3RoaXMuYWN0aW9ufVwiIHJlZmVyZW5jZXMgdW5kZWZpbmVkIG1ldGhvZCBcIiR7dGhpcy5tZXRob2ROYW1lfVwiYCk7XG4gICAgfVxuICAgIGFwcGx5RXZlbnRNb2RpZmllcnMoZXZlbnQpIHtcbiAgICAgICAgY29uc3QgeyBlbGVtZW50IH0gPSB0aGlzLmFjdGlvbjtcbiAgICAgICAgY29uc3QgeyBhY3Rpb25EZXNjcmlwdG9yRmlsdGVycyB9ID0gdGhpcy5jb250ZXh0LmFwcGxpY2F0aW9uO1xuICAgICAgICBjb25zdCB7IGNvbnRyb2xsZXIgfSA9IHRoaXMuY29udGV4dDtcbiAgICAgICAgbGV0IHBhc3NlcyA9IHRydWU7XG4gICAgICAgIGZvciAoY29uc3QgW25hbWUsIHZhbHVlXSBvZiBPYmplY3QuZW50cmllcyh0aGlzLmV2ZW50T3B0aW9ucykpIHtcbiAgICAgICAgICAgIGlmIChuYW1lIGluIGFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgZmlsdGVyID0gYWN0aW9uRGVzY3JpcHRvckZpbHRlcnNbbmFtZV07XG4gICAgICAgICAgICAgICAgcGFzc2VzID0gcGFzc2VzICYmIGZpbHRlcih7IG5hbWUsIHZhbHVlLCBldmVudCwgZWxlbWVudCwgY29udHJvbGxlciB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgIGNvbnRpbnVlO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgICAgIHJldHVybiBwYXNzZXM7XG4gICAgfVxuICAgIHByZXBhcmVBY3Rpb25FdmVudChldmVudCkge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihldmVudCwgeyBwYXJhbXM6IHRoaXMuYWN0aW9uLnBhcmFtcyB9KTtcbiAgICB9XG4gICAgaW52b2tlV2l0aEV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IHsgdGFyZ2V0LCBjdXJyZW50VGFyZ2V0IH0gPSBldmVudDtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIHRoaXMubWV0aG9kLmNhbGwodGhpcy5jb250cm9sbGVyLCBldmVudCk7XG4gICAgICAgICAgICB0aGlzLmNvbnRleHQubG9nRGVidWdBY3Rpdml0eSh0aGlzLm1ldGhvZE5hbWUsIHsgZXZlbnQsIHRhcmdldCwgY3VycmVudFRhcmdldCwgYWN0aW9uOiB0aGlzLm1ldGhvZE5hbWUgfSk7XG4gICAgICAgIH1cbiAgICAgICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICBjb25zdCB7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXIsIGVsZW1lbnQsIGluZGV4IH0gPSB0aGlzO1xuICAgICAgICAgICAgY29uc3QgZGV0YWlsID0geyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50LCBpbmRleCwgZXZlbnQgfTtcbiAgICAgICAgICAgIHRoaXMuY29udGV4dC5oYW5kbGVFcnJvcihlcnJvciwgYGludm9raW5nIGFjdGlvbiBcIiR7dGhpcy5hY3Rpb259XCJgLCBkZXRhaWwpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHdpbGxCZUludm9rZWRCeUV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IGV2ZW50VGFyZ2V0ID0gZXZlbnQudGFyZ2V0O1xuICAgICAgICBpZiAoZXZlbnQgaW5zdGFuY2VvZiBLZXlib2FyZEV2ZW50ICYmIHRoaXMuYWN0aW9uLnNob3VsZElnbm9yZUtleWJvYXJkRXZlbnQoZXZlbnQpKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKGV2ZW50IGluc3RhbmNlb2YgTW91c2VFdmVudCAmJiB0aGlzLmFjdGlvbi5zaG91bGRJZ25vcmVNb3VzZUV2ZW50KGV2ZW50KSkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmICh0aGlzLmVsZW1lbnQgPT09IGV2ZW50VGFyZ2V0KSB7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIGlmIChldmVudFRhcmdldCBpbnN0YW5jZW9mIEVsZW1lbnQgJiYgdGhpcy5lbGVtZW50LmNvbnRhaW5zKGV2ZW50VGFyZ2V0KSkge1xuICAgICAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuY29udGFpbnNFbGVtZW50KGV2ZW50VGFyZ2V0KTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmNvbnRhaW5zRWxlbWVudCh0aGlzLmFjdGlvbi5lbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgY29udHJvbGxlcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5jb250cm9sbGVyO1xuICAgIH1cbiAgICBnZXQgbWV0aG9kTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYWN0aW9uLm1ldGhvZE5hbWU7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgc2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NvcGU7XG4gICAgfVxufVxuXG5jbGFzcyBFbGVtZW50T2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlckluaXQgPSB7IGF0dHJpYnV0ZXM6IHRydWUsIGNoaWxkTGlzdDogdHJ1ZSwgc3VidHJlZTogdHJ1ZSB9O1xuICAgICAgICB0aGlzLmVsZW1lbnQgPSBlbGVtZW50O1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLmVsZW1lbnRzID0gbmV3IFNldCgpO1xuICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIgPSBuZXcgTXV0YXRpb25PYnNlcnZlcigobXV0YXRpb25zKSA9PiB0aGlzLnByb2Nlc3NNdXRhdGlvbnMobXV0YXRpb25zKSk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5vYnNlcnZlKHRoaXMuZWxlbWVudCwgdGhpcy5tdXRhdGlvbk9ic2VydmVySW5pdCk7XG4gICAgICAgICAgICB0aGlzLnJlZnJlc2goKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgY2FsbGJhY2soKTtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5vYnNlcnZlKHRoaXMuZWxlbWVudCwgdGhpcy5tdXRhdGlvbk9ic2VydmVySW5pdCk7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci50YWtlUmVjb3JkcygpO1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoZXMgPSBuZXcgU2V0KHRoaXMubWF0Y2hFbGVtZW50c0luVHJlZSgpKTtcbiAgICAgICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiBBcnJheS5mcm9tKHRoaXMuZWxlbWVudHMpKSB7XG4gICAgICAgICAgICAgICAgaWYgKCFtYXRjaGVzLmhhcyhlbGVtZW50KSkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLnJlbW92ZUVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZm9yIChjb25zdCBlbGVtZW50IG9mIEFycmF5LmZyb20obWF0Y2hlcykpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmFkZEVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc011dGF0aW9ucyhtdXRhdGlvbnMpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgZm9yIChjb25zdCBtdXRhdGlvbiBvZiBtdXRhdGlvbnMpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnByb2Nlc3NNdXRhdGlvbihtdXRhdGlvbik7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc011dGF0aW9uKG11dGF0aW9uKSB7XG4gICAgICAgIGlmIChtdXRhdGlvbi50eXBlID09IFwiYXR0cmlidXRlc1wiKSB7XG4gICAgICAgICAgICB0aGlzLnByb2Nlc3NBdHRyaWJ1dGVDaGFuZ2UobXV0YXRpb24udGFyZ2V0LCBtdXRhdGlvbi5hdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIGlmIChtdXRhdGlvbi50eXBlID09IFwiY2hpbGRMaXN0XCIpIHtcbiAgICAgICAgICAgIHRoaXMucHJvY2Vzc1JlbW92ZWROb2RlcyhtdXRhdGlvbi5yZW1vdmVkTm9kZXMpO1xuICAgICAgICAgICAgdGhpcy5wcm9jZXNzQWRkZWROb2RlcyhtdXRhdGlvbi5hZGRlZE5vZGVzKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzQXR0cmlidXRlQ2hhbmdlKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZWxlbWVudHMuaGFzKGVsZW1lbnQpKSB7XG4gICAgICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5lbGVtZW50QXR0cmlidXRlQ2hhbmdlZCAmJiB0aGlzLm1hdGNoRWxlbWVudChlbGVtZW50KSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZUNoYW5nZWQoZWxlbWVudCwgYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICB0aGlzLnJlbW92ZUVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAodGhpcy5tYXRjaEVsZW1lbnQoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuYWRkRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzUmVtb3ZlZE5vZGVzKG5vZGVzKSB7XG4gICAgICAgIGZvciAoY29uc3Qgbm9kZSBvZiBBcnJheS5mcm9tKG5vZGVzKSkge1xuICAgICAgICAgICAgY29uc3QgZWxlbWVudCA9IHRoaXMuZWxlbWVudEZyb21Ob2RlKG5vZGUpO1xuICAgICAgICAgICAgaWYgKGVsZW1lbnQpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnByb2Nlc3NUcmVlKGVsZW1lbnQsIHRoaXMucmVtb3ZlRWxlbWVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc0FkZGVkTm9kZXMobm9kZXMpIHtcbiAgICAgICAgZm9yIChjb25zdCBub2RlIG9mIEFycmF5LmZyb20obm9kZXMpKSB7XG4gICAgICAgICAgICBjb25zdCBlbGVtZW50ID0gdGhpcy5lbGVtZW50RnJvbU5vZGUobm9kZSk7XG4gICAgICAgICAgICBpZiAoZWxlbWVudCAmJiB0aGlzLmVsZW1lbnRJc0FjdGl2ZShlbGVtZW50KSkge1xuICAgICAgICAgICAgICAgIHRoaXMucHJvY2Vzc1RyZWUoZWxlbWVudCwgdGhpcy5hZGRFbGVtZW50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBtYXRjaEVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICByZXR1cm4gdGhpcy5kZWxlZ2F0ZS5tYXRjaEVsZW1lbnQoZWxlbWVudCk7XG4gICAgfVxuICAgIG1hdGNoRWxlbWVudHNJblRyZWUodHJlZSA9IHRoaXMuZWxlbWVudCkge1xuICAgICAgICByZXR1cm4gdGhpcy5kZWxlZ2F0ZS5tYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUpO1xuICAgIH1cbiAgICBwcm9jZXNzVHJlZSh0cmVlLCBwcm9jZXNzb3IpIHtcbiAgICAgICAgZm9yIChjb25zdCBlbGVtZW50IG9mIHRoaXMubWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKSkge1xuICAgICAgICAgICAgcHJvY2Vzc29yLmNhbGwodGhpcywgZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudEZyb21Ob2RlKG5vZGUpIHtcbiAgICAgICAgaWYgKG5vZGUubm9kZVR5cGUgPT0gTm9kZS5FTEVNRU5UX05PREUpIHtcbiAgICAgICAgICAgIHJldHVybiBub2RlO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRJc0FjdGl2ZShlbGVtZW50KSB7XG4gICAgICAgIGlmIChlbGVtZW50LmlzQ29ubmVjdGVkICE9IHRoaXMuZWxlbWVudC5pc0Nvbm5lY3RlZCkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5jb250YWlucyhlbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBhZGRFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgaWYgKCF0aGlzLmVsZW1lbnRzLmhhcyhlbGVtZW50KSkge1xuICAgICAgICAgICAgaWYgKHRoaXMuZWxlbWVudElzQWN0aXZlKGVsZW1lbnQpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5lbGVtZW50cy5hZGQoZWxlbWVudCk7XG4gICAgICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50TWF0Y2hlZChlbGVtZW50KTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVtb3ZlRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGlmICh0aGlzLmVsZW1lbnRzLmhhcyhlbGVtZW50KSkge1xuICAgICAgICAgICAgdGhpcy5lbGVtZW50cy5kZWxldGUoZWxlbWVudCk7XG4gICAgICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5lbGVtZW50VW5tYXRjaGVkKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50VW5tYXRjaGVkKGVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxufVxuXG5jbGFzcyBBdHRyaWJ1dGVPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVOYW1lID0gYXR0cmlidXRlTmFtZTtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlciA9IG5ldyBFbGVtZW50T2JzZXJ2ZXIoZWxlbWVudCwgdGhpcyk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IHNlbGVjdG9yKCkge1xuICAgICAgICByZXR1cm4gYFske3RoaXMuYXR0cmlidXRlTmFtZX1dYDtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHBhdXNlKGNhbGxiYWNrKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnBhdXNlKGNhbGxiYWNrKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RhcnRlZDtcbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnQuaGFzQXR0cmlidXRlKHRoaXMuYXR0cmlidXRlTmFtZSk7XG4gICAgfVxuICAgIG1hdGNoRWxlbWVudHNJblRyZWUodHJlZSkge1xuICAgICAgICBjb25zdCBtYXRjaCA9IHRoaXMubWF0Y2hFbGVtZW50KHRyZWUpID8gW3RyZWVdIDogW107XG4gICAgICAgIGNvbnN0IG1hdGNoZXMgPSBBcnJheS5mcm9tKHRyZWUucXVlcnlTZWxlY3RvckFsbCh0aGlzLnNlbGVjdG9yKSk7XG4gICAgICAgIHJldHVybiBtYXRjaC5jb25jYXQobWF0Y2hlcyk7XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkKGVsZW1lbnQpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWRBdHRyaWJ1dGUpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWRBdHRyaWJ1dGUoZWxlbWVudCwgdGhpcy5hdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkKGVsZW1lbnQpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZEF0dHJpYnV0ZSkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50VW5tYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQsIHRoaXMuYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudEF0dHJpYnV0ZUNoYW5nZWQoZWxlbWVudCwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5lbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkICYmIHRoaXMuYXR0cmlidXRlTmFtZSA9PSBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRBdHRyaWJ1dGVWYWx1ZUNoYW5nZWQoZWxlbWVudCwgYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmZ1bmN0aW9uIGFkZChtYXAsIGtleSwgdmFsdWUpIHtcbiAgICBmZXRjaChtYXAsIGtleSkuYWRkKHZhbHVlKTtcbn1cbmZ1bmN0aW9uIGRlbChtYXAsIGtleSwgdmFsdWUpIHtcbiAgICBmZXRjaChtYXAsIGtleSkuZGVsZXRlKHZhbHVlKTtcbiAgICBwcnVuZShtYXAsIGtleSk7XG59XG5mdW5jdGlvbiBmZXRjaChtYXAsIGtleSkge1xuICAgIGxldCB2YWx1ZXMgPSBtYXAuZ2V0KGtleSk7XG4gICAgaWYgKCF2YWx1ZXMpIHtcbiAgICAgICAgdmFsdWVzID0gbmV3IFNldCgpO1xuICAgICAgICBtYXAuc2V0KGtleSwgdmFsdWVzKTtcbiAgICB9XG4gICAgcmV0dXJuIHZhbHVlcztcbn1cbmZ1bmN0aW9uIHBydW5lKG1hcCwga2V5KSB7XG4gICAgY29uc3QgdmFsdWVzID0gbWFwLmdldChrZXkpO1xuICAgIGlmICh2YWx1ZXMgIT0gbnVsbCAmJiB2YWx1ZXMuc2l6ZSA9PSAwKSB7XG4gICAgICAgIG1hcC5kZWxldGUoa2V5KTtcbiAgICB9XG59XG5cbmNsYXNzIE11bHRpbWFwIHtcbiAgICBjb25zdHJ1Y3RvcigpIHtcbiAgICAgICAgdGhpcy52YWx1ZXNCeUtleSA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgZ2V0IGtleXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkua2V5cygpKTtcbiAgICB9XG4gICAgZ2V0IHZhbHVlcygpIHtcbiAgICAgICAgY29uc3Qgc2V0cyA9IEFycmF5LmZyb20odGhpcy52YWx1ZXNCeUtleS52YWx1ZXMoKSk7XG4gICAgICAgIHJldHVybiBzZXRzLnJlZHVjZSgodmFsdWVzLCBzZXQpID0+IHZhbHVlcy5jb25jYXQoQXJyYXkuZnJvbShzZXQpKSwgW10pO1xuICAgIH1cbiAgICBnZXQgc2l6ZSgpIHtcbiAgICAgICAgY29uc3Qgc2V0cyA9IEFycmF5LmZyb20odGhpcy52YWx1ZXNCeUtleS52YWx1ZXMoKSk7XG4gICAgICAgIHJldHVybiBzZXRzLnJlZHVjZSgoc2l6ZSwgc2V0KSA9PiBzaXplICsgc2V0LnNpemUsIDApO1xuICAgIH1cbiAgICBhZGQoa2V5LCB2YWx1ZSkge1xuICAgICAgICBhZGQodGhpcy52YWx1ZXNCeUtleSwga2V5LCB2YWx1ZSk7XG4gICAgfVxuICAgIGRlbGV0ZShrZXksIHZhbHVlKSB7XG4gICAgICAgIGRlbCh0aGlzLnZhbHVlc0J5S2V5LCBrZXksIHZhbHVlKTtcbiAgICB9XG4gICAgaGFzKGtleSwgdmFsdWUpIHtcbiAgICAgICAgY29uc3QgdmFsdWVzID0gdGhpcy52YWx1ZXNCeUtleS5nZXQoa2V5KTtcbiAgICAgICAgcmV0dXJuIHZhbHVlcyAhPSBudWxsICYmIHZhbHVlcy5oYXModmFsdWUpO1xuICAgIH1cbiAgICBoYXNLZXkoa2V5KSB7XG4gICAgICAgIHJldHVybiB0aGlzLnZhbHVlc0J5S2V5LmhhcyhrZXkpO1xuICAgIH1cbiAgICBoYXNWYWx1ZSh2YWx1ZSkge1xuICAgICAgICBjb25zdCBzZXRzID0gQXJyYXkuZnJvbSh0aGlzLnZhbHVlc0J5S2V5LnZhbHVlcygpKTtcbiAgICAgICAgcmV0dXJuIHNldHMuc29tZSgoc2V0KSA9PiBzZXQuaGFzKHZhbHVlKSk7XG4gICAgfVxuICAgIGdldFZhbHVlc0ZvcktleShrZXkpIHtcbiAgICAgICAgY29uc3QgdmFsdWVzID0gdGhpcy52YWx1ZXNCeUtleS5nZXQoa2V5KTtcbiAgICAgICAgcmV0dXJuIHZhbHVlcyA/IEFycmF5LmZyb20odmFsdWVzKSA6IFtdO1xuICAgIH1cbiAgICBnZXRLZXlzRm9yVmFsdWUodmFsdWUpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy52YWx1ZXNCeUtleSlcbiAgICAgICAgICAgIC5maWx0ZXIoKFtfa2V5LCB2YWx1ZXNdKSA9PiB2YWx1ZXMuaGFzKHZhbHVlKSlcbiAgICAgICAgICAgIC5tYXAoKFtrZXksIF92YWx1ZXNdKSA9PiBrZXkpO1xuICAgIH1cbn1cblxuY2xhc3MgSW5kZXhlZE11bHRpbWFwIGV4dGVuZHMgTXVsdGltYXAge1xuICAgIGNvbnN0cnVjdG9yKCkge1xuICAgICAgICBzdXBlcigpO1xuICAgICAgICB0aGlzLmtleXNCeVZhbHVlID0gbmV3IE1hcCgpO1xuICAgIH1cbiAgICBnZXQgdmFsdWVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmtleXNCeVZhbHVlLmtleXMoKSk7XG4gICAgfVxuICAgIGFkZChrZXksIHZhbHVlKSB7XG4gICAgICAgIHN1cGVyLmFkZChrZXksIHZhbHVlKTtcbiAgICAgICAgYWRkKHRoaXMua2V5c0J5VmFsdWUsIHZhbHVlLCBrZXkpO1xuICAgIH1cbiAgICBkZWxldGUoa2V5LCB2YWx1ZSkge1xuICAgICAgICBzdXBlci5kZWxldGUoa2V5LCB2YWx1ZSk7XG4gICAgICAgIGRlbCh0aGlzLmtleXNCeVZhbHVlLCB2YWx1ZSwga2V5KTtcbiAgICB9XG4gICAgaGFzVmFsdWUodmFsdWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMua2V5c0J5VmFsdWUuaGFzKHZhbHVlKTtcbiAgICB9XG4gICAgZ2V0S2V5c0ZvclZhbHVlKHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHNldCA9IHRoaXMua2V5c0J5VmFsdWUuZ2V0KHZhbHVlKTtcbiAgICAgICAgcmV0dXJuIHNldCA/IEFycmF5LmZyb20oc2V0KSA6IFtdO1xuICAgIH1cbn1cblxuY2xhc3MgU2VsZWN0b3JPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgc2VsZWN0b3IsIGRlbGVnYXRlLCBkZXRhaWxzKSB7XG4gICAgICAgIHRoaXMuX3NlbGVjdG9yID0gc2VsZWN0b3I7XG4gICAgICAgIHRoaXMuZGV0YWlscyA9IGRldGFpbHM7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyID0gbmV3IEVsZW1lbnRPYnNlcnZlcihlbGVtZW50LCB0aGlzKTtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLm1hdGNoZXNCeUVsZW1lbnQgPSBuZXcgTXVsdGltYXAoKTtcbiAgICB9XG4gICAgZ2V0IHN0YXJ0ZWQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnRPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBnZXQgc2VsZWN0b3IoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLl9zZWxlY3RvcjtcbiAgICB9XG4gICAgc2V0IHNlbGVjdG9yKHNlbGVjdG9yKSB7XG4gICAgICAgIHRoaXMuX3NlbGVjdG9yID0gc2VsZWN0b3I7XG4gICAgICAgIHRoaXMucmVmcmVzaCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgcGF1c2UoY2FsbGJhY2spIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIucGF1c2UoY2FsbGJhY2spO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnJlZnJlc2goKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnRPYnNlcnZlci5lbGVtZW50O1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCB7IHNlbGVjdG9yIH0gPSB0aGlzO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoZXMgPSBlbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpO1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuc2VsZWN0b3JNYXRjaEVsZW1lbnQpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gbWF0Y2hlcyAmJiB0aGlzLmRlbGVnYXRlLnNlbGVjdG9yTWF0Y2hFbGVtZW50KGVsZW1lbnQsIHRoaXMuZGV0YWlscyk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICByZXR1cm4gbWF0Y2hlcztcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBtYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUpIHtcbiAgICAgICAgY29uc3QgeyBzZWxlY3RvciB9ID0gdGhpcztcbiAgICAgICAgaWYgKHNlbGVjdG9yKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaCA9IHRoaXMubWF0Y2hFbGVtZW50KHRyZWUpID8gW3RyZWVdIDogW107XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gQXJyYXkuZnJvbSh0cmVlLnF1ZXJ5U2VsZWN0b3JBbGwoc2VsZWN0b3IpKS5maWx0ZXIoKG1hdGNoKSA9PiB0aGlzLm1hdGNoRWxlbWVudChtYXRjaCkpO1xuICAgICAgICAgICAgcmV0dXJuIG1hdGNoLmNvbmNhdChtYXRjaGVzKTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiBbXTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgdGhpcy5zZWxlY3Rvck1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCBzZWxlY3RvcnMgPSB0aGlzLm1hdGNoZXNCeUVsZW1lbnQuZ2V0S2V5c0ZvclZhbHVlKGVsZW1lbnQpO1xuICAgICAgICBmb3IgKGNvbnN0IHNlbGVjdG9yIG9mIHNlbGVjdG9ycykge1xuICAgICAgICAgICAgdGhpcy5zZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudEF0dHJpYnV0ZUNoYW5nZWQoZWxlbWVudCwgX2F0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3QgeyBzZWxlY3RvciB9ID0gdGhpcztcbiAgICAgICAgaWYgKHNlbGVjdG9yKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gdGhpcy5tYXRjaEVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVkQmVmb3JlID0gdGhpcy5tYXRjaGVzQnlFbGVtZW50LmhhcyhzZWxlY3RvciwgZWxlbWVudCk7XG4gICAgICAgICAgICBpZiAobWF0Y2hlcyAmJiAhbWF0Y2hlZEJlZm9yZSkge1xuICAgICAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGVsc2UgaWYgKCFtYXRjaGVzICYmIG1hdGNoZWRCZWZvcmUpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnNlbGVjdG9yVW5tYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBzZWxlY3Rvck1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpIHtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zZWxlY3Rvck1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IsIHRoaXMuZGV0YWlscyk7XG4gICAgICAgIHRoaXMubWF0Y2hlc0J5RWxlbWVudC5hZGQoc2VsZWN0b3IsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBzZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcikge1xuICAgICAgICB0aGlzLmRlbGVnYXRlLnNlbGVjdG9yVW5tYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yLCB0aGlzLmRldGFpbHMpO1xuICAgICAgICB0aGlzLm1hdGNoZXNCeUVsZW1lbnQuZGVsZXRlKHNlbGVjdG9yLCBlbGVtZW50KTtcbiAgICB9XG59XG5cbmNsYXNzIFN0cmluZ01hcE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmVsZW1lbnQgPSBlbGVtZW50O1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICB0aGlzLnN0cmluZ01hcCA9IG5ldyBNYXAoKTtcbiAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyID0gbmV3IE11dGF0aW9uT2JzZXJ2ZXIoKG11dGF0aW9ucykgPT4gdGhpcy5wcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IHRydWU7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIub2JzZXJ2ZSh0aGlzLmVsZW1lbnQsIHsgYXR0cmlidXRlczogdHJ1ZSwgYXR0cmlidXRlT2xkVmFsdWU6IHRydWUgfSk7XG4gICAgICAgICAgICB0aGlzLnJlZnJlc2goKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIudGFrZVJlY29yZHMoKTtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGF0dHJpYnV0ZU5hbWUgb2YgdGhpcy5rbm93bkF0dHJpYnV0ZU5hbWVzKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5yZWZyZXNoQXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUsIG51bGwpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbnMobXV0YXRpb25zKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGZvciAoY29uc3QgbXV0YXRpb24gb2YgbXV0YXRpb25zKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzTXV0YXRpb24obXV0YXRpb24pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbihtdXRhdGlvbikge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gbXV0YXRpb24uYXR0cmlidXRlTmFtZTtcbiAgICAgICAgaWYgKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMucmVmcmVzaEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lLCBtdXRhdGlvbi5vbGRWYWx1ZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVmcmVzaEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSkge1xuICAgICAgICBjb25zdCBrZXkgPSB0aGlzLmRlbGVnYXRlLmdldFN0cmluZ01hcEtleUZvckF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKGtleSAhPSBudWxsKSB7XG4gICAgICAgICAgICBpZiAoIXRoaXMuc3RyaW5nTWFwLmhhcyhhdHRyaWJ1dGVOYW1lKSkge1xuICAgICAgICAgICAgICAgIHRoaXMuc3RyaW5nTWFwS2V5QWRkZWQoa2V5LCBhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gdGhpcy5lbGVtZW50LmdldEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgICAgIGlmICh0aGlzLnN0cmluZ01hcC5nZXQoYXR0cmlidXRlTmFtZSkgIT0gdmFsdWUpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwga2V5LCBvbGRWYWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAodmFsdWUgPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IG9sZFZhbHVlID0gdGhpcy5zdHJpbmdNYXAuZ2V0KGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICAgICAgICAgIHRoaXMuc3RyaW5nTWFwLmRlbGV0ZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgICAgICAgICBpZiAob2xkVmFsdWUpXG4gICAgICAgICAgICAgICAgICAgIHRoaXMuc3RyaW5nTWFwS2V5UmVtb3ZlZChrZXksIGF0dHJpYnV0ZU5hbWUsIG9sZFZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgIHRoaXMuc3RyaW5nTWFwLnNldChhdHRyaWJ1dGVOYW1lLCB2YWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RyaW5nTWFwS2V5QWRkZWQoa2V5LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLnN0cmluZ01hcEtleUFkZGVkKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLnN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RyaW5nTWFwVmFsdWVDaGFuZ2VkKHZhbHVlLCBrZXksIG9sZFZhbHVlKSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLnN0cmluZ01hcFZhbHVlQ2hhbmdlZCkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBWYWx1ZUNoYW5nZWQodmFsdWUsIGtleSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlSZW1vdmVkKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLnN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGtub3duQXR0cmlidXRlTmFtZXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKG5ldyBTZXQodGhpcy5jdXJyZW50QXR0cmlidXRlTmFtZXMuY29uY2F0KHRoaXMucmVjb3JkZWRBdHRyaWJ1dGVOYW1lcykpKTtcbiAgICB9XG4gICAgZ2V0IGN1cnJlbnRBdHRyaWJ1dGVOYW1lcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5lbGVtZW50LmF0dHJpYnV0ZXMpLm1hcCgoYXR0cmlidXRlKSA9PiBhdHRyaWJ1dGUubmFtZSk7XG4gICAgfVxuICAgIGdldCByZWNvcmRlZEF0dHJpYnV0ZU5hbWVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnN0cmluZ01hcC5rZXlzKCkpO1xuICAgIH1cbn1cblxuY2xhc3MgVG9rZW5MaXN0T2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIgPSBuZXcgQXR0cmlidXRlT2JzZXJ2ZXIoZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgdGhpcyk7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy50b2tlbnNCeUVsZW1lbnQgPSBuZXcgTXVsdGltYXAoKTtcbiAgICB9XG4gICAgZ2V0IHN0YXJ0ZWQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLnN0YXJ0ZWQ7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHBhdXNlKGNhbGxiYWNrKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIucGF1c2UoY2FsbGJhY2spO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgYXR0cmlidXRlTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIuYXR0cmlidXRlTmFtZTtcbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWRBdHRyaWJ1dGUoZWxlbWVudCkge1xuICAgICAgICB0aGlzLnRva2Vuc01hdGNoZWQodGhpcy5yZWFkVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KSk7XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVWYWx1ZUNoYW5nZWQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCBbdW5tYXRjaGVkVG9rZW5zLCBtYXRjaGVkVG9rZW5zXSA9IHRoaXMucmVmcmVzaFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgIHRoaXMudG9rZW5zVW5tYXRjaGVkKHVubWF0Y2hlZFRva2Vucyk7XG4gICAgICAgIHRoaXMudG9rZW5zTWF0Y2hlZChtYXRjaGVkVG9rZW5zKTtcbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZEF0dHJpYnV0ZShlbGVtZW50KSB7XG4gICAgICAgIHRoaXMudG9rZW5zVW5tYXRjaGVkKHRoaXMudG9rZW5zQnlFbGVtZW50LmdldFZhbHVlc0ZvcktleShlbGVtZW50KSk7XG4gICAgfVxuICAgIHRva2Vuc01hdGNoZWQodG9rZW5zKSB7XG4gICAgICAgIHRva2Vucy5mb3JFYWNoKCh0b2tlbikgPT4gdGhpcy50b2tlbk1hdGNoZWQodG9rZW4pKTtcbiAgICB9XG4gICAgdG9rZW5zVW5tYXRjaGVkKHRva2Vucykge1xuICAgICAgICB0b2tlbnMuZm9yRWFjaCgodG9rZW4pID0+IHRoaXMudG9rZW5Vbm1hdGNoZWQodG9rZW4pKTtcbiAgICB9XG4gICAgdG9rZW5NYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUudG9rZW5NYXRjaGVkKHRva2VuKTtcbiAgICAgICAgdGhpcy50b2tlbnNCeUVsZW1lbnQuYWRkKHRva2VuLmVsZW1lbnQsIHRva2VuKTtcbiAgICB9XG4gICAgdG9rZW5Vbm1hdGNoZWQodG9rZW4pIHtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZS50b2tlblVubWF0Y2hlZCh0b2tlbik7XG4gICAgICAgIHRoaXMudG9rZW5zQnlFbGVtZW50LmRlbGV0ZSh0b2tlbi5lbGVtZW50LCB0b2tlbik7XG4gICAgfVxuICAgIHJlZnJlc2hUb2tlbnNGb3JFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgcHJldmlvdXNUb2tlbnMgPSB0aGlzLnRva2Vuc0J5RWxlbWVudC5nZXRWYWx1ZXNGb3JLZXkoZWxlbWVudCk7XG4gICAgICAgIGNvbnN0IGN1cnJlbnRUb2tlbnMgPSB0aGlzLnJlYWRUb2tlbnNGb3JFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICBjb25zdCBmaXJzdERpZmZlcmluZ0luZGV4ID0gemlwKHByZXZpb3VzVG9rZW5zLCBjdXJyZW50VG9rZW5zKS5maW5kSW5kZXgoKFtwcmV2aW91c1Rva2VuLCBjdXJyZW50VG9rZW5dKSA9PiAhdG9rZW5zQXJlRXF1YWwocHJldmlvdXNUb2tlbiwgY3VycmVudFRva2VuKSk7XG4gICAgICAgIGlmIChmaXJzdERpZmZlcmluZ0luZGV4ID09IC0xKSB7XG4gICAgICAgICAgICByZXR1cm4gW1tdLCBbXV07XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gW3ByZXZpb3VzVG9rZW5zLnNsaWNlKGZpcnN0RGlmZmVyaW5nSW5kZXgpLCBjdXJyZW50VG9rZW5zLnNsaWNlKGZpcnN0RGlmZmVyaW5nSW5kZXgpXTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWFkVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLmF0dHJpYnV0ZU5hbWU7XG4gICAgICAgIGNvbnN0IHRva2VuU3RyaW5nID0gZWxlbWVudC5nZXRBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSkgfHwgXCJcIjtcbiAgICAgICAgcmV0dXJuIHBhcnNlVG9rZW5TdHJpbmcodG9rZW5TdHJpbmcsIGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbn1cbmZ1bmN0aW9uIHBhcnNlVG9rZW5TdHJpbmcodG9rZW5TdHJpbmcsIGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICByZXR1cm4gdG9rZW5TdHJpbmdcbiAgICAgICAgLnRyaW0oKVxuICAgICAgICAuc3BsaXQoL1xccysvKVxuICAgICAgICAuZmlsdGVyKChjb250ZW50KSA9PiBjb250ZW50Lmxlbmd0aClcbiAgICAgICAgLm1hcCgoY29udGVudCwgaW5kZXgpID0+ICh7IGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIGNvbnRlbnQsIGluZGV4IH0pKTtcbn1cbmZ1bmN0aW9uIHppcChsZWZ0LCByaWdodCkge1xuICAgIGNvbnN0IGxlbmd0aCA9IE1hdGgubWF4KGxlZnQubGVuZ3RoLCByaWdodC5sZW5ndGgpO1xuICAgIHJldHVybiBBcnJheS5mcm9tKHsgbGVuZ3RoIH0sIChfLCBpbmRleCkgPT4gW2xlZnRbaW5kZXhdLCByaWdodFtpbmRleF1dKTtcbn1cbmZ1bmN0aW9uIHRva2Vuc0FyZUVxdWFsKGxlZnQsIHJpZ2h0KSB7XG4gICAgcmV0dXJuIGxlZnQgJiYgcmlnaHQgJiYgbGVmdC5pbmRleCA9PSByaWdodC5pbmRleCAmJiBsZWZ0LmNvbnRlbnQgPT0gcmlnaHQuY29udGVudDtcbn1cblxuY2xhc3MgVmFsdWVMaXN0T2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIgPSBuZXcgVG9rZW5MaXN0T2JzZXJ2ZXIoZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgdGhpcyk7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5wYXJzZVJlc3VsdHNCeVRva2VuID0gbmV3IFdlYWtNYXAoKTtcbiAgICAgICAgdGhpcy52YWx1ZXNCeVRva2VuQnlFbGVtZW50ID0gbmV3IFdlYWtNYXAoKTtcbiAgICB9XG4gICAgZ2V0IHN0YXJ0ZWQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnN0YXJ0ZWQ7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnJlZnJlc2goKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnRva2VuTGlzdE9ic2VydmVyLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBhdHRyaWJ1dGVOYW1lKCkge1xuICAgICAgICByZXR1cm4gdGhpcy50b2tlbkxpc3RPYnNlcnZlci5hdHRyaWJ1dGVOYW1lO1xuICAgIH1cbiAgICB0b2tlbk1hdGNoZWQodG9rZW4pIHtcbiAgICAgICAgY29uc3QgeyBlbGVtZW50IH0gPSB0b2tlbjtcbiAgICAgICAgY29uc3QgeyB2YWx1ZSB9ID0gdGhpcy5mZXRjaFBhcnNlUmVzdWx0Rm9yVG9rZW4odG9rZW4pO1xuICAgICAgICBpZiAodmFsdWUpIHtcbiAgICAgICAgICAgIHRoaXMuZmV0Y2hWYWx1ZXNCeVRva2VuRm9yRWxlbWVudChlbGVtZW50KS5zZXQodG9rZW4sIHZhbHVlKTtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWRWYWx1ZShlbGVtZW50LCB2YWx1ZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgdG9rZW5Vbm1hdGNoZWQodG9rZW4pIHtcbiAgICAgICAgY29uc3QgeyBlbGVtZW50IH0gPSB0b2tlbjtcbiAgICAgICAgY29uc3QgeyB2YWx1ZSB9ID0gdGhpcy5mZXRjaFBhcnNlUmVzdWx0Rm9yVG9rZW4odG9rZW4pO1xuICAgICAgICBpZiAodmFsdWUpIHtcbiAgICAgICAgICAgIHRoaXMuZmV0Y2hWYWx1ZXNCeVRva2VuRm9yRWxlbWVudChlbGVtZW50KS5kZWxldGUodG9rZW4pO1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50VW5tYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGZldGNoUGFyc2VSZXN1bHRGb3JUb2tlbih0b2tlbikge1xuICAgICAgICBsZXQgcGFyc2VSZXN1bHQgPSB0aGlzLnBhcnNlUmVzdWx0c0J5VG9rZW4uZ2V0KHRva2VuKTtcbiAgICAgICAgaWYgKCFwYXJzZVJlc3VsdCkge1xuICAgICAgICAgICAgcGFyc2VSZXN1bHQgPSB0aGlzLnBhcnNlVG9rZW4odG9rZW4pO1xuICAgICAgICAgICAgdGhpcy5wYXJzZVJlc3VsdHNCeVRva2VuLnNldCh0b2tlbiwgcGFyc2VSZXN1bHQpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBwYXJzZVJlc3VsdDtcbiAgICB9XG4gICAgZmV0Y2hWYWx1ZXNCeVRva2VuRm9yRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGxldCB2YWx1ZXNCeVRva2VuID0gdGhpcy52YWx1ZXNCeVRva2VuQnlFbGVtZW50LmdldChlbGVtZW50KTtcbiAgICAgICAgaWYgKCF2YWx1ZXNCeVRva2VuKSB7XG4gICAgICAgICAgICB2YWx1ZXNCeVRva2VuID0gbmV3IE1hcCgpO1xuICAgICAgICAgICAgdGhpcy52YWx1ZXNCeVRva2VuQnlFbGVtZW50LnNldChlbGVtZW50LCB2YWx1ZXNCeVRva2VuKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gdmFsdWVzQnlUb2tlbjtcbiAgICB9XG4gICAgcGFyc2VUb2tlbih0b2tlbikge1xuICAgICAgICB0cnkge1xuICAgICAgICAgICAgY29uc3QgdmFsdWUgPSB0aGlzLmRlbGVnYXRlLnBhcnNlVmFsdWVGb3JUb2tlbih0b2tlbik7XG4gICAgICAgICAgICByZXR1cm4geyB2YWx1ZSB9O1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgcmV0dXJuIHsgZXJyb3IgfTtcbiAgICAgICAgfVxuICAgIH1cbn1cblxuY2xhc3MgQmluZGluZ09ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3Rvcihjb250ZXh0LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMuYmluZGluZ3NCeUFjdGlvbiA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy52YWx1ZUxpc3RPYnNlcnZlcikge1xuICAgICAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlciA9IG5ldyBWYWx1ZUxpc3RPYnNlcnZlcih0aGlzLmVsZW1lbnQsIHRoaXMuYWN0aW9uQXR0cmlidXRlLCB0aGlzKTtcbiAgICAgICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy52YWx1ZUxpc3RPYnNlcnZlcikge1xuICAgICAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgICAgICBkZWxldGUgdGhpcy52YWx1ZUxpc3RPYnNlcnZlcjtcbiAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdEFsbEFjdGlvbnMoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgYWN0aW9uQXR0cmlidXRlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY2hlbWEuYWN0aW9uQXR0cmlidXRlO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGJpbmRpbmdzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmJpbmRpbmdzQnlBY3Rpb24udmFsdWVzKCkpO1xuICAgIH1cbiAgICBjb25uZWN0QWN0aW9uKGFjdGlvbikge1xuICAgICAgICBjb25zdCBiaW5kaW5nID0gbmV3IEJpbmRpbmcodGhpcy5jb250ZXh0LCBhY3Rpb24pO1xuICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uc2V0KGFjdGlvbiwgYmluZGluZyk7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUuYmluZGluZ0Nvbm5lY3RlZChiaW5kaW5nKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdEFjdGlvbihhY3Rpb24pIHtcbiAgICAgICAgY29uc3QgYmluZGluZyA9IHRoaXMuYmluZGluZ3NCeUFjdGlvbi5nZXQoYWN0aW9uKTtcbiAgICAgICAgaWYgKGJpbmRpbmcpIHtcbiAgICAgICAgICAgIHRoaXMuYmluZGluZ3NCeUFjdGlvbi5kZWxldGUoYWN0aW9uKTtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuYmluZGluZ0Rpc2Nvbm5lY3RlZChiaW5kaW5nKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0QWxsQWN0aW9ucygpIHtcbiAgICAgICAgdGhpcy5iaW5kaW5ncy5mb3JFYWNoKChiaW5kaW5nKSA9PiB0aGlzLmRlbGVnYXRlLmJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZywgdHJ1ZSkpO1xuICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uY2xlYXIoKTtcbiAgICB9XG4gICAgcGFyc2VWYWx1ZUZvclRva2VuKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IGFjdGlvbiA9IEFjdGlvbi5mb3JUb2tlbih0b2tlbiwgdGhpcy5zY2hlbWEpO1xuICAgICAgICBpZiAoYWN0aW9uLmlkZW50aWZpZXIgPT0gdGhpcy5pZGVudGlmaWVyKSB7XG4gICAgICAgICAgICByZXR1cm4gYWN0aW9uO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkVmFsdWUoZWxlbWVudCwgYWN0aW9uKSB7XG4gICAgICAgIHRoaXMuY29ubmVjdEFjdGlvbihhY3Rpb24pO1xuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkVmFsdWUoZWxlbWVudCwgYWN0aW9uKSB7XG4gICAgICAgIHRoaXMuZGlzY29ubmVjdEFjdGlvbihhY3Rpb24pO1xuICAgIH1cbn1cblxuY2xhc3MgVmFsdWVPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgcmVjZWl2ZXIpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICAgICAgdGhpcy5yZWNlaXZlciA9IHJlY2VpdmVyO1xuICAgICAgICB0aGlzLnN0cmluZ01hcE9ic2VydmVyID0gbmV3IFN0cmluZ01hcE9ic2VydmVyKHRoaXMuZWxlbWVudCwgdGhpcyk7XG4gICAgICAgIHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwID0gdGhpcy5jb250cm9sbGVyLnZhbHVlRGVzY3JpcHRvck1hcDtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMuc3RyaW5nTWFwT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2tzRm9yRGVmYXVsdFZhbHVlcygpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnN0cmluZ01hcE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuY29udHJvbGxlcjtcbiAgICB9XG4gICAgZ2V0U3RyaW5nTWFwS2V5Rm9yQXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgaWYgKGF0dHJpYnV0ZU5hbWUgaW4gdGhpcy52YWx1ZURlc2NyaXB0b3JNYXApIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcFthdHRyaWJ1dGVOYW1lXS5uYW1lO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JNYXBbYXR0cmlidXRlTmFtZV07XG4gICAgICAgIGlmICghdGhpcy5oYXNWYWx1ZShrZXkpKSB7XG4gICAgICAgICAgICB0aGlzLmludm9rZUNoYW5nZWRDYWxsYmFjayhrZXksIGRlc2NyaXB0b3Iud3JpdGVyKHRoaXMucmVjZWl2ZXJba2V5XSksIGRlc2NyaXB0b3Iud3JpdGVyKGRlc2NyaXB0b3IuZGVmYXVsdFZhbHVlKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RyaW5nTWFwVmFsdWVDaGFuZ2VkKHZhbHVlLCBuYW1lLCBvbGRWYWx1ZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JOYW1lTWFwW25hbWVdO1xuICAgICAgICBpZiAodmFsdWUgPT09IG51bGwpXG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIGlmIChvbGRWYWx1ZSA9PT0gbnVsbCkge1xuICAgICAgICAgICAgb2xkVmFsdWUgPSBkZXNjcmlwdG9yLndyaXRlcihkZXNjcmlwdG9yLmRlZmF1bHRWYWx1ZSk7XG4gICAgICAgIH1cbiAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2sobmFtZSwgdmFsdWUsIG9sZFZhbHVlKTtcbiAgICB9XG4gICAgc3RyaW5nTWFwS2V5UmVtb3ZlZChrZXksIGF0dHJpYnV0ZU5hbWUsIG9sZFZhbHVlKSB7XG4gICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck5hbWVNYXBba2V5XTtcbiAgICAgICAgaWYgKHRoaXMuaGFzVmFsdWUoa2V5KSkge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2soa2V5LCBkZXNjcmlwdG9yLndyaXRlcih0aGlzLnJlY2VpdmVyW2tleV0pLCBvbGRWYWx1ZSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICB0aGlzLmludm9rZUNoYW5nZWRDYWxsYmFjayhrZXksIGRlc2NyaXB0b3Iud3JpdGVyKGRlc2NyaXB0b3IuZGVmYXVsdFZhbHVlKSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGludm9rZUNoYW5nZWRDYWxsYmFja3NGb3JEZWZhdWx0VmFsdWVzKCkge1xuICAgICAgICBmb3IgKGNvbnN0IHsga2V5LCBuYW1lLCBkZWZhdWx0VmFsdWUsIHdyaXRlciB9IG9mIHRoaXMudmFsdWVEZXNjcmlwdG9ycykge1xuICAgICAgICAgICAgaWYgKGRlZmF1bHRWYWx1ZSAhPSB1bmRlZmluZWQgJiYgIXRoaXMuY29udHJvbGxlci5kYXRhLmhhcyhrZXkpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2sobmFtZSwgd3JpdGVyKGRlZmF1bHRWYWx1ZSksIHVuZGVmaW5lZCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgaW52b2tlQ2hhbmdlZENhbGxiYWNrKG5hbWUsIHJhd1ZhbHVlLCByYXdPbGRWYWx1ZSkge1xuICAgICAgICBjb25zdCBjaGFuZ2VkTWV0aG9kTmFtZSA9IGAke25hbWV9Q2hhbmdlZGA7XG4gICAgICAgIGNvbnN0IGNoYW5nZWRNZXRob2QgPSB0aGlzLnJlY2VpdmVyW2NoYW5nZWRNZXRob2ROYW1lXTtcbiAgICAgICAgaWYgKHR5cGVvZiBjaGFuZ2VkTWV0aG9kID09IFwiZnVuY3Rpb25cIikge1xuICAgICAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFtuYW1lXTtcbiAgICAgICAgICAgIHRyeSB7XG4gICAgICAgICAgICAgICAgY29uc3QgdmFsdWUgPSBkZXNjcmlwdG9yLnJlYWRlcihyYXdWYWx1ZSk7XG4gICAgICAgICAgICAgICAgbGV0IG9sZFZhbHVlID0gcmF3T2xkVmFsdWU7XG4gICAgICAgICAgICAgICAgaWYgKHJhd09sZFZhbHVlKSB7XG4gICAgICAgICAgICAgICAgICAgIG9sZFZhbHVlID0gZGVzY3JpcHRvci5yZWFkZXIocmF3T2xkVmFsdWUpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBjaGFuZ2VkTWV0aG9kLmNhbGwodGhpcy5yZWNlaXZlciwgdmFsdWUsIG9sZFZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgICAgIGlmIChlcnJvciBpbnN0YW5jZW9mIFR5cGVFcnJvcikge1xuICAgICAgICAgICAgICAgICAgICBlcnJvci5tZXNzYWdlID0gYFN0aW11bHVzIFZhbHVlIFwiJHt0aGlzLmNvbnRleHQuaWRlbnRpZmllcn0uJHtkZXNjcmlwdG9yLm5hbWV9XCIgLSAke2Vycm9yLm1lc3NhZ2V9YDtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgdGhyb3cgZXJyb3I7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IHZhbHVlRGVzY3JpcHRvcnMoKSB7XG4gICAgICAgIGNvbnN0IHsgdmFsdWVEZXNjcmlwdG9yTWFwIH0gPSB0aGlzO1xuICAgICAgICByZXR1cm4gT2JqZWN0LmtleXModmFsdWVEZXNjcmlwdG9yTWFwKS5tYXAoKGtleSkgPT4gdmFsdWVEZXNjcmlwdG9yTWFwW2tleV0pO1xuICAgIH1cbiAgICBnZXQgdmFsdWVEZXNjcmlwdG9yTmFtZU1hcCgpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvcnMgPSB7fTtcbiAgICAgICAgT2JqZWN0LmtleXModGhpcy52YWx1ZURlc2NyaXB0b3JNYXApLmZvckVhY2goKGtleSkgPT4ge1xuICAgICAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwW2tleV07XG4gICAgICAgICAgICBkZXNjcmlwdG9yc1tkZXNjcmlwdG9yLm5hbWVdID0gZGVzY3JpcHRvcjtcbiAgICAgICAgfSk7XG4gICAgICAgIHJldHVybiBkZXNjcmlwdG9ycztcbiAgICB9XG4gICAgaGFzVmFsdWUoYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JOYW1lTWFwW2F0dHJpYnV0ZU5hbWVdO1xuICAgICAgICBjb25zdCBoYXNNZXRob2ROYW1lID0gYGhhcyR7Y2FwaXRhbGl6ZShkZXNjcmlwdG9yLm5hbWUpfWA7XG4gICAgICAgIHJldHVybiB0aGlzLnJlY2VpdmVyW2hhc01ldGhvZE5hbWVdO1xuICAgIH1cbn1cblxuY2xhc3MgVGFyZ2V0T2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy50YXJnZXRzQnlOYW1lID0gbmV3IE11bHRpbWFwKCk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIgPSBuZXcgVG9rZW5MaXN0T2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLmF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIGlmICh0aGlzLnRva2VuTGlzdE9ic2VydmVyKSB7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBbGxUYXJnZXRzKCk7XG4gICAgICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICAgICAgICAgIGRlbGV0ZSB0aGlzLnRva2VuTGlzdE9ic2VydmVyO1xuICAgICAgICB9XG4gICAgfVxuICAgIHRva2VuTWF0Y2hlZCh7IGVsZW1lbnQsIGNvbnRlbnQ6IG5hbWUgfSkge1xuICAgICAgICBpZiAodGhpcy5zY29wZS5jb250YWluc0VsZW1lbnQoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuY29ubmVjdFRhcmdldChlbGVtZW50LCBuYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICB0b2tlblVubWF0Y2hlZCh7IGVsZW1lbnQsIGNvbnRlbnQ6IG5hbWUgfSkge1xuICAgICAgICB0aGlzLmRpc2Nvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSk7XG4gICAgfVxuICAgIGNvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICghdGhpcy50YXJnZXRzQnlOYW1lLmhhcyhuYW1lLCBlbGVtZW50KSkge1xuICAgICAgICAgICAgdGhpcy50YXJnZXRzQnlOYW1lLmFkZChuYW1lLCBlbGVtZW50KTtcbiAgICAgICAgICAgIChfYSA9IHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIpID09PSBudWxsIHx8IF9hID09PSB2b2lkIDAgPyB2b2lkIDAgOiBfYS5wYXVzZSgoKSA9PiB0aGlzLmRlbGVnYXRlLnRhcmdldENvbm5lY3RlZChlbGVtZW50LCBuYW1lKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZGlzY29ubmVjdFRhcmdldChlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgaWYgKHRoaXMudGFyZ2V0c0J5TmFtZS5oYXMobmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMudGFyZ2V0c0J5TmFtZS5kZWxldGUobmFtZSwgZWxlbWVudCk7XG4gICAgICAgICAgICAoX2EgPSB0aGlzLnRva2VuTGlzdE9ic2VydmVyKSA9PT0gbnVsbCB8fCBfYSA9PT0gdm9pZCAwID8gdm9pZCAwIDogX2EucGF1c2UoKCkgPT4gdGhpcy5kZWxlZ2F0ZS50YXJnZXREaXNjb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RBbGxUYXJnZXRzKCkge1xuICAgICAgICBmb3IgKGNvbnN0IG5hbWUgb2YgdGhpcy50YXJnZXRzQnlOYW1lLmtleXMpIHtcbiAgICAgICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiB0aGlzLnRhcmdldHNCeU5hbWUuZ2V0VmFsdWVzRm9yS2V5KG5hbWUpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBhdHRyaWJ1dGVOYW1lKCkge1xuICAgICAgICByZXR1cm4gYGRhdGEtJHt0aGlzLmNvbnRleHQuaWRlbnRpZmllcn0tdGFyZ2V0YDtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbn1cblxuZnVuY3Rpb24gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIHByb3BlcnR5TmFtZSkge1xuICAgIGNvbnN0IGFuY2VzdG9ycyA9IGdldEFuY2VzdG9yc0ZvckNvbnN0cnVjdG9yKGNvbnN0cnVjdG9yKTtcbiAgICByZXR1cm4gQXJyYXkuZnJvbShhbmNlc3RvcnMucmVkdWNlKCh2YWx1ZXMsIGNvbnN0cnVjdG9yKSA9PiB7XG4gICAgICAgIGdldE93blN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpLmZvckVhY2goKG5hbWUpID0+IHZhbHVlcy5hZGQobmFtZSkpO1xuICAgICAgICByZXR1cm4gdmFsdWVzO1xuICAgIH0sIG5ldyBTZXQoKSkpO1xufVxuZnVuY3Rpb24gcmVhZEluaGVyaXRhYmxlU3RhdGljT2JqZWN0UGFpcnMoY29uc3RydWN0b3IsIHByb3BlcnR5TmFtZSkge1xuICAgIGNvbnN0IGFuY2VzdG9ycyA9IGdldEFuY2VzdG9yc0ZvckNvbnN0cnVjdG9yKGNvbnN0cnVjdG9yKTtcbiAgICByZXR1cm4gYW5jZXN0b3JzLnJlZHVjZSgocGFpcnMsIGNvbnN0cnVjdG9yKSA9PiB7XG4gICAgICAgIHBhaXJzLnB1c2goLi4uZ2V0T3duU3RhdGljT2JqZWN0UGFpcnMoY29uc3RydWN0b3IsIHByb3BlcnR5TmFtZSkpO1xuICAgICAgICByZXR1cm4gcGFpcnM7XG4gICAgfSwgW10pO1xufVxuZnVuY3Rpb24gZ2V0QW5jZXN0b3JzRm9yQ29uc3RydWN0b3IoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBhbmNlc3RvcnMgPSBbXTtcbiAgICB3aGlsZSAoY29uc3RydWN0b3IpIHtcbiAgICAgICAgYW5jZXN0b3JzLnB1c2goY29uc3RydWN0b3IpO1xuICAgICAgICBjb25zdHJ1Y3RvciA9IE9iamVjdC5nZXRQcm90b3R5cGVPZihjb25zdHJ1Y3Rvcik7XG4gICAgfVxuICAgIHJldHVybiBhbmNlc3RvcnMucmV2ZXJzZSgpO1xufVxuZnVuY3Rpb24gZ2V0T3duU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIHByb3BlcnR5TmFtZSkge1xuICAgIGNvbnN0IGRlZmluaXRpb24gPSBjb25zdHJ1Y3Rvcltwcm9wZXJ0eU5hbWVdO1xuICAgIHJldHVybiBBcnJheS5pc0FycmF5KGRlZmluaXRpb24pID8gZGVmaW5pdGlvbiA6IFtdO1xufVxuZnVuY3Rpb24gZ2V0T3duU3RhdGljT2JqZWN0UGFpcnMoY29uc3RydWN0b3IsIHByb3BlcnR5TmFtZSkge1xuICAgIGNvbnN0IGRlZmluaXRpb24gPSBjb25zdHJ1Y3Rvcltwcm9wZXJ0eU5hbWVdO1xuICAgIHJldHVybiBkZWZpbml0aW9uID8gT2JqZWN0LmtleXMoZGVmaW5pdGlvbikubWFwKChrZXkpID0+IFtrZXksIGRlZmluaXRpb25ba2V5XV0pIDogW107XG59XG5cbmNsYXNzIE91dGxldE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3Rvcihjb250ZXh0LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLm91dGxldHNCeU5hbWUgPSBuZXcgTXVsdGltYXAoKTtcbiAgICAgICAgdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZSA9IG5ldyBNdWx0aW1hcCgpO1xuICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAgPSBuZXcgTWFwKCk7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAgPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5vdXRsZXREZWZpbml0aW9ucy5mb3JFYWNoKChvdXRsZXROYW1lKSA9PiB7XG4gICAgICAgICAgICAgICAgdGhpcy5zZXR1cFNlbGVjdG9yT2JzZXJ2ZXJGb3JPdXRsZXQob3V0bGV0TmFtZSk7XG4gICAgICAgICAgICAgICAgdGhpcy5zZXR1cEF0dHJpYnV0ZU9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5kZXBlbmRlbnRDb250ZXh0cy5mb3JFYWNoKChjb250ZXh0KSA9PiBjb250ZXh0LnJlZnJlc2goKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmZvckVhY2goKG9ic2VydmVyKSA9PiBvYnNlcnZlci5yZWZyZXNoKCkpO1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwLmZvckVhY2goKG9ic2VydmVyKSA9PiBvYnNlcnZlci5yZWZyZXNoKCkpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdEFsbE91dGxldHMoKTtcbiAgICAgICAgICAgIHRoaXMuc3RvcFNlbGVjdG9yT2JzZXJ2ZXJzKCk7XG4gICAgICAgICAgICB0aGlzLnN0b3BBdHRyaWJ1dGVPYnNlcnZlcnMoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wU2VsZWN0b3JPYnNlcnZlcnMoKSB7XG4gICAgICAgIGlmICh0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuc2l6ZSA+IDApIHtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIuc3RvcCgpKTtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5jbGVhcigpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0b3BBdHRyaWJ1dGVPYnNlcnZlcnMoKSB7XG4gICAgICAgIGlmICh0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwLnNpemUgPiAwKSB7XG4gICAgICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwLmZvckVhY2goKG9ic2VydmVyKSA9PiBvYnNlcnZlci5zdG9wKCkpO1xuICAgICAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5jbGVhcigpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHNlbGVjdG9yTWF0Y2hlZChlbGVtZW50LCBfc2VsZWN0b3IsIHsgb3V0bGV0TmFtZSB9KSB7XG4gICAgICAgIGNvbnN0IG91dGxldCA9IHRoaXMuZ2V0T3V0bGV0KGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgICAgICBpZiAob3V0bGV0KSB7XG4gICAgICAgICAgICB0aGlzLmNvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBfc2VsZWN0b3IsIHsgb3V0bGV0TmFtZSB9KSB7XG4gICAgICAgIGNvbnN0IG91dGxldCA9IHRoaXMuZ2V0T3V0bGV0RnJvbU1hcChlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKG91dGxldCkge1xuICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JNYXRjaEVsZW1lbnQoZWxlbWVudCwgeyBvdXRsZXROYW1lIH0pIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLnNlbGVjdG9yKG91dGxldE5hbWUpO1xuICAgICAgICBjb25zdCBoYXNPdXRsZXQgPSB0aGlzLmhhc091dGxldChlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgY29uc3QgaGFzT3V0bGV0Q29udHJvbGxlciA9IGVsZW1lbnQubWF0Y2hlcyhgWyR7dGhpcy5zY2hlbWEuY29udHJvbGxlckF0dHJpYnV0ZX1+PSR7b3V0bGV0TmFtZX1dYCk7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgcmV0dXJuIGhhc091dGxldCAmJiBoYXNPdXRsZXRDb250cm9sbGVyICYmIGVsZW1lbnQubWF0Y2hlcyhzZWxlY3Rvcik7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWRBdHRyaWJ1dGUoX2VsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3Qgb3V0bGV0TmFtZSA9IHRoaXMuZ2V0T3V0bGV0TmFtZUZyb21PdXRsZXRBdHRyaWJ1dGVOYW1lKGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICBpZiAob3V0bGV0TmFtZSkge1xuICAgICAgICAgICAgdGhpcy51cGRhdGVTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVWYWx1ZUNoYW5nZWQoX2VsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3Qgb3V0bGV0TmFtZSA9IHRoaXMuZ2V0T3V0bGV0TmFtZUZyb21PdXRsZXRBdHRyaWJ1dGVOYW1lKGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICBpZiAob3V0bGV0TmFtZSkge1xuICAgICAgICAgICAgdGhpcy51cGRhdGVTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUoX2VsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3Qgb3V0bGV0TmFtZSA9IHRoaXMuZ2V0T3V0bGV0TmFtZUZyb21PdXRsZXRBdHRyaWJ1dGVOYW1lKGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICBpZiAob3V0bGV0TmFtZSkge1xuICAgICAgICAgICAgdGhpcy51cGRhdGVTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGNvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgaWYgKCF0aGlzLm91dGxldEVsZW1lbnRzQnlOYW1lLmhhcyhvdXRsZXROYW1lLCBlbGVtZW50KSkge1xuICAgICAgICAgICAgdGhpcy5vdXRsZXRzQnlOYW1lLmFkZChvdXRsZXROYW1lLCBvdXRsZXQpO1xuICAgICAgICAgICAgdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5hZGQob3V0bGV0TmFtZSwgZWxlbWVudCk7XG4gICAgICAgICAgICAoX2EgPSB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuZ2V0KG91dGxldE5hbWUpKSA9PT0gbnVsbCB8fCBfYSA9PT0gdm9pZCAwID8gdm9pZCAwIDogX2EucGF1c2UoKCkgPT4gdGhpcy5kZWxlZ2F0ZS5vdXRsZXRDb25uZWN0ZWQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZGlzY29ubmVjdE91dGxldChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpIHtcbiAgICAgICAgdmFyIF9hO1xuICAgICAgICBpZiAodGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5oYXMob3V0bGV0TmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0c0J5TmFtZS5kZWxldGUob3V0bGV0TmFtZSwgb3V0bGV0KTtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuZGVsZXRlKG91dGxldE5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwXG4gICAgICAgICAgICAgICAgLmdldChvdXRsZXROYW1lKSkgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUub3V0bGV0RGlzY29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RBbGxPdXRsZXRzKCkge1xuICAgICAgICBmb3IgKGNvbnN0IG91dGxldE5hbWUgb2YgdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5rZXlzKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5nZXRWYWx1ZXNGb3JLZXkob3V0bGV0TmFtZSkpIHtcbiAgICAgICAgICAgICAgICBmb3IgKGNvbnN0IG91dGxldCBvZiB0aGlzLm91dGxldHNCeU5hbWUuZ2V0VmFsdWVzRm9yS2V5KG91dGxldE5hbWUpKSB7XG4gICAgICAgICAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdE91dGxldChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICB1cGRhdGVTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgb2JzZXJ2ZXIgPSB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuZ2V0KG91dGxldE5hbWUpO1xuICAgICAgICBpZiAob2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIG9ic2VydmVyLnNlbGVjdG9yID0gdGhpcy5zZWxlY3RvcihvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzZXR1cFNlbGVjdG9yT2JzZXJ2ZXJGb3JPdXRsZXQob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuc2VsZWN0b3Iob3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yT2JzZXJ2ZXIgPSBuZXcgU2VsZWN0b3JPYnNlcnZlcihkb2N1bWVudC5ib2R5LCBzZWxlY3RvciwgdGhpcywgeyBvdXRsZXROYW1lIH0pO1xuICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuc2V0KG91dGxldE5hbWUsIHNlbGVjdG9yT2JzZXJ2ZXIpO1xuICAgICAgICBzZWxlY3Rvck9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHNldHVwQXR0cmlidXRlT2JzZXJ2ZXJGb3JPdXRsZXQob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5hdHRyaWJ1dGVOYW1lRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlT2JzZXJ2ZXIgPSBuZXcgQXR0cmlidXRlT2JzZXJ2ZXIodGhpcy5zY29wZS5lbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCB0aGlzKTtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5zZXQob3V0bGV0TmFtZSwgYXR0cmlidXRlT2JzZXJ2ZXIpO1xuICAgICAgICBhdHRyaWJ1dGVPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzZWxlY3RvcihvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLm91dGxldHMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBhdHRyaWJ1dGVOYW1lRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLnNjaGVtYS5vdXRsZXRBdHRyaWJ1dGVGb3JTY29wZSh0aGlzLmlkZW50aWZpZXIsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBnZXRPdXRsZXROYW1lRnJvbU91dGxldEF0dHJpYnV0ZU5hbWUoYXR0cmlidXRlTmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXREZWZpbml0aW9ucy5maW5kKChvdXRsZXROYW1lKSA9PiB0aGlzLmF0dHJpYnV0ZU5hbWVGb3JPdXRsZXROYW1lKG91dGxldE5hbWUpID09PSBhdHRyaWJ1dGVOYW1lKTtcbiAgICB9XG4gICAgZ2V0IG91dGxldERlcGVuZGVuY2llcygpIHtcbiAgICAgICAgY29uc3QgZGVwZW5kZW5jaWVzID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMucm91dGVyLm1vZHVsZXMuZm9yRWFjaCgobW9kdWxlKSA9PiB7XG4gICAgICAgICAgICBjb25zdCBjb25zdHJ1Y3RvciA9IG1vZHVsZS5kZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvcjtcbiAgICAgICAgICAgIGNvbnN0IG91dGxldHMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJvdXRsZXRzXCIpO1xuICAgICAgICAgICAgb3V0bGV0cy5mb3JFYWNoKChvdXRsZXQpID0+IGRlcGVuZGVuY2llcy5hZGQob3V0bGV0LCBtb2R1bGUuaWRlbnRpZmllcikpO1xuICAgICAgICB9KTtcbiAgICAgICAgcmV0dXJuIGRlcGVuZGVuY2llcztcbiAgICB9XG4gICAgZ2V0IG91dGxldERlZmluaXRpb25zKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXREZXBlbmRlbmNpZXMuZ2V0S2V5c0ZvclZhbHVlKHRoaXMuaWRlbnRpZmllcik7XG4gICAgfVxuICAgIGdldCBkZXBlbmRlbnRDb250cm9sbGVySWRlbnRpZmllcnMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm91dGxldERlcGVuZGVuY2llcy5nZXRWYWx1ZXNGb3JLZXkodGhpcy5pZGVudGlmaWVyKTtcbiAgICB9XG4gICAgZ2V0IGRlcGVuZGVudENvbnRleHRzKCkge1xuICAgICAgICBjb25zdCBpZGVudGlmaWVycyA9IHRoaXMuZGVwZW5kZW50Q29udHJvbGxlcklkZW50aWZpZXJzO1xuICAgICAgICByZXR1cm4gdGhpcy5yb3V0ZXIuY29udGV4dHMuZmlsdGVyKChjb250ZXh0KSA9PiBpZGVudGlmaWVycy5pbmNsdWRlcyhjb250ZXh0LmlkZW50aWZpZXIpKTtcbiAgICB9XG4gICAgaGFzT3V0bGV0KGVsZW1lbnQsIG91dGxldE5hbWUpIHtcbiAgICAgICAgcmV0dXJuICEhdGhpcy5nZXRPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSkgfHwgISF0aGlzLmdldE91dGxldEZyb21NYXAoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgfVxuICAgIGdldE91dGxldChlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmdldENvbnRyb2xsZXJGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgZ2V0T3V0bGV0RnJvbU1hcChlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm91dGxldHNCeU5hbWUuZ2V0VmFsdWVzRm9yS2V5KG91dGxldE5hbWUpLmZpbmQoKG91dGxldCkgPT4gb3V0bGV0LmVsZW1lbnQgPT09IGVsZW1lbnQpO1xuICAgIH1cbiAgICBnZXQgc2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NvcGU7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NoZW1hO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgYXBwbGljYXRpb24oKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuYXBwbGljYXRpb247XG4gICAgfVxuICAgIGdldCByb3V0ZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLnJvdXRlcjtcbiAgICB9XG59XG5cbmNsYXNzIENvbnRleHQge1xuICAgIGNvbnN0cnVjdG9yKG1vZHVsZSwgc2NvcGUpIHtcbiAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5ID0gKGZ1bmN0aW9uTmFtZSwgZGV0YWlsID0ge30pID0+IHtcbiAgICAgICAgICAgIGNvbnN0IHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9ID0gdGhpcztcbiAgICAgICAgICAgIGRldGFpbCA9IE9iamVjdC5hc3NpZ24oeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50IH0sIGRldGFpbCk7XG4gICAgICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmxvZ0RlYnVnQWN0aXZpdHkodGhpcy5pZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCk7XG4gICAgICAgIH07XG4gICAgICAgIHRoaXMubW9kdWxlID0gbW9kdWxlO1xuICAgICAgICB0aGlzLnNjb3BlID0gc2NvcGU7XG4gICAgICAgIHRoaXMuY29udHJvbGxlciA9IG5ldyBtb2R1bGUuY29udHJvbGxlckNvbnN0cnVjdG9yKHRoaXMpO1xuICAgICAgICB0aGlzLmJpbmRpbmdPYnNlcnZlciA9IG5ldyBCaW5kaW5nT2JzZXJ2ZXIodGhpcywgdGhpcy5kaXNwYXRjaGVyKTtcbiAgICAgICAgdGhpcy52YWx1ZU9ic2VydmVyID0gbmV3IFZhbHVlT2JzZXJ2ZXIodGhpcywgdGhpcy5jb250cm9sbGVyKTtcbiAgICAgICAgdGhpcy50YXJnZXRPYnNlcnZlciA9IG5ldyBUYXJnZXRPYnNlcnZlcih0aGlzLCB0aGlzKTtcbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlciA9IG5ldyBPdXRsZXRPYnNlcnZlcih0aGlzLCB0aGlzKTtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIHRoaXMuY29udHJvbGxlci5pbml0aWFsaXplKCk7XG4gICAgICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJpbml0aWFsaXplXCIpO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgdGhpcy5oYW5kbGVFcnJvcihlcnJvciwgXCJpbml0aWFsaXppbmcgY29udHJvbGxlclwiKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBjb25uZWN0KCkge1xuICAgICAgICB0aGlzLmJpbmRpbmdPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB0aGlzLnZhbHVlT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy50YXJnZXRPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB0aGlzLm91dGxldE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLmNvbnRyb2xsZXIuY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiY29ubmVjdFwiKTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHRoaXMuaGFuZGxlRXJyb3IoZXJyb3IsIFwiY29ubmVjdGluZyBjb250cm9sbGVyXCIpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMub3V0bGV0T2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBkaXNjb25uZWN0KCkge1xuICAgICAgICB0cnkge1xuICAgICAgICAgICAgdGhpcy5jb250cm9sbGVyLmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImRpc2Nvbm5lY3RcIik7XG4gICAgICAgIH1cbiAgICAgICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICB0aGlzLmhhbmRsZUVycm9yKGVycm9yLCBcImRpc2Nvbm5lY3RpbmcgY29udHJvbGxlclwiKTtcbiAgICAgICAgfVxuICAgICAgICB0aGlzLm91dGxldE9ic2VydmVyLnN0b3AoKTtcbiAgICAgICAgdGhpcy50YXJnZXRPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgIHRoaXMudmFsdWVPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgIHRoaXMuYmluZGluZ09ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgZ2V0IGFwcGxpY2F0aW9uKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5tb2R1bGUuYXBwbGljYXRpb247XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5tb2R1bGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24uc2NoZW1hO1xuICAgIH1cbiAgICBnZXQgZGlzcGF0Y2hlcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24uZGlzcGF0Y2hlcjtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBwYXJlbnRFbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50LnBhcmVudEVsZW1lbnQ7XG4gICAgfVxuICAgIGhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwgPSB7fSkge1xuICAgICAgICBjb25zdCB7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXIsIGVsZW1lbnQgfSA9IHRoaXM7XG4gICAgICAgIGRldGFpbCA9IE9iamVjdC5hc3NpZ24oeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50IH0sIGRldGFpbCk7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24uaGFuZGxlRXJyb3IoZXJyb3IsIGBFcnJvciAke21lc3NhZ2V9YCwgZGV0YWlsKTtcbiAgICB9XG4gICAgdGFyZ2V0Q29ubmVjdGVkKGVsZW1lbnQsIG5hbWUpIHtcbiAgICAgICAgdGhpcy5pbnZva2VDb250cm9sbGVyTWV0aG9kKGAke25hbWV9VGFyZ2V0Q29ubmVjdGVkYCwgZWxlbWVudCk7XG4gICAgfVxuICAgIHRhcmdldERpc2Nvbm5lY3RlZChlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHRoaXMuaW52b2tlQ29udHJvbGxlck1ldGhvZChgJHtuYW1lfVRhcmdldERpc2Nvbm5lY3RlZGAsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBvdXRsZXRDb25uZWN0ZWQob3V0bGV0LCBlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHRoaXMuaW52b2tlQ29udHJvbGxlck1ldGhvZChgJHtuYW1lc3BhY2VDYW1lbGl6ZShuYW1lKX1PdXRsZXRDb25uZWN0ZWRgLCBvdXRsZXQsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBvdXRsZXREaXNjb25uZWN0ZWQob3V0bGV0LCBlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHRoaXMuaW52b2tlQ29udHJvbGxlck1ldGhvZChgJHtuYW1lc3BhY2VDYW1lbGl6ZShuYW1lKX1PdXRsZXREaXNjb25uZWN0ZWRgLCBvdXRsZXQsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBpbnZva2VDb250cm9sbGVyTWV0aG9kKG1ldGhvZE5hbWUsIC4uLmFyZ3MpIHtcbiAgICAgICAgY29uc3QgY29udHJvbGxlciA9IHRoaXMuY29udHJvbGxlcjtcbiAgICAgICAgaWYgKHR5cGVvZiBjb250cm9sbGVyW21ldGhvZE5hbWVdID09IFwiZnVuY3Rpb25cIikge1xuICAgICAgICAgICAgY29udHJvbGxlclttZXRob2ROYW1lXSguLi5hcmdzKTtcbiAgICAgICAgfVxuICAgIH1cbn1cblxuZnVuY3Rpb24gYmxlc3MoY29uc3RydWN0b3IpIHtcbiAgICByZXR1cm4gc2hhZG93KGNvbnN0cnVjdG9yLCBnZXRCbGVzc2VkUHJvcGVydGllcyhjb25zdHJ1Y3RvcikpO1xufVxuZnVuY3Rpb24gc2hhZG93KGNvbnN0cnVjdG9yLCBwcm9wZXJ0aWVzKSB7XG4gICAgY29uc3Qgc2hhZG93Q29uc3RydWN0b3IgPSBleHRlbmQoY29uc3RydWN0b3IpO1xuICAgIGNvbnN0IHNoYWRvd1Byb3BlcnRpZXMgPSBnZXRTaGFkb3dQcm9wZXJ0aWVzKGNvbnN0cnVjdG9yLnByb3RvdHlwZSwgcHJvcGVydGllcyk7XG4gICAgT2JqZWN0LmRlZmluZVByb3BlcnRpZXMoc2hhZG93Q29uc3RydWN0b3IucHJvdG90eXBlLCBzaGFkb3dQcm9wZXJ0aWVzKTtcbiAgICByZXR1cm4gc2hhZG93Q29uc3RydWN0b3I7XG59XG5mdW5jdGlvbiBnZXRCbGVzc2VkUHJvcGVydGllcyhjb25zdHJ1Y3Rvcikge1xuICAgIGNvbnN0IGJsZXNzaW5ncyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBcImJsZXNzaW5nc1wiKTtcbiAgICByZXR1cm4gYmxlc3NpbmdzLnJlZHVjZSgoYmxlc3NlZFByb3BlcnRpZXMsIGJsZXNzaW5nKSA9PiB7XG4gICAgICAgIGNvbnN0IHByb3BlcnRpZXMgPSBibGVzc2luZyhjb25zdHJ1Y3Rvcik7XG4gICAgICAgIGZvciAoY29uc3Qga2V5IGluIHByb3BlcnRpZXMpIHtcbiAgICAgICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSBibGVzc2VkUHJvcGVydGllc1trZXldIHx8IHt9O1xuICAgICAgICAgICAgYmxlc3NlZFByb3BlcnRpZXNba2V5XSA9IE9iamVjdC5hc3NpZ24oZGVzY3JpcHRvciwgcHJvcGVydGllc1trZXldKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gYmxlc3NlZFByb3BlcnRpZXM7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gZ2V0U2hhZG93UHJvcGVydGllcyhwcm90b3R5cGUsIHByb3BlcnRpZXMpIHtcbiAgICByZXR1cm4gZ2V0T3duS2V5cyhwcm9wZXJ0aWVzKS5yZWR1Y2UoKHNoYWRvd1Byb3BlcnRpZXMsIGtleSkgPT4ge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gZ2V0U2hhZG93ZWREZXNjcmlwdG9yKHByb3RvdHlwZSwgcHJvcGVydGllcywga2V5KTtcbiAgICAgICAgaWYgKGRlc2NyaXB0b3IpIHtcbiAgICAgICAgICAgIE9iamVjdC5hc3NpZ24oc2hhZG93UHJvcGVydGllcywgeyBba2V5XTogZGVzY3JpcHRvciB9KTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gc2hhZG93UHJvcGVydGllcztcbiAgICB9LCB7fSk7XG59XG5mdW5jdGlvbiBnZXRTaGFkb3dlZERlc2NyaXB0b3IocHJvdG90eXBlLCBwcm9wZXJ0aWVzLCBrZXkpIHtcbiAgICBjb25zdCBzaGFkb3dpbmdEZXNjcmlwdG9yID0gT2JqZWN0LmdldE93blByb3BlcnR5RGVzY3JpcHRvcihwcm90b3R5cGUsIGtleSk7XG4gICAgY29uc3Qgc2hhZG93ZWRCeVZhbHVlID0gc2hhZG93aW5nRGVzY3JpcHRvciAmJiBcInZhbHVlXCIgaW4gc2hhZG93aW5nRGVzY3JpcHRvcjtcbiAgICBpZiAoIXNoYWRvd2VkQnlWYWx1ZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gT2JqZWN0LmdldE93blByb3BlcnR5RGVzY3JpcHRvcihwcm9wZXJ0aWVzLCBrZXkpLnZhbHVlO1xuICAgICAgICBpZiAoc2hhZG93aW5nRGVzY3JpcHRvcikge1xuICAgICAgICAgICAgZGVzY3JpcHRvci5nZXQgPSBzaGFkb3dpbmdEZXNjcmlwdG9yLmdldCB8fCBkZXNjcmlwdG9yLmdldDtcbiAgICAgICAgICAgIGRlc2NyaXB0b3Iuc2V0ID0gc2hhZG93aW5nRGVzY3JpcHRvci5zZXQgfHwgZGVzY3JpcHRvci5zZXQ7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGRlc2NyaXB0b3I7XG4gICAgfVxufVxuY29uc3QgZ2V0T3duS2V5cyA9ICgoKSA9PiB7XG4gICAgaWYgKHR5cGVvZiBPYmplY3QuZ2V0T3duUHJvcGVydHlTeW1ib2xzID09IFwiZnVuY3Rpb25cIikge1xuICAgICAgICByZXR1cm4gKG9iamVjdCkgPT4gWy4uLk9iamVjdC5nZXRPd25Qcm9wZXJ0eU5hbWVzKG9iamVjdCksIC4uLk9iamVjdC5nZXRPd25Qcm9wZXJ0eVN5bWJvbHMob2JqZWN0KV07XG4gICAgfVxuICAgIGVsc2Uge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmdldE93blByb3BlcnR5TmFtZXM7XG4gICAgfVxufSkoKTtcbmNvbnN0IGV4dGVuZCA9ICgoKSA9PiB7XG4gICAgZnVuY3Rpb24gZXh0ZW5kV2l0aFJlZmxlY3QoY29uc3RydWN0b3IpIHtcbiAgICAgICAgZnVuY3Rpb24gZXh0ZW5kZWQoKSB7XG4gICAgICAgICAgICByZXR1cm4gUmVmbGVjdC5jb25zdHJ1Y3QoY29uc3RydWN0b3IsIGFyZ3VtZW50cywgbmV3LnRhcmdldCk7XG4gICAgICAgIH1cbiAgICAgICAgZXh0ZW5kZWQucHJvdG90eXBlID0gT2JqZWN0LmNyZWF0ZShjb25zdHJ1Y3Rvci5wcm90b3R5cGUsIHtcbiAgICAgICAgICAgIGNvbnN0cnVjdG9yOiB7IHZhbHVlOiBleHRlbmRlZCB9LFxuICAgICAgICB9KTtcbiAgICAgICAgUmVmbGVjdC5zZXRQcm90b3R5cGVPZihleHRlbmRlZCwgY29uc3RydWN0b3IpO1xuICAgICAgICByZXR1cm4gZXh0ZW5kZWQ7XG4gICAgfVxuICAgIGZ1bmN0aW9uIHRlc3RSZWZsZWN0RXh0ZW5zaW9uKCkge1xuICAgICAgICBjb25zdCBhID0gZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgdGhpcy5hLmNhbGwodGhpcyk7XG4gICAgICAgIH07XG4gICAgICAgIGNvbnN0IGIgPSBleHRlbmRXaXRoUmVmbGVjdChhKTtcbiAgICAgICAgYi5wcm90b3R5cGUuYSA9IGZ1bmN0aW9uICgpIHsgfTtcbiAgICAgICAgcmV0dXJuIG5ldyBiKCk7XG4gICAgfVxuICAgIHRyeSB7XG4gICAgICAgIHRlc3RSZWZsZWN0RXh0ZW5zaW9uKCk7XG4gICAgICAgIHJldHVybiBleHRlbmRXaXRoUmVmbGVjdDtcbiAgICB9XG4gICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgIHJldHVybiAoY29uc3RydWN0b3IpID0+IGNsYXNzIGV4dGVuZGVkIGV4dGVuZHMgY29uc3RydWN0b3Ige1xuICAgICAgICB9O1xuICAgIH1cbn0pKCk7XG5cbmZ1bmN0aW9uIGJsZXNzRGVmaW5pdGlvbihkZWZpbml0aW9uKSB7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgaWRlbnRpZmllcjogZGVmaW5pdGlvbi5pZGVudGlmaWVyLFxuICAgICAgICBjb250cm9sbGVyQ29uc3RydWN0b3I6IGJsZXNzKGRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yKSxcbiAgICB9O1xufVxuXG5jbGFzcyBNb2R1bGUge1xuICAgIGNvbnN0cnVjdG9yKGFwcGxpY2F0aW9uLCBkZWZpbml0aW9uKSB7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24gPSBhcHBsaWNhdGlvbjtcbiAgICAgICAgdGhpcy5kZWZpbml0aW9uID0gYmxlc3NEZWZpbml0aW9uKGRlZmluaXRpb24pO1xuICAgICAgICB0aGlzLmNvbnRleHRzQnlTY29wZSA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMuY29ubmVjdGVkQ29udGV4dHMgPSBuZXcgU2V0KCk7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5kZWZpbml0aW9uLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVyQ29uc3RydWN0b3IoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yO1xuICAgIH1cbiAgICBnZXQgY29udGV4dHMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuY29ubmVjdGVkQ29udGV4dHMpO1xuICAgIH1cbiAgICBjb25uZWN0Q29udGV4dEZvclNjb3BlKHNjb3BlKSB7XG4gICAgICAgIGNvbnN0IGNvbnRleHQgPSB0aGlzLmZldGNoQ29udGV4dEZvclNjb3BlKHNjb3BlKTtcbiAgICAgICAgdGhpcy5jb25uZWN0ZWRDb250ZXh0cy5hZGQoY29udGV4dCk7XG4gICAgICAgIGNvbnRleHQuY29ubmVjdCgpO1xuICAgIH1cbiAgICBkaXNjb25uZWN0Q29udGV4dEZvclNjb3BlKHNjb3BlKSB7XG4gICAgICAgIGNvbnN0IGNvbnRleHQgPSB0aGlzLmNvbnRleHRzQnlTY29wZS5nZXQoc2NvcGUpO1xuICAgICAgICBpZiAoY29udGV4dCkge1xuICAgICAgICAgICAgdGhpcy5jb25uZWN0ZWRDb250ZXh0cy5kZWxldGUoY29udGV4dCk7XG4gICAgICAgICAgICBjb250ZXh0LmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBmZXRjaENvbnRleHRGb3JTY29wZShzY29wZSkge1xuICAgICAgICBsZXQgY29udGV4dCA9IHRoaXMuY29udGV4dHNCeVNjb3BlLmdldChzY29wZSk7XG4gICAgICAgIGlmICghY29udGV4dCkge1xuICAgICAgICAgICAgY29udGV4dCA9IG5ldyBDb250ZXh0KHRoaXMsIHNjb3BlKTtcbiAgICAgICAgICAgIHRoaXMuY29udGV4dHNCeVNjb3BlLnNldChzY29wZSwgY29udGV4dCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGNvbnRleHQ7XG4gICAgfVxufVxuXG5jbGFzcyBDbGFzc01hcCB7XG4gICAgY29uc3RydWN0b3Ioc2NvcGUpIHtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgIH1cbiAgICBoYXMobmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5kYXRhLmhhcyh0aGlzLmdldERhdGFLZXkobmFtZSkpO1xuICAgIH1cbiAgICBnZXQobmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5nZXRBbGwobmFtZSlbMF07XG4gICAgfVxuICAgIGdldEFsbChuYW1lKSB7XG4gICAgICAgIGNvbnN0IHRva2VuU3RyaW5nID0gdGhpcy5kYXRhLmdldCh0aGlzLmdldERhdGFLZXkobmFtZSkpIHx8IFwiXCI7XG4gICAgICAgIHJldHVybiB0b2tlbml6ZSh0b2tlblN0cmluZyk7XG4gICAgfVxuICAgIGdldEF0dHJpYnV0ZU5hbWUobmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5kYXRhLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkodGhpcy5nZXREYXRhS2V5KG5hbWUpKTtcbiAgICB9XG4gICAgZ2V0RGF0YUtleShuYW1lKSB7XG4gICAgICAgIHJldHVybiBgJHtuYW1lfS1jbGFzc2A7XG4gICAgfVxuICAgIGdldCBkYXRhKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5kYXRhO1xuICAgIH1cbn1cblxuY2xhc3MgRGF0YU1hcCB7XG4gICAgY29uc3RydWN0b3Ioc2NvcGUpIHtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldChrZXkpIHtcbiAgICAgICAgY29uc3QgbmFtZSA9IHRoaXMuZ2V0QXR0cmlidXRlTmFtZUZvcktleShrZXkpO1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50LmdldEF0dHJpYnV0ZShuYW1lKTtcbiAgICB9XG4gICAgc2V0KGtleSwgdmFsdWUpIHtcbiAgICAgICAgY29uc3QgbmFtZSA9IHRoaXMuZ2V0QXR0cmlidXRlTmFtZUZvcktleShrZXkpO1xuICAgICAgICB0aGlzLmVsZW1lbnQuc2V0QXR0cmlidXRlKG5hbWUsIHZhbHVlKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZ2V0KGtleSk7XG4gICAgfVxuICAgIGhhcyhrZXkpIHtcbiAgICAgICAgY29uc3QgbmFtZSA9IHRoaXMuZ2V0QXR0cmlidXRlTmFtZUZvcktleShrZXkpO1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50Lmhhc0F0dHJpYnV0ZShuYW1lKTtcbiAgICB9XG4gICAgZGVsZXRlKGtleSkge1xuICAgICAgICBpZiAodGhpcy5oYXMoa2V5KSkge1xuICAgICAgICAgICAgY29uc3QgbmFtZSA9IHRoaXMuZ2V0QXR0cmlidXRlTmFtZUZvcktleShrZXkpO1xuICAgICAgICAgICAgdGhpcy5lbGVtZW50LnJlbW92ZUF0dHJpYnV0ZShuYW1lKTtcbiAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KSB7XG4gICAgICAgIHJldHVybiBgZGF0YS0ke3RoaXMuaWRlbnRpZmllcn0tJHtkYXNoZXJpemUoa2V5KX1gO1xuICAgIH1cbn1cblxuY2xhc3MgR3VpZGUge1xuICAgIGNvbnN0cnVjdG9yKGxvZ2dlcikge1xuICAgICAgICB0aGlzLndhcm5lZEtleXNCeU9iamVjdCA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMubG9nZ2VyID0gbG9nZ2VyO1xuICAgIH1cbiAgICB3YXJuKG9iamVjdCwga2V5LCBtZXNzYWdlKSB7XG4gICAgICAgIGxldCB3YXJuZWRLZXlzID0gdGhpcy53YXJuZWRLZXlzQnlPYmplY3QuZ2V0KG9iamVjdCk7XG4gICAgICAgIGlmICghd2FybmVkS2V5cykge1xuICAgICAgICAgICAgd2FybmVkS2V5cyA9IG5ldyBTZXQoKTtcbiAgICAgICAgICAgIHRoaXMud2FybmVkS2V5c0J5T2JqZWN0LnNldChvYmplY3QsIHdhcm5lZEtleXMpO1xuICAgICAgICB9XG4gICAgICAgIGlmICghd2FybmVkS2V5cy5oYXMoa2V5KSkge1xuICAgICAgICAgICAgd2FybmVkS2V5cy5hZGQoa2V5KTtcbiAgICAgICAgICAgIHRoaXMubG9nZ2VyLndhcm4obWVzc2FnZSwgb2JqZWN0KTtcbiAgICAgICAgfVxuICAgIH1cbn1cblxuZnVuY3Rpb24gYXR0cmlidXRlVmFsdWVDb250YWluc1Rva2VuKGF0dHJpYnV0ZU5hbWUsIHRva2VuKSB7XG4gICAgcmV0dXJuIGBbJHthdHRyaWJ1dGVOYW1lfX49XCIke3Rva2VufVwiXWA7XG59XG5cbmNsYXNzIFRhcmdldFNldCB7XG4gICAgY29uc3RydWN0b3Ioc2NvcGUpIHtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLnNjaGVtYTtcbiAgICB9XG4gICAgaGFzKHRhcmdldE5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZmluZCh0YXJnZXROYW1lKSAhPSBudWxsO1xuICAgIH1cbiAgICBmaW5kKC4uLnRhcmdldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiB0YXJnZXROYW1lcy5yZWR1Y2UoKHRhcmdldCwgdGFyZ2V0TmFtZSkgPT4gdGFyZ2V0IHx8IHRoaXMuZmluZFRhcmdldCh0YXJnZXROYW1lKSB8fCB0aGlzLmZpbmRMZWdhY3lUYXJnZXQodGFyZ2V0TmFtZSksIHVuZGVmaW5lZCk7XG4gICAgfVxuICAgIGZpbmRBbGwoLi4udGFyZ2V0TmFtZXMpIHtcbiAgICAgICAgcmV0dXJuIHRhcmdldE5hbWVzLnJlZHVjZSgodGFyZ2V0cywgdGFyZ2V0TmFtZSkgPT4gW1xuICAgICAgICAgICAgLi4udGFyZ2V0cyxcbiAgICAgICAgICAgIC4uLnRoaXMuZmluZEFsbFRhcmdldHModGFyZ2V0TmFtZSksXG4gICAgICAgICAgICAuLi50aGlzLmZpbmRBbGxMZWdhY3lUYXJnZXRzKHRhcmdldE5hbWUpLFxuICAgICAgICBdLCBbXSk7XG4gICAgfVxuICAgIGZpbmRUYXJnZXQodGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5maW5kRWxlbWVudChzZWxlY3Rvcik7XG4gICAgfVxuICAgIGZpbmRBbGxUYXJnZXRzKHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yKTtcbiAgICB9XG4gICAgZ2V0U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuc2NoZW1hLnRhcmdldEF0dHJpYnV0ZUZvclNjb3BlKHRoaXMuaWRlbnRpZmllcik7XG4gICAgICAgIHJldHVybiBhdHRyaWJ1dGVWYWx1ZUNvbnRhaW5zVG9rZW4oYXR0cmlidXRlTmFtZSwgdGFyZ2V0TmFtZSk7XG4gICAgfVxuICAgIGZpbmRMZWdhY3lUYXJnZXQodGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0TGVnYWN5U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5kZXByZWNhdGUodGhpcy5zY29wZS5maW5kRWxlbWVudChzZWxlY3RvciksIHRhcmdldE5hbWUpO1xuICAgIH1cbiAgICBmaW5kQWxsTGVnYWN5VGFyZ2V0cyh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRMZWdhY3lTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSk7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmZpbmRBbGxFbGVtZW50cyhzZWxlY3RvcikubWFwKChlbGVtZW50KSA9PiB0aGlzLmRlcHJlY2F0ZShlbGVtZW50LCB0YXJnZXROYW1lKSk7XG4gICAgfVxuICAgIGdldExlZ2FjeVNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHRhcmdldERlc2NyaXB0b3IgPSBgJHt0aGlzLmlkZW50aWZpZXJ9LiR7dGFyZ2V0TmFtZX1gO1xuICAgICAgICByZXR1cm4gYXR0cmlidXRlVmFsdWVDb250YWluc1Rva2VuKHRoaXMuc2NoZW1hLnRhcmdldEF0dHJpYnV0ZSwgdGFyZ2V0RGVzY3JpcHRvcik7XG4gICAgfVxuICAgIGRlcHJlY2F0ZShlbGVtZW50LCB0YXJnZXROYW1lKSB7XG4gICAgICAgIGlmIChlbGVtZW50KSB7XG4gICAgICAgICAgICBjb25zdCB7IGlkZW50aWZpZXIgfSA9IHRoaXM7XG4gICAgICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEudGFyZ2V0QXR0cmlidXRlO1xuICAgICAgICAgICAgY29uc3QgcmV2aXNlZEF0dHJpYnV0ZU5hbWUgPSB0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGVGb3JTY29wZShpZGVudGlmaWVyKTtcbiAgICAgICAgICAgIHRoaXMuZ3VpZGUud2FybihlbGVtZW50LCBgdGFyZ2V0OiR7dGFyZ2V0TmFtZX1gLCBgUGxlYXNlIHJlcGxhY2UgJHthdHRyaWJ1dGVOYW1lfT1cIiR7aWRlbnRpZmllcn0uJHt0YXJnZXROYW1lfVwiIHdpdGggJHtyZXZpc2VkQXR0cmlidXRlTmFtZX09XCIke3RhcmdldE5hbWV9XCIuIGAgK1xuICAgICAgICAgICAgICAgIGBUaGUgJHthdHRyaWJ1dGVOYW1lfSBhdHRyaWJ1dGUgaXMgZGVwcmVjYXRlZCBhbmQgd2lsbCBiZSByZW1vdmVkIGluIGEgZnV0dXJlIHZlcnNpb24gb2YgU3RpbXVsdXMuYCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBndWlkZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZ3VpZGU7XG4gICAgfVxufVxuXG5jbGFzcyBPdXRsZXRTZXQge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlLCBjb250cm9sbGVyRWxlbWVudCkge1xuICAgICAgICB0aGlzLnNjb3BlID0gc2NvcGU7XG4gICAgICAgIHRoaXMuY29udHJvbGxlckVsZW1lbnQgPSBjb250cm9sbGVyRWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5zY2hlbWE7XG4gICAgfVxuICAgIGhhcyhvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmZpbmQob3V0bGV0TmFtZSkgIT0gbnVsbDtcbiAgICB9XG4gICAgZmluZCguLi5vdXRsZXROYW1lcykge1xuICAgICAgICByZXR1cm4gb3V0bGV0TmFtZXMucmVkdWNlKChvdXRsZXQsIG91dGxldE5hbWUpID0+IG91dGxldCB8fCB0aGlzLmZpbmRPdXRsZXQob3V0bGV0TmFtZSksIHVuZGVmaW5lZCk7XG4gICAgfVxuICAgIGZpbmRBbGwoLi4ub3V0bGV0TmFtZXMpIHtcbiAgICAgICAgcmV0dXJuIG91dGxldE5hbWVzLnJlZHVjZSgob3V0bGV0cywgb3V0bGV0TmFtZSkgPT4gWy4uLm91dGxldHMsIC4uLnRoaXMuZmluZEFsbE91dGxldHMob3V0bGV0TmFtZSldLCBbXSk7XG4gICAgfVxuICAgIGdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLnNjaGVtYS5vdXRsZXRBdHRyaWJ1dGVGb3JTY29wZSh0aGlzLmlkZW50aWZpZXIsIG91dGxldE5hbWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5jb250cm9sbGVyRWxlbWVudC5nZXRBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSk7XG4gICAgfVxuICAgIGZpbmRPdXRsZXQob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG91dGxldE5hbWUpO1xuICAgICAgICBpZiAoc2VsZWN0b3IpXG4gICAgICAgICAgICByZXR1cm4gdGhpcy5maW5kRWxlbWVudChzZWxlY3Rvciwgb3V0bGV0TmFtZSk7XG4gICAgfVxuICAgIGZpbmRBbGxPdXRsZXRzKG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHNlbGVjdG9yID8gdGhpcy5maW5kQWxsRWxlbWVudHMoc2VsZWN0b3IsIG91dGxldE5hbWUpIDogW107XG4gICAgfVxuICAgIGZpbmRFbGVtZW50KHNlbGVjdG9yLCBvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGVsZW1lbnRzID0gdGhpcy5zY29wZS5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKTtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnRzLmZpbHRlcigoZWxlbWVudCkgPT4gdGhpcy5tYXRjaGVzRWxlbWVudChlbGVtZW50LCBzZWxlY3Rvciwgb3V0bGV0TmFtZSkpWzBdO1xuICAgIH1cbiAgICBmaW5kQWxsRWxlbWVudHMoc2VsZWN0b3IsIG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3QgZWxlbWVudHMgPSB0aGlzLnNjb3BlLnF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpO1xuICAgICAgICByZXR1cm4gZWxlbWVudHMuZmlsdGVyKChlbGVtZW50KSA9PiB0aGlzLm1hdGNoZXNFbGVtZW50KGVsZW1lbnQsIHNlbGVjdG9yLCBvdXRsZXROYW1lKSk7XG4gICAgfVxuICAgIG1hdGNoZXNFbGVtZW50KGVsZW1lbnQsIHNlbGVjdG9yLCBvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGNvbnRyb2xsZXJBdHRyaWJ1dGUgPSBlbGVtZW50LmdldEF0dHJpYnV0ZSh0aGlzLnNjb3BlLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlKSB8fCBcIlwiO1xuICAgICAgICByZXR1cm4gZWxlbWVudC5tYXRjaGVzKHNlbGVjdG9yKSAmJiBjb250cm9sbGVyQXR0cmlidXRlLnNwbGl0KFwiIFwiKS5pbmNsdWRlcyhvdXRsZXROYW1lKTtcbiAgICB9XG59XG5cbmNsYXNzIFNjb3BlIHtcbiAgICBjb25zdHJ1Y3RvcihzY2hlbWEsIGVsZW1lbnQsIGlkZW50aWZpZXIsIGxvZ2dlcikge1xuICAgICAgICB0aGlzLnRhcmdldHMgPSBuZXcgVGFyZ2V0U2V0KHRoaXMpO1xuICAgICAgICB0aGlzLmNsYXNzZXMgPSBuZXcgQ2xhc3NNYXAodGhpcyk7XG4gICAgICAgIHRoaXMuZGF0YSA9IG5ldyBEYXRhTWFwKHRoaXMpO1xuICAgICAgICB0aGlzLmNvbnRhaW5zRWxlbWVudCA9IChlbGVtZW50KSA9PiB7XG4gICAgICAgICAgICByZXR1cm4gZWxlbWVudC5jbG9zZXN0KHRoaXMuY29udHJvbGxlclNlbGVjdG9yKSA9PT0gdGhpcy5lbGVtZW50O1xuICAgICAgICB9O1xuICAgICAgICB0aGlzLnNjaGVtYSA9IHNjaGVtYTtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5pZGVudGlmaWVyID0gaWRlbnRpZmllcjtcbiAgICAgICAgdGhpcy5ndWlkZSA9IG5ldyBHdWlkZShsb2dnZXIpO1xuICAgICAgICB0aGlzLm91dGxldHMgPSBuZXcgT3V0bGV0U2V0KHRoaXMuZG9jdW1lbnRTY29wZSwgZWxlbWVudCk7XG4gICAgfVxuICAgIGZpbmRFbGVtZW50KHNlbGVjdG9yKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnQubWF0Y2hlcyhzZWxlY3RvcikgPyB0aGlzLmVsZW1lbnQgOiB0aGlzLnF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpLmZpbmQodGhpcy5jb250YWluc0VsZW1lbnQpO1xuICAgIH1cbiAgICBmaW5kQWxsRWxlbWVudHMoc2VsZWN0b3IpIHtcbiAgICAgICAgcmV0dXJuIFtcbiAgICAgICAgICAgIC4uLih0aGlzLmVsZW1lbnQubWF0Y2hlcyhzZWxlY3RvcikgPyBbdGhpcy5lbGVtZW50XSA6IFtdKSxcbiAgICAgICAgICAgIC4uLnRoaXMucXVlcnlFbGVtZW50cyhzZWxlY3RvcikuZmlsdGVyKHRoaXMuY29udGFpbnNFbGVtZW50KSxcbiAgICAgICAgXTtcbiAgICB9XG4gICAgcXVlcnlFbGVtZW50cyhzZWxlY3Rvcikge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnQucXVlcnlTZWxlY3RvckFsbChzZWxlY3RvcikpO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlclNlbGVjdG9yKCkge1xuICAgICAgICByZXR1cm4gYXR0cmlidXRlVmFsdWVDb250YWluc1Rva2VuKHRoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGUsIHRoaXMuaWRlbnRpZmllcik7XG4gICAgfVxuICAgIGdldCBpc0RvY3VtZW50U2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnQgPT09IGRvY3VtZW50LmRvY3VtZW50RWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGRvY3VtZW50U2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmlzRG9jdW1lbnRTY29wZVxuICAgICAgICAgICAgPyB0aGlzXG4gICAgICAgICAgICA6IG5ldyBTY29wZSh0aGlzLnNjaGVtYSwgZG9jdW1lbnQuZG9jdW1lbnRFbGVtZW50LCB0aGlzLmlkZW50aWZpZXIsIHRoaXMuZ3VpZGUubG9nZ2VyKTtcbiAgICB9XG59XG5cbmNsYXNzIFNjb3BlT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIHNjaGVtYSwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5zY2hlbWEgPSBzY2hlbWE7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlciA9IG5ldyBWYWx1ZUxpc3RPYnNlcnZlcih0aGlzLmVsZW1lbnQsIHRoaXMuY29udHJvbGxlckF0dHJpYnV0ZSwgdGhpcyk7XG4gICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyQnlFbGVtZW50ID0gbmV3IFdlYWtNYXAoKTtcbiAgICAgICAgdGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cyA9IG5ldyBXZWFrTWFwKCk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlckF0dHJpYnV0ZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGU7XG4gICAgfVxuICAgIHBhcnNlVmFsdWVGb3JUb2tlbih0b2tlbikge1xuICAgICAgICBjb25zdCB7IGVsZW1lbnQsIGNvbnRlbnQ6IGlkZW50aWZpZXIgfSA9IHRva2VuO1xuICAgICAgICByZXR1cm4gdGhpcy5wYXJzZVZhbHVlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcik7XG4gICAgfVxuICAgIHBhcnNlVmFsdWVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgICAgIGNvbnN0IHNjb3Blc0J5SWRlbnRpZmllciA9IHRoaXMuZmV0Y2hTY29wZXNCeUlkZW50aWZpZXJGb3JFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICBsZXQgc2NvcGUgPSBzY29wZXNCeUlkZW50aWZpZXIuZ2V0KGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAoIXNjb3BlKSB7XG4gICAgICAgICAgICBzY29wZSA9IHRoaXMuZGVsZWdhdGUuY3JlYXRlU2NvcGVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICAgICAgICAgIHNjb3Blc0J5SWRlbnRpZmllci5zZXQoaWRlbnRpZmllciwgc2NvcGUpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBzY29wZTtcbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWRWYWx1ZShlbGVtZW50LCB2YWx1ZSkge1xuICAgICAgICBjb25zdCByZWZlcmVuY2VDb3VudCA9ICh0aGlzLnNjb3BlUmVmZXJlbmNlQ291bnRzLmdldCh2YWx1ZSkgfHwgMCkgKyAxO1xuICAgICAgICB0aGlzLnNjb3BlUmVmZXJlbmNlQ291bnRzLnNldCh2YWx1ZSwgcmVmZXJlbmNlQ291bnQpO1xuICAgICAgICBpZiAocmVmZXJlbmNlQ291bnQgPT0gMSkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zY29wZUNvbm5lY3RlZCh2YWx1ZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZFZhbHVlKGVsZW1lbnQsIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHJlZmVyZW5jZUNvdW50ID0gdGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5nZXQodmFsdWUpO1xuICAgICAgICBpZiAocmVmZXJlbmNlQ291bnQpIHtcbiAgICAgICAgICAgIHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMuc2V0KHZhbHVlLCByZWZlcmVuY2VDb3VudCAtIDEpO1xuICAgICAgICAgICAgaWYgKHJlZmVyZW5jZUNvdW50ID09IDEpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmRlbGVnYXRlLnNjb3BlRGlzY29ubmVjdGVkKHZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBmZXRjaFNjb3Blc0J5SWRlbnRpZmllckZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBsZXQgc2NvcGVzQnlJZGVudGlmaWVyID0gdGhpcy5zY29wZXNCeUlkZW50aWZpZXJCeUVsZW1lbnQuZ2V0KGVsZW1lbnQpO1xuICAgICAgICBpZiAoIXNjb3Blc0J5SWRlbnRpZmllcikge1xuICAgICAgICAgICAgc2NvcGVzQnlJZGVudGlmaWVyID0gbmV3IE1hcCgpO1xuICAgICAgICAgICAgdGhpcy5zY29wZXNCeUlkZW50aWZpZXJCeUVsZW1lbnQuc2V0KGVsZW1lbnQsIHNjb3Blc0J5SWRlbnRpZmllcik7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHNjb3Blc0J5SWRlbnRpZmllcjtcbiAgICB9XG59XG5cbmNsYXNzIFJvdXRlciB7XG4gICAgY29uc3RydWN0b3IoYXBwbGljYXRpb24pIHtcbiAgICAgICAgdGhpcy5hcHBsaWNhdGlvbiA9IGFwcGxpY2F0aW9uO1xuICAgICAgICB0aGlzLnNjb3BlT2JzZXJ2ZXIgPSBuZXcgU2NvcGVPYnNlcnZlcih0aGlzLmVsZW1lbnQsIHRoaXMuc2NoZW1hLCB0aGlzKTtcbiAgICAgICAgdGhpcy5zY29wZXNCeUlkZW50aWZpZXIgPSBuZXcgTXVsdGltYXAoKTtcbiAgICAgICAgdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyID0gbmV3IE1hcCgpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24uZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24uc2NoZW1hO1xuICAgIH1cbiAgICBnZXQgbG9nZ2VyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5sb2dnZXI7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVyQXR0cmlidXRlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY2hlbWEuY29udHJvbGxlckF0dHJpYnV0ZTtcbiAgICB9XG4gICAgZ2V0IG1vZHVsZXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci52YWx1ZXMoKSk7XG4gICAgfVxuICAgIGdldCBjb250ZXh0cygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMubW9kdWxlcy5yZWR1Y2UoKGNvbnRleHRzLCBtb2R1bGUpID0+IGNvbnRleHRzLmNvbmNhdChtb2R1bGUuY29udGV4dHMpLCBbXSk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLnNjb3BlT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgbG9hZERlZmluaXRpb24oZGVmaW5pdGlvbikge1xuICAgICAgICB0aGlzLnVubG9hZElkZW50aWZpZXIoZGVmaW5pdGlvbi5pZGVudGlmaWVyKTtcbiAgICAgICAgY29uc3QgbW9kdWxlID0gbmV3IE1vZHVsZSh0aGlzLmFwcGxpY2F0aW9uLCBkZWZpbml0aW9uKTtcbiAgICAgICAgdGhpcy5jb25uZWN0TW9kdWxlKG1vZHVsZSk7XG4gICAgICAgIGNvbnN0IGFmdGVyTG9hZCA9IGRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yLmFmdGVyTG9hZDtcbiAgICAgICAgaWYgKGFmdGVyTG9hZCkge1xuICAgICAgICAgICAgYWZ0ZXJMb2FkLmNhbGwoZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3IsIGRlZmluaXRpb24uaWRlbnRpZmllciwgdGhpcy5hcHBsaWNhdGlvbik7XG4gICAgICAgIH1cbiAgICB9XG4gICAgdW5sb2FkSWRlbnRpZmllcihpZGVudGlmaWVyKSB7XG4gICAgICAgIGNvbnN0IG1vZHVsZSA9IHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5nZXQoaWRlbnRpZmllcik7XG4gICAgICAgIGlmIChtb2R1bGUpIHtcbiAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdE1vZHVsZShtb2R1bGUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldENvbnRleHRGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgICAgIGNvbnN0IG1vZHVsZSA9IHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5nZXQoaWRlbnRpZmllcik7XG4gICAgICAgIGlmIChtb2R1bGUpIHtcbiAgICAgICAgICAgIHJldHVybiBtb2R1bGUuY29udGV4dHMuZmluZCgoY29udGV4dCkgPT4gY29udGV4dC5lbGVtZW50ID09IGVsZW1lbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHByb3Bvc2VUb0Nvbm5lY3RTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgY29uc3Qgc2NvcGUgPSB0aGlzLnNjb3BlT2JzZXJ2ZXIucGFyc2VWYWx1ZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAoc2NvcGUpIHtcbiAgICAgICAgICAgIHRoaXMuc2NvcGVPYnNlcnZlci5lbGVtZW50TWF0Y2hlZFZhbHVlKHNjb3BlLmVsZW1lbnQsIHNjb3BlKTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIGNvbnNvbGUuZXJyb3IoYENvdWxkbid0IGZpbmQgb3IgY3JlYXRlIHNjb3BlIGZvciBpZGVudGlmaWVyOiBcIiR7aWRlbnRpZmllcn1cIiBhbmQgZWxlbWVudDpgLCBlbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsKSB7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24uaGFuZGxlRXJyb3IoZXJyb3IsIG1lc3NhZ2UsIGRldGFpbCk7XG4gICAgfVxuICAgIGNyZWF0ZVNjb3BlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICByZXR1cm4gbmV3IFNjb3BlKHRoaXMuc2NoZW1hLCBlbGVtZW50LCBpZGVudGlmaWVyLCB0aGlzLmxvZ2dlcik7XG4gICAgfVxuICAgIHNjb3BlQ29ubmVjdGVkKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmFkZChzY29wZS5pZGVudGlmaWVyLCBzY29wZSk7XG4gICAgICAgIGNvbnN0IG1vZHVsZSA9IHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5nZXQoc2NvcGUuaWRlbnRpZmllcik7XG4gICAgICAgIGlmIChtb2R1bGUpIHtcbiAgICAgICAgICAgIG1vZHVsZS5jb25uZWN0Q29udGV4dEZvclNjb3BlKHNjb3BlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzY29wZURpc2Nvbm5lY3RlZChzY29wZSkge1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllci5kZWxldGUoc2NvcGUuaWRlbnRpZmllciwgc2NvcGUpO1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KHNjb3BlLmlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICBtb2R1bGUuZGlzY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgY29ubmVjdE1vZHVsZShtb2R1bGUpIHtcbiAgICAgICAgdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLnNldChtb2R1bGUuaWRlbnRpZmllciwgbW9kdWxlKTtcbiAgICAgICAgY29uc3Qgc2NvcGVzID0gdGhpcy5zY29wZXNCeUlkZW50aWZpZXIuZ2V0VmFsdWVzRm9yS2V5KG1vZHVsZS5pZGVudGlmaWVyKTtcbiAgICAgICAgc2NvcGVzLmZvckVhY2goKHNjb3BlKSA9PiBtb2R1bGUuY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkpO1xuICAgIH1cbiAgICBkaXNjb25uZWN0TW9kdWxlKG1vZHVsZSkge1xuICAgICAgICB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZGVsZXRlKG1vZHVsZS5pZGVudGlmaWVyKTtcbiAgICAgICAgY29uc3Qgc2NvcGVzID0gdGhpcy5zY29wZXNCeUlkZW50aWZpZXIuZ2V0VmFsdWVzRm9yS2V5KG1vZHVsZS5pZGVudGlmaWVyKTtcbiAgICAgICAgc2NvcGVzLmZvckVhY2goKHNjb3BlKSA9PiBtb2R1bGUuZGlzY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkpO1xuICAgIH1cbn1cblxuY29uc3QgZGVmYXVsdFNjaGVtYSA9IHtcbiAgICBjb250cm9sbGVyQXR0cmlidXRlOiBcImRhdGEtY29udHJvbGxlclwiLFxuICAgIGFjdGlvbkF0dHJpYnV0ZTogXCJkYXRhLWFjdGlvblwiLFxuICAgIHRhcmdldEF0dHJpYnV0ZTogXCJkYXRhLXRhcmdldFwiLFxuICAgIHRhcmdldEF0dHJpYnV0ZUZvclNjb3BlOiAoaWRlbnRpZmllcikgPT4gYGRhdGEtJHtpZGVudGlmaWVyfS10YXJnZXRgLFxuICAgIG91dGxldEF0dHJpYnV0ZUZvclNjb3BlOiAoaWRlbnRpZmllciwgb3V0bGV0KSA9PiBgZGF0YS0ke2lkZW50aWZpZXJ9LSR7b3V0bGV0fS1vdXRsZXRgLFxuICAgIGtleU1hcHBpbmdzOiBPYmplY3QuYXNzaWduKE9iamVjdC5hc3NpZ24oeyBlbnRlcjogXCJFbnRlclwiLCB0YWI6IFwiVGFiXCIsIGVzYzogXCJFc2NhcGVcIiwgc3BhY2U6IFwiIFwiLCB1cDogXCJBcnJvd1VwXCIsIGRvd246IFwiQXJyb3dEb3duXCIsIGxlZnQ6IFwiQXJyb3dMZWZ0XCIsIHJpZ2h0OiBcIkFycm93UmlnaHRcIiwgaG9tZTogXCJIb21lXCIsIGVuZDogXCJFbmRcIiwgcGFnZV91cDogXCJQYWdlVXBcIiwgcGFnZV9kb3duOiBcIlBhZ2VEb3duXCIgfSwgb2JqZWN0RnJvbUVudHJpZXMoXCJhYmNkZWZnaGlqa2xtbm9wcXJzdHV2d3h5elwiLnNwbGl0KFwiXCIpLm1hcCgoYykgPT4gW2MsIGNdKSkpLCBvYmplY3RGcm9tRW50cmllcyhcIjAxMjM0NTY3ODlcIi5zcGxpdChcIlwiKS5tYXAoKG4pID0+IFtuLCBuXSkpKSxcbn07XG5mdW5jdGlvbiBvYmplY3RGcm9tRW50cmllcyhhcnJheSkge1xuICAgIHJldHVybiBhcnJheS5yZWR1Y2UoKG1lbW8sIFtrLCB2XSkgPT4gKE9iamVjdC5hc3NpZ24oT2JqZWN0LmFzc2lnbih7fSwgbWVtbyksIHsgW2tdOiB2IH0pKSwge30pO1xufVxuXG5jbGFzcyBBcHBsaWNhdGlvbiB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCA9IGRvY3VtZW50LmRvY3VtZW50RWxlbWVudCwgc2NoZW1hID0gZGVmYXVsdFNjaGVtYSkge1xuICAgICAgICB0aGlzLmxvZ2dlciA9IGNvbnNvbGU7XG4gICAgICAgIHRoaXMuZGVidWcgPSBmYWxzZTtcbiAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5ID0gKGlkZW50aWZpZXIsIGZ1bmN0aW9uTmFtZSwgZGV0YWlsID0ge30pID0+IHtcbiAgICAgICAgICAgIGlmICh0aGlzLmRlYnVnKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5sb2dGb3JtYXR0ZWRNZXNzYWdlKGlkZW50aWZpZXIsIGZ1bmN0aW9uTmFtZSwgZGV0YWlsKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfTtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5zY2hlbWEgPSBzY2hlbWE7XG4gICAgICAgIHRoaXMuZGlzcGF0Y2hlciA9IG5ldyBEaXNwYXRjaGVyKHRoaXMpO1xuICAgICAgICB0aGlzLnJvdXRlciA9IG5ldyBSb3V0ZXIodGhpcyk7XG4gICAgICAgIHRoaXMuYWN0aW9uRGVzY3JpcHRvckZpbHRlcnMgPSBPYmplY3QuYXNzaWduKHt9LCBkZWZhdWx0QWN0aW9uRGVzY3JpcHRvckZpbHRlcnMpO1xuICAgIH1cbiAgICBzdGF0aWMgc3RhcnQoZWxlbWVudCwgc2NoZW1hKSB7XG4gICAgICAgIGNvbnN0IGFwcGxpY2F0aW9uID0gbmV3IHRoaXMoZWxlbWVudCwgc2NoZW1hKTtcbiAgICAgICAgYXBwbGljYXRpb24uc3RhcnQoKTtcbiAgICAgICAgcmV0dXJuIGFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBhc3luYyBzdGFydCgpIHtcbiAgICAgICAgYXdhaXQgZG9tUmVhZHkoKTtcbiAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiYXBwbGljYXRpb25cIiwgXCJzdGFydGluZ1wiKTtcbiAgICAgICAgdGhpcy5kaXNwYXRjaGVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMucm91dGVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RhcnRcIik7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RvcHBpbmdcIik7XG4gICAgICAgIHRoaXMuZGlzcGF0Y2hlci5zdG9wKCk7XG4gICAgICAgIHRoaXMucm91dGVyLnN0b3AoKTtcbiAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiYXBwbGljYXRpb25cIiwgXCJzdG9wXCIpO1xuICAgIH1cbiAgICByZWdpc3RlcihpZGVudGlmaWVyLCBjb250cm9sbGVyQ29uc3RydWN0b3IpIHtcbiAgICAgICAgdGhpcy5sb2FkKHsgaWRlbnRpZmllciwgY29udHJvbGxlckNvbnN0cnVjdG9yIH0pO1xuICAgIH1cbiAgICByZWdpc3RlckFjdGlvbk9wdGlvbihuYW1lLCBmaWx0ZXIpIHtcbiAgICAgICAgdGhpcy5hY3Rpb25EZXNjcmlwdG9yRmlsdGVyc1tuYW1lXSA9IGZpbHRlcjtcbiAgICB9XG4gICAgbG9hZChoZWFkLCAuLi5yZXN0KSB7XG4gICAgICAgIGNvbnN0IGRlZmluaXRpb25zID0gQXJyYXkuaXNBcnJheShoZWFkKSA/IGhlYWQgOiBbaGVhZCwgLi4ucmVzdF07XG4gICAgICAgIGRlZmluaXRpb25zLmZvckVhY2goKGRlZmluaXRpb24pID0+IHtcbiAgICAgICAgICAgIGlmIChkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3Rvci5zaG91bGRMb2FkKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5yb3V0ZXIubG9hZERlZmluaXRpb24oZGVmaW5pdGlvbik7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgIH1cbiAgICB1bmxvYWQoaGVhZCwgLi4ucmVzdCkge1xuICAgICAgICBjb25zdCBpZGVudGlmaWVycyA9IEFycmF5LmlzQXJyYXkoaGVhZCkgPyBoZWFkIDogW2hlYWQsIC4uLnJlc3RdO1xuICAgICAgICBpZGVudGlmaWVycy5mb3JFYWNoKChpZGVudGlmaWVyKSA9PiB0aGlzLnJvdXRlci51bmxvYWRJZGVudGlmaWVyKGlkZW50aWZpZXIpKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5yb3V0ZXIuY29udGV4dHMubWFwKChjb250ZXh0KSA9PiBjb250ZXh0LmNvbnRyb2xsZXIpO1xuICAgIH1cbiAgICBnZXRDb250cm9sbGVyRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBjb250ZXh0ID0gdGhpcy5yb3V0ZXIuZ2V0Q29udGV4dEZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xuICAgICAgICByZXR1cm4gY29udGV4dCA/IGNvbnRleHQuY29udHJvbGxlciA6IG51bGw7XG4gICAgfVxuICAgIGhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwpIHtcbiAgICAgICAgdmFyIF9hO1xuICAgICAgICB0aGlzLmxvZ2dlci5lcnJvcihgJXNcXG5cXG4lb1xcblxcbiVvYCwgbWVzc2FnZSwgZXJyb3IsIGRldGFpbCk7XG4gICAgICAgIChfYSA9IHdpbmRvdy5vbmVycm9yKSA9PT0gbnVsbCB8fCBfYSA9PT0gdm9pZCAwID8gdm9pZCAwIDogX2EuY2FsbCh3aW5kb3csIG1lc3NhZ2UsIFwiXCIsIDAsIDAsIGVycm9yKTtcbiAgICB9XG4gICAgbG9nRm9ybWF0dGVkTWVzc2FnZShpZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCA9IHt9KSB7XG4gICAgICAgIGRldGFpbCA9IE9iamVjdC5hc3NpZ24oeyBhcHBsaWNhdGlvbjogdGhpcyB9LCBkZXRhaWwpO1xuICAgICAgICB0aGlzLmxvZ2dlci5ncm91cENvbGxhcHNlZChgJHtpZGVudGlmaWVyfSAjJHtmdW5jdGlvbk5hbWV9YCk7XG4gICAgICAgIHRoaXMubG9nZ2VyLmxvZyhcImRldGFpbHM6XCIsIE9iamVjdC5hc3NpZ24oe30sIGRldGFpbCkpO1xuICAgICAgICB0aGlzLmxvZ2dlci5ncm91cEVuZCgpO1xuICAgIH1cbn1cbmZ1bmN0aW9uIGRvbVJlYWR5KCkge1xuICAgIHJldHVybiBuZXcgUHJvbWlzZSgocmVzb2x2ZSkgPT4ge1xuICAgICAgICBpZiAoZG9jdW1lbnQucmVhZHlTdGF0ZSA9PSBcImxvYWRpbmdcIikge1xuICAgICAgICAgICAgZG9jdW1lbnQuYWRkRXZlbnRMaXN0ZW5lcihcIkRPTUNvbnRlbnRMb2FkZWRcIiwgKCkgPT4gcmVzb2x2ZSgpKTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJlc29sdmUoKTtcbiAgICAgICAgfVxuICAgIH0pO1xufVxuXG5mdW5jdGlvbiBDbGFzc1Byb3BlcnRpZXNCbGVzc2luZyhjb25zdHJ1Y3Rvcikge1xuICAgIGNvbnN0IGNsYXNzZXMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJjbGFzc2VzXCIpO1xuICAgIHJldHVybiBjbGFzc2VzLnJlZHVjZSgocHJvcGVydGllcywgY2xhc3NEZWZpbml0aW9uKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JDbGFzc0RlZmluaXRpb24oY2xhc3NEZWZpbml0aW9uKSk7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gcHJvcGVydGllc0ZvckNsYXNzRGVmaW5pdGlvbihrZXkpIHtcbiAgICByZXR1cm4ge1xuICAgICAgICBbYCR7a2V5fUNsYXNzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCB7IGNsYXNzZXMgfSA9IHRoaXM7XG4gICAgICAgICAgICAgICAgaWYgKGNsYXNzZXMuaGFzKGtleSkpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGNsYXNzZXMuZ2V0KGtleSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICBjb25zdCBhdHRyaWJ1dGUgPSBjbGFzc2VzLmdldEF0dHJpYnV0ZU5hbWUoa2V5KTtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBNaXNzaW5nIGF0dHJpYnV0ZSBcIiR7YXR0cmlidXRlfVwiYCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2Ake2tleX1DbGFzc2VzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy5jbGFzc2VzLmdldEFsbChrZXkpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2BoYXMke2NhcGl0YWxpemUoa2V5KX1DbGFzc2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMuY2xhc3Nlcy5oYXMoa2V5KTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbn1cblxuZnVuY3Rpb24gT3V0bGV0UHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3Qgb3V0bGV0cyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBcIm91dGxldHNcIik7XG4gICAgcmV0dXJuIG91dGxldHMucmVkdWNlKChwcm9wZXJ0aWVzLCBvdXRsZXREZWZpbml0aW9uKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JPdXRsZXREZWZpbml0aW9uKG91dGxldERlZmluaXRpb24pKTtcbiAgICB9LCB7fSk7XG59XG5mdW5jdGlvbiBnZXRPdXRsZXRDb250cm9sbGVyKGNvbnRyb2xsZXIsIGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICByZXR1cm4gY29udHJvbGxlci5hcHBsaWNhdGlvbi5nZXRDb250cm9sbGVyRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcik7XG59XG5mdW5jdGlvbiBnZXRDb250cm9sbGVyQW5kRW5zdXJlQ29ubmVjdGVkU2NvcGUoY29udHJvbGxlciwgZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgIGxldCBvdXRsZXRDb250cm9sbGVyID0gZ2V0T3V0bGV0Q29udHJvbGxlcihjb250cm9sbGVyLCBlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICBpZiAob3V0bGV0Q29udHJvbGxlcilcbiAgICAgICAgcmV0dXJuIG91dGxldENvbnRyb2xsZXI7XG4gICAgY29udHJvbGxlci5hcHBsaWNhdGlvbi5yb3V0ZXIucHJvcG9zZVRvQ29ubmVjdFNjb3BlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgb3V0bGV0Q29udHJvbGxlciA9IGdldE91dGxldENvbnRyb2xsZXIoY29udHJvbGxlciwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgaWYgKG91dGxldENvbnRyb2xsZXIpXG4gICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xufVxuZnVuY3Rpb24gcHJvcGVydGllc0Zvck91dGxldERlZmluaXRpb24obmFtZSkge1xuICAgIGNvbnN0IGNhbWVsaXplZE5hbWUgPSBuYW1lc3BhY2VDYW1lbGl6ZShuYW1lKTtcbiAgICByZXR1cm4ge1xuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IG91dGxldEVsZW1lbnQgPSB0aGlzLm91dGxldHMuZmluZChuYW1lKTtcbiAgICAgICAgICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMub3V0bGV0cy5nZXRTZWxlY3RvckZvck91dGxldE5hbWUobmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG91dGxldEVsZW1lbnQpIHtcbiAgICAgICAgICAgICAgICAgICAgY29uc3Qgb3V0bGV0Q29udHJvbGxlciA9IGdldENvbnRyb2xsZXJBbmRFbnN1cmVDb25uZWN0ZWRTY29wZSh0aGlzLCBvdXRsZXRFbGVtZW50LCBuYW1lKTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKG91dGxldENvbnRyb2xsZXIpXG4gICAgICAgICAgICAgICAgICAgICAgICByZXR1cm4gb3V0bGV0Q29udHJvbGxlcjtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBUaGUgcHJvdmlkZWQgb3V0bGV0IGVsZW1lbnQgaXMgbWlzc2luZyBhbiBvdXRsZXQgY29udHJvbGxlciBcIiR7bmFtZX1cIiBpbnN0YW5jZSBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCJgKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBNaXNzaW5nIG91dGxldCBlbGVtZW50IFwiJHtuYW1lfVwiIGZvciBob3N0IGNvbnRyb2xsZXIgXCIke3RoaXMuaWRlbnRpZmllcn1cIi4gU3RpbXVsdXMgY291bGRuJ3QgZmluZCBhIG1hdGNoaW5nIG91dGxldCBlbGVtZW50IHVzaW5nIHNlbGVjdG9yIFwiJHtzZWxlY3Rvcn1cIi5gKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtjYW1lbGl6ZWROYW1lfU91dGxldHNgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IG91dGxldHMgPSB0aGlzLm91dGxldHMuZmluZEFsbChuYW1lKTtcbiAgICAgICAgICAgICAgICBpZiAob3V0bGV0cy5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRzXG4gICAgICAgICAgICAgICAgICAgICAgICAubWFwKChvdXRsZXRFbGVtZW50KSA9PiB7XG4gICAgICAgICAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRDb250cm9sbGVyID0gZ2V0Q29udHJvbGxlckFuZEVuc3VyZUNvbm5lY3RlZFNjb3BlKHRoaXMsIG91dGxldEVsZW1lbnQsIG5hbWUpO1xuICAgICAgICAgICAgICAgICAgICAgICAgaWYgKG91dGxldENvbnRyb2xsZXIpXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgcmV0dXJuIG91dGxldENvbnRyb2xsZXI7XG4gICAgICAgICAgICAgICAgICAgICAgICBjb25zb2xlLndhcm4oYFRoZSBwcm92aWRlZCBvdXRsZXQgZWxlbWVudCBpcyBtaXNzaW5nIGFuIG91dGxldCBjb250cm9sbGVyIFwiJHtuYW1lfVwiIGluc3RhbmNlIGZvciBob3N0IGNvbnRyb2xsZXIgXCIke3RoaXMuaWRlbnRpZmllcn1cImAsIG91dGxldEVsZW1lbnQpO1xuICAgICAgICAgICAgICAgICAgICB9KVxuICAgICAgICAgICAgICAgICAgICAgICAgLmZpbHRlcigoY29udHJvbGxlcikgPT4gY29udHJvbGxlcik7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHJldHVybiBbXTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtjYW1lbGl6ZWROYW1lfU91dGxldEVsZW1lbnRgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IG91dGxldEVsZW1lbnQgPSB0aGlzLm91dGxldHMuZmluZChuYW1lKTtcbiAgICAgICAgICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMub3V0bGV0cy5nZXRTZWxlY3RvckZvck91dGxldE5hbWUobmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG91dGxldEVsZW1lbnQpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIG91dGxldEVsZW1lbnQ7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICB0aHJvdyBuZXcgRXJyb3IoYE1pc3Npbmcgb3V0bGV0IGVsZW1lbnQgXCIke25hbWV9XCIgZm9yIGhvc3QgY29udHJvbGxlciBcIiR7dGhpcy5pZGVudGlmaWVyfVwiLiBTdGltdWx1cyBjb3VsZG4ndCBmaW5kIGEgbWF0Y2hpbmcgb3V0bGV0IGVsZW1lbnQgdXNpbmcgc2VsZWN0b3IgXCIke3NlbGVjdG9yfVwiLmApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtjYW1lbGl6ZWROYW1lfU91dGxldEVsZW1lbnRzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXRzLmZpbmRBbGwobmFtZSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYGhhcyR7Y2FwaXRhbGl6ZShjYW1lbGl6ZWROYW1lKX1PdXRsZXRgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLm91dGxldHMuaGFzKG5hbWUpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICB9O1xufVxuXG5mdW5jdGlvbiBUYXJnZXRQcm9wZXJ0aWVzQmxlc3NpbmcoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCB0YXJnZXRzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwidGFyZ2V0c1wiKTtcbiAgICByZXR1cm4gdGFyZ2V0cy5yZWR1Y2UoKHByb3BlcnRpZXMsIHRhcmdldERlZmluaXRpb24pID0+IHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24ocHJvcGVydGllcywgcHJvcGVydGllc0ZvclRhcmdldERlZmluaXRpb24odGFyZ2V0RGVmaW5pdGlvbikpO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JUYXJnZXREZWZpbml0aW9uKG5hbWUpIHtcbiAgICByZXR1cm4ge1xuICAgICAgICBbYCR7bmFtZX1UYXJnZXRgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IHRhcmdldCA9IHRoaXMudGFyZ2V0cy5maW5kKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmICh0YXJnZXQpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIHRhcmdldDtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyB0YXJnZXQgZWxlbWVudCBcIiR7bmFtZX1cIiBmb3IgXCIke3RoaXMuaWRlbnRpZmllcn1cIiBjb250cm9sbGVyYCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2Ake25hbWV9VGFyZ2V0c2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMudGFyZ2V0cy5maW5kQWxsKG5hbWUpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2BoYXMke2NhcGl0YWxpemUobmFtZSl9VGFyZ2V0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy50YXJnZXRzLmhhcyhuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbn1cblxuZnVuY3Rpb24gVmFsdWVQcm9wZXJ0aWVzQmxlc3NpbmcoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCB2YWx1ZURlZmluaXRpb25QYWlycyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBcInZhbHVlc1wiKTtcbiAgICBjb25zdCBwcm9wZXJ0eURlc2NyaXB0b3JNYXAgPSB7XG4gICAgICAgIHZhbHVlRGVzY3JpcHRvck1hcDoge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB2YWx1ZURlZmluaXRpb25QYWlycy5yZWR1Y2UoKHJlc3VsdCwgdmFsdWVEZWZpbml0aW9uUGFpcikgPT4ge1xuICAgICAgICAgICAgICAgICAgICBjb25zdCB2YWx1ZURlc2NyaXB0b3IgPSBwYXJzZVZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpciwgdGhpcy5pZGVudGlmaWVyKTtcbiAgICAgICAgICAgICAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuZGF0YS5nZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KHZhbHVlRGVzY3JpcHRvci5rZXkpO1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihyZXN1bHQsIHsgW2F0dHJpYnV0ZU5hbWVdOiB2YWx1ZURlc2NyaXB0b3IgfSk7XG4gICAgICAgICAgICAgICAgfSwge30pO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICB9O1xuICAgIHJldHVybiB2YWx1ZURlZmluaXRpb25QYWlycy5yZWR1Y2UoKHByb3BlcnRpZXMsIHZhbHVlRGVmaW5pdGlvblBhaXIpID0+IHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24ocHJvcGVydGllcywgcHJvcGVydGllc0ZvclZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpcikpO1xuICAgIH0sIHByb3BlcnR5RGVzY3JpcHRvck1hcCk7XG59XG5mdW5jdGlvbiBwcm9wZXJ0aWVzRm9yVmFsdWVEZWZpbml0aW9uUGFpcih2YWx1ZURlZmluaXRpb25QYWlyLCBjb250cm9sbGVyKSB7XG4gICAgY29uc3QgZGVmaW5pdGlvbiA9IHBhcnNlVmFsdWVEZWZpbml0aW9uUGFpcih2YWx1ZURlZmluaXRpb25QYWlyLCBjb250cm9sbGVyKTtcbiAgICBjb25zdCB7IGtleSwgbmFtZSwgcmVhZGVyOiByZWFkLCB3cml0ZXI6IHdyaXRlIH0gPSBkZWZpbml0aW9uO1xuICAgIHJldHVybiB7XG4gICAgICAgIFtuYW1lXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gdGhpcy5kYXRhLmdldChrZXkpO1xuICAgICAgICAgICAgICAgIGlmICh2YWx1ZSAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gcmVhZCh2YWx1ZSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gZGVmaW5pdGlvbi5kZWZhdWx0VmFsdWU7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIHNldCh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIGlmICh2YWx1ZSA9PT0gdW5kZWZpbmVkKSB7XG4gICAgICAgICAgICAgICAgICAgIHRoaXMuZGF0YS5kZWxldGUoa2V5KTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgICAgIHRoaXMuZGF0YS5zZXQoa2V5LCB3cml0ZSh2YWx1ZSkpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKG5hbWUpfWBdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMuZGF0YS5oYXMoa2V5KSB8fCBkZWZpbml0aW9uLmhhc0N1c3RvbURlZmF1bHRWYWx1ZTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVEZWZpbml0aW9uUGFpcihbdG9rZW4sIHR5cGVEZWZpbml0aW9uXSwgY29udHJvbGxlcikge1xuICAgIHJldHVybiB2YWx1ZURlc2NyaXB0b3JGb3JUb2tlbkFuZFR5cGVEZWZpbml0aW9uKHtcbiAgICAgICAgY29udHJvbGxlcixcbiAgICAgICAgdG9rZW4sXG4gICAgICAgIHR5cGVEZWZpbml0aW9uLFxuICAgIH0pO1xufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVDb25zdGFudChjb25zdGFudCkge1xuICAgIHN3aXRjaCAoY29uc3RhbnQpIHtcbiAgICAgICAgY2FzZSBBcnJheTpcbiAgICAgICAgICAgIHJldHVybiBcImFycmF5XCI7XG4gICAgICAgIGNhc2UgQm9vbGVhbjpcbiAgICAgICAgICAgIHJldHVybiBcImJvb2xlYW5cIjtcbiAgICAgICAgY2FzZSBOdW1iZXI6XG4gICAgICAgICAgICByZXR1cm4gXCJudW1iZXJcIjtcbiAgICAgICAgY2FzZSBPYmplY3Q6XG4gICAgICAgICAgICByZXR1cm4gXCJvYmplY3RcIjtcbiAgICAgICAgY2FzZSBTdHJpbmc6XG4gICAgICAgICAgICByZXR1cm4gXCJzdHJpbmdcIjtcbiAgICB9XG59XG5mdW5jdGlvbiBwYXJzZVZhbHVlVHlwZURlZmF1bHQoZGVmYXVsdFZhbHVlKSB7XG4gICAgc3dpdGNoICh0eXBlb2YgZGVmYXVsdFZhbHVlKSB7XG4gICAgICAgIGNhc2UgXCJib29sZWFuXCI6XG4gICAgICAgICAgICByZXR1cm4gXCJib29sZWFuXCI7XG4gICAgICAgIGNhc2UgXCJudW1iZXJcIjpcbiAgICAgICAgICAgIHJldHVybiBcIm51bWJlclwiO1xuICAgICAgICBjYXNlIFwic3RyaW5nXCI6XG4gICAgICAgICAgICByZXR1cm4gXCJzdHJpbmdcIjtcbiAgICB9XG4gICAgaWYgKEFycmF5LmlzQXJyYXkoZGVmYXVsdFZhbHVlKSlcbiAgICAgICAgcmV0dXJuIFwiYXJyYXlcIjtcbiAgICBpZiAoT2JqZWN0LnByb3RvdHlwZS50b1N0cmluZy5jYWxsKGRlZmF1bHRWYWx1ZSkgPT09IFwiW29iamVjdCBPYmplY3RdXCIpXG4gICAgICAgIHJldHVybiBcIm9iamVjdFwiO1xufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVPYmplY3QocGF5bG9hZCkge1xuICAgIGNvbnN0IHsgY29udHJvbGxlciwgdG9rZW4sIHR5cGVPYmplY3QgfSA9IHBheWxvYWQ7XG4gICAgY29uc3QgaGFzVHlwZSA9IGlzU29tZXRoaW5nKHR5cGVPYmplY3QudHlwZSk7XG4gICAgY29uc3QgaGFzRGVmYXVsdCA9IGlzU29tZXRoaW5nKHR5cGVPYmplY3QuZGVmYXVsdCk7XG4gICAgY29uc3QgZnVsbE9iamVjdCA9IGhhc1R5cGUgJiYgaGFzRGVmYXVsdDtcbiAgICBjb25zdCBvbmx5VHlwZSA9IGhhc1R5cGUgJiYgIWhhc0RlZmF1bHQ7XG4gICAgY29uc3Qgb25seURlZmF1bHQgPSAhaGFzVHlwZSAmJiBoYXNEZWZhdWx0O1xuICAgIGNvbnN0IHR5cGVGcm9tT2JqZWN0ID0gcGFyc2VWYWx1ZVR5cGVDb25zdGFudCh0eXBlT2JqZWN0LnR5cGUpO1xuICAgIGNvbnN0IHR5cGVGcm9tRGVmYXVsdFZhbHVlID0gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KHBheWxvYWQudHlwZU9iamVjdC5kZWZhdWx0KTtcbiAgICBpZiAob25seVR5cGUpXG4gICAgICAgIHJldHVybiB0eXBlRnJvbU9iamVjdDtcbiAgICBpZiAob25seURlZmF1bHQpXG4gICAgICAgIHJldHVybiB0eXBlRnJvbURlZmF1bHRWYWx1ZTtcbiAgICBpZiAodHlwZUZyb21PYmplY3QgIT09IHR5cGVGcm9tRGVmYXVsdFZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHByb3BlcnR5UGF0aCA9IGNvbnRyb2xsZXIgPyBgJHtjb250cm9sbGVyfS4ke3Rva2VufWAgOiB0b2tlbjtcbiAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBUaGUgc3BlY2lmaWVkIGRlZmF1bHQgdmFsdWUgZm9yIHRoZSBTdGltdWx1cyBWYWx1ZSBcIiR7cHJvcGVydHlQYXRofVwiIG11c3QgbWF0Y2ggdGhlIGRlZmluZWQgdHlwZSBcIiR7dHlwZUZyb21PYmplY3R9XCIuIFRoZSBwcm92aWRlZCBkZWZhdWx0IHZhbHVlIG9mIFwiJHt0eXBlT2JqZWN0LmRlZmF1bHR9XCIgaXMgb2YgdHlwZSBcIiR7dHlwZUZyb21EZWZhdWx0VmFsdWV9XCIuYCk7XG4gICAgfVxuICAgIGlmIChmdWxsT2JqZWN0KVxuICAgICAgICByZXR1cm4gdHlwZUZyb21PYmplY3Q7XG59XG5mdW5jdGlvbiBwYXJzZVZhbHVlVHlwZURlZmluaXRpb24ocGF5bG9hZCkge1xuICAgIGNvbnN0IHsgY29udHJvbGxlciwgdG9rZW4sIHR5cGVEZWZpbml0aW9uIH0gPSBwYXlsb2FkO1xuICAgIGNvbnN0IHR5cGVPYmplY3QgPSB7IGNvbnRyb2xsZXIsIHRva2VuLCB0eXBlT2JqZWN0OiB0eXBlRGVmaW5pdGlvbiB9O1xuICAgIGNvbnN0IHR5cGVGcm9tT2JqZWN0ID0gcGFyc2VWYWx1ZVR5cGVPYmplY3QodHlwZU9iamVjdCk7XG4gICAgY29uc3QgdHlwZUZyb21EZWZhdWx0VmFsdWUgPSBwYXJzZVZhbHVlVHlwZURlZmF1bHQodHlwZURlZmluaXRpb24pO1xuICAgIGNvbnN0IHR5cGVGcm9tQ29uc3RhbnQgPSBwYXJzZVZhbHVlVHlwZUNvbnN0YW50KHR5cGVEZWZpbml0aW9uKTtcbiAgICBjb25zdCB0eXBlID0gdHlwZUZyb21PYmplY3QgfHwgdHlwZUZyb21EZWZhdWx0VmFsdWUgfHwgdHlwZUZyb21Db25zdGFudDtcbiAgICBpZiAodHlwZSlcbiAgICAgICAgcmV0dXJuIHR5cGU7XG4gICAgY29uc3QgcHJvcGVydHlQYXRoID0gY29udHJvbGxlciA/IGAke2NvbnRyb2xsZXJ9LiR7dHlwZURlZmluaXRpb259YCA6IHRva2VuO1xuICAgIHRocm93IG5ldyBFcnJvcihgVW5rbm93biB2YWx1ZSB0eXBlIFwiJHtwcm9wZXJ0eVBhdGh9XCIgZm9yIFwiJHt0b2tlbn1cIiB2YWx1ZWApO1xufVxuZnVuY3Rpb24gZGVmYXVsdFZhbHVlRm9yRGVmaW5pdGlvbih0eXBlRGVmaW5pdGlvbikge1xuICAgIGNvbnN0IGNvbnN0YW50ID0gcGFyc2VWYWx1ZVR5cGVDb25zdGFudCh0eXBlRGVmaW5pdGlvbik7XG4gICAgaWYgKGNvbnN0YW50KVxuICAgICAgICByZXR1cm4gZGVmYXVsdFZhbHVlc0J5VHlwZVtjb25zdGFudF07XG4gICAgY29uc3QgaGFzRGVmYXVsdCA9IGhhc1Byb3BlcnR5KHR5cGVEZWZpbml0aW9uLCBcImRlZmF1bHRcIik7XG4gICAgY29uc3QgaGFzVHlwZSA9IGhhc1Byb3BlcnR5KHR5cGVEZWZpbml0aW9uLCBcInR5cGVcIik7XG4gICAgY29uc3QgdHlwZU9iamVjdCA9IHR5cGVEZWZpbml0aW9uO1xuICAgIGlmIChoYXNEZWZhdWx0KVxuICAgICAgICByZXR1cm4gdHlwZU9iamVjdC5kZWZhdWx0O1xuICAgIGlmIChoYXNUeXBlKSB7XG4gICAgICAgIGNvbnN0IHsgdHlwZSB9ID0gdHlwZU9iamVjdDtcbiAgICAgICAgY29uc3QgY29uc3RhbnRGcm9tVHlwZSA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZSk7XG4gICAgICAgIGlmIChjb25zdGFudEZyb21UeXBlKVxuICAgICAgICAgICAgcmV0dXJuIGRlZmF1bHRWYWx1ZXNCeVR5cGVbY29uc3RhbnRGcm9tVHlwZV07XG4gICAgfVxuICAgIHJldHVybiB0eXBlRGVmaW5pdGlvbjtcbn1cbmZ1bmN0aW9uIHZhbHVlRGVzY3JpcHRvckZvclRva2VuQW5kVHlwZURlZmluaXRpb24ocGF5bG9hZCkge1xuICAgIGNvbnN0IHsgdG9rZW4sIHR5cGVEZWZpbml0aW9uIH0gPSBwYXlsb2FkO1xuICAgIGNvbnN0IGtleSA9IGAke2Rhc2hlcml6ZSh0b2tlbil9LXZhbHVlYDtcbiAgICBjb25zdCB0eXBlID0gcGFyc2VWYWx1ZVR5cGVEZWZpbml0aW9uKHBheWxvYWQpO1xuICAgIHJldHVybiB7XG4gICAgICAgIHR5cGUsXG4gICAgICAgIGtleSxcbiAgICAgICAgbmFtZTogY2FtZWxpemUoa2V5KSxcbiAgICAgICAgZ2V0IGRlZmF1bHRWYWx1ZSgpIHtcbiAgICAgICAgICAgIHJldHVybiBkZWZhdWx0VmFsdWVGb3JEZWZpbml0aW9uKHR5cGVEZWZpbml0aW9uKTtcbiAgICAgICAgfSxcbiAgICAgICAgZ2V0IGhhc0N1c3RvbURlZmF1bHRWYWx1ZSgpIHtcbiAgICAgICAgICAgIHJldHVybiBwYXJzZVZhbHVlVHlwZURlZmF1bHQodHlwZURlZmluaXRpb24pICE9PSB1bmRlZmluZWQ7XG4gICAgICAgIH0sXG4gICAgICAgIHJlYWRlcjogcmVhZGVyc1t0eXBlXSxcbiAgICAgICAgd3JpdGVyOiB3cml0ZXJzW3R5cGVdIHx8IHdyaXRlcnMuZGVmYXVsdCxcbiAgICB9O1xufVxuY29uc3QgZGVmYXVsdFZhbHVlc0J5VHlwZSA9IHtcbiAgICBnZXQgYXJyYXkoKSB7XG4gICAgICAgIHJldHVybiBbXTtcbiAgICB9LFxuICAgIGJvb2xlYW46IGZhbHNlLFxuICAgIG51bWJlcjogMCxcbiAgICBnZXQgb2JqZWN0KCkge1xuICAgICAgICByZXR1cm4ge307XG4gICAgfSxcbiAgICBzdHJpbmc6IFwiXCIsXG59O1xuY29uc3QgcmVhZGVycyA9IHtcbiAgICBhcnJheSh2YWx1ZSkge1xuICAgICAgICBjb25zdCBhcnJheSA9IEpTT04ucGFyc2UodmFsdWUpO1xuICAgICAgICBpZiAoIUFycmF5LmlzQXJyYXkoYXJyYXkpKSB7XG4gICAgICAgICAgICB0aHJvdyBuZXcgVHlwZUVycm9yKGBleHBlY3RlZCB2YWx1ZSBvZiB0eXBlIFwiYXJyYXlcIiBidXQgaW5zdGVhZCBnb3QgdmFsdWUgXCIke3ZhbHVlfVwiIG9mIHR5cGUgXCIke3BhcnNlVmFsdWVUeXBlRGVmYXVsdChhcnJheSl9XCJgKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gYXJyYXk7XG4gICAgfSxcbiAgICBib29sZWFuKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiAhKHZhbHVlID09IFwiMFwiIHx8IFN0cmluZyh2YWx1ZSkudG9Mb3dlckNhc2UoKSA9PSBcImZhbHNlXCIpO1xuICAgIH0sXG4gICAgbnVtYmVyKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiBOdW1iZXIodmFsdWUucmVwbGFjZSgvXy9nLCBcIlwiKSk7XG4gICAgfSxcbiAgICBvYmplY3QodmFsdWUpIHtcbiAgICAgICAgY29uc3Qgb2JqZWN0ID0gSlNPTi5wYXJzZSh2YWx1ZSk7XG4gICAgICAgIGlmIChvYmplY3QgPT09IG51bGwgfHwgdHlwZW9mIG9iamVjdCAhPSBcIm9iamVjdFwiIHx8IEFycmF5LmlzQXJyYXkob2JqZWN0KSkge1xuICAgICAgICAgICAgdGhyb3cgbmV3IFR5cGVFcnJvcihgZXhwZWN0ZWQgdmFsdWUgb2YgdHlwZSBcIm9iamVjdFwiIGJ1dCBpbnN0ZWFkIGdvdCB2YWx1ZSBcIiR7dmFsdWV9XCIgb2YgdHlwZSBcIiR7cGFyc2VWYWx1ZVR5cGVEZWZhdWx0KG9iamVjdCl9XCJgKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gb2JqZWN0O1xuICAgIH0sXG4gICAgc3RyaW5nKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiB2YWx1ZTtcbiAgICB9LFxufTtcbmNvbnN0IHdyaXRlcnMgPSB7XG4gICAgZGVmYXVsdDogd3JpdGVTdHJpbmcsXG4gICAgYXJyYXk6IHdyaXRlSlNPTixcbiAgICBvYmplY3Q6IHdyaXRlSlNPTixcbn07XG5mdW5jdGlvbiB3cml0ZUpTT04odmFsdWUpIHtcbiAgICByZXR1cm4gSlNPTi5zdHJpbmdpZnkodmFsdWUpO1xufVxuZnVuY3Rpb24gd3JpdGVTdHJpbmcodmFsdWUpIHtcbiAgICByZXR1cm4gYCR7dmFsdWV9YDtcbn1cblxuY2xhc3MgQ29udHJvbGxlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCkge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgIH1cbiAgICBzdGF0aWMgZ2V0IHNob3VsZExvYWQoKSB7XG4gICAgICAgIHJldHVybiB0cnVlO1xuICAgIH1cbiAgICBzdGF0aWMgYWZ0ZXJMb2FkKF9pZGVudGlmaWVyLCBfYXBwbGljYXRpb24pIHtcbiAgICAgICAgcmV0dXJuO1xuICAgIH1cbiAgICBnZXQgYXBwbGljYXRpb24oKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuYXBwbGljYXRpb247XG4gICAgfVxuICAgIGdldCBzY29wZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY29wZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgdGFyZ2V0cygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUudGFyZ2V0cztcbiAgICB9XG4gICAgZ2V0IG91dGxldHMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLm91dGxldHM7XG4gICAgfVxuICAgIGdldCBjbGFzc2VzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5jbGFzc2VzO1xuICAgIH1cbiAgICBnZXQgZGF0YSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZGF0YTtcbiAgICB9XG4gICAgaW5pdGlhbGl6ZSgpIHtcbiAgICB9XG4gICAgY29ubmVjdCgpIHtcbiAgICB9XG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICB9XG4gICAgZGlzcGF0Y2goZXZlbnROYW1lLCB7IHRhcmdldCA9IHRoaXMuZWxlbWVudCwgZGV0YWlsID0ge30sIHByZWZpeCA9IHRoaXMuaWRlbnRpZmllciwgYnViYmxlcyA9IHRydWUsIGNhbmNlbGFibGUgPSB0cnVlLCB9ID0ge30pIHtcbiAgICAgICAgY29uc3QgdHlwZSA9IHByZWZpeCA/IGAke3ByZWZpeH06JHtldmVudE5hbWV9YCA6IGV2ZW50TmFtZTtcbiAgICAgICAgY29uc3QgZXZlbnQgPSBuZXcgQ3VzdG9tRXZlbnQodHlwZSwgeyBkZXRhaWwsIGJ1YmJsZXMsIGNhbmNlbGFibGUgfSk7XG4gICAgICAgIHRhcmdldC5kaXNwYXRjaEV2ZW50KGV2ZW50KTtcbiAgICAgICAgcmV0dXJuIGV2ZW50O1xuICAgIH1cbn1cbkNvbnRyb2xsZXIuYmxlc3NpbmdzID0gW1xuICAgIENsYXNzUHJvcGVydGllc0JsZXNzaW5nLFxuICAgIFRhcmdldFByb3BlcnRpZXNCbGVzc2luZyxcbiAgICBWYWx1ZVByb3BlcnRpZXNCbGVzc2luZyxcbiAgICBPdXRsZXRQcm9wZXJ0aWVzQmxlc3NpbmcsXG5dO1xuQ29udHJvbGxlci50YXJnZXRzID0gW107XG5Db250cm9sbGVyLm91dGxldHMgPSBbXTtcbkNvbnRyb2xsZXIudmFsdWVzID0ge307XG5cbmV4cG9ydCB7IEFwcGxpY2F0aW9uLCBBdHRyaWJ1dGVPYnNlcnZlciwgQ29udGV4dCwgQ29udHJvbGxlciwgRWxlbWVudE9ic2VydmVyLCBJbmRleGVkTXVsdGltYXAsIE11bHRpbWFwLCBTZWxlY3Rvck9ic2VydmVyLCBTdHJpbmdNYXBPYnNlcnZlciwgVG9rZW5MaXN0T2JzZXJ2ZXIsIFZhbHVlTGlzdE9ic2VydmVyLCBhZGQsIGRlZmF1bHRTY2hlbWEsIGRlbCwgZmV0Y2gsIHBydW5lIH07XG4iLCAiaW1wb3J0IHsgQ29udHJvbGxlciB9IGZyb20gXCJAaG90d2lyZWQvc3RpbXVsdXNcIlxuXG4vKipcbiAqIFN0aW11bHVzIENvbnRyb2xsZXIgZm9yIFN0cmlwZSBQYXltZW50IEVsZW1lbnQgb24gT3JkZXIgUGFnZVxuICpcbiAqIEhhbmRsZXMgU3RyaXBlIHBheW1lbnQgZm9ybSBpbml0aWFsaXphdGlvbiBhbmQgc3VibWlzc2lvbiBvbiB0aGUgb3JkZXIgY29uZmlybWF0aW9uIHBhZ2VcbiAqXG4gKiBVc2FnZSBpbiBUd2lnOlxuICogPGRpdiBkYXRhLWNvbnRyb2xsZXI9XCJzdHJpcGUtb3JkZXJcIlxuICogICAgICBkYXRhLXN0cmlwZS1vcmRlci1wdWJsaXNoYWJsZS1rZXktdmFsdWU9XCJwa18uLi5cIlxuICogICAgICBkYXRhLXN0cmlwZS1vcmRlci1jbGllbnQtc2VjcmV0LXZhbHVlPVwicGlfLi4uX3NlY3JldF8uLi5cIj5cbiAqICAgPGRpdiBpZD1cInBheW1lbnQtZWxlbWVudFwiPjwvZGl2PlxuICogICA8ZGl2IGlkPVwicGF5bWVudC1lcnJvcnNcIiBzdHlsZT1cImRpc3BsYXk6bm9uZVwiPlxuICogICAgIDxzcGFuIGRhdGEtc3RyaXBlLW9yZGVyLXRhcmdldD1cImVycm9yTWVzc2FnZVwiPjwvc3Bhbj5cbiAqICAgPC9kaXY+XG4gKiA8L2Rpdj5cbiAqL1xuZXhwb3J0IGRlZmF1bHQgY2xhc3MgZXh0ZW5kcyBDb250cm9sbGVyIHtcbiAgc3RhdGljIHZhbHVlcyA9IHtcbiAgICBwdWJsaXNoYWJsZUtleTogU3RyaW5nLFxuICAgIGNsaWVudFNlY3JldDogU3RyaW5nXG4gIH1cblxuICBzdGF0aWMgdGFyZ2V0cyA9IFtcImVycm9yTWVzc2FnZVwiLCBcImxvYWRpbmdcIl1cblxuICBjb25uZWN0KCkge1xuICAgIGNvbnNvbGUubG9nKCdTdHJpcGUgT3JkZXIgY29udHJvbGxlciBjb25uZWN0ZWQnLCB7XG4gICAgICBoYXNQdWJsaXNoYWJsZUtleTogISF0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUsXG4gICAgICBwdWJsaXNoYWJsZUtleTogdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlID8gdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlLnN1YnN0cmluZygwLCAxMCkgKyAnLi4uJyA6ICdtaXNzaW5nJyxcbiAgICB9KVxuXG4gICAgLy8gR2V0IGRlYnVnIGluZm8gZnJvbSBlbGVtZW50XG4gICAgY29uc3QgZGVidWdJbmZvID0gdGhpcy5lbGVtZW50LmdldEF0dHJpYnV0ZSgnZGF0YS1kZWJ1Zy1pbmZvJylcbiAgICBpZiAoZGVidWdJbmZvKSB7XG4gICAgICBjb25zb2xlLmxvZygnRGVidWcgaW5mbzonLCBkZWJ1Z0luZm8pXG4gICAgfVxuXG4gICAgLy8gVmFsaWRhdGUgcmVxdWlyZWQgY29uZmlndXJhdGlvblxuICAgIGlmICghdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdTdHJpcGUgcHVibGlzaGFibGUga2V5IG5vdCBjb25maWd1cmVkJylcbiAgICAgIHRoaXMuc2hvd0Vycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5DT05GSUdfRVJST1IgfHwgJ1N0cmlwZSBjb25maWd1cmF0aW9uIGVycm9yLiBQbGVhc2UgY29udGFjdCBzdXBwb3J0LicpXG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICAvLyBXYWl0IGZvciBTdHJpcGUuanMgdG8gbG9hZFxuICAgIHRoaXMuaW5pdGlhbGl6ZVN0cmlwZSgpXG4gIH1cblxuICBkaXNjb25uZWN0KCkge1xuICAgIC8vIENsZWFudXAgaWYgbmVlZGVkXG4gICAgaWYgKHRoaXMucGF5bWVudEVsZW1lbnQpIHtcbiAgICAgIHRoaXMucGF5bWVudEVsZW1lbnQudW5tb3VudCgpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEluaXRpYWxpemUgU3RyaXBlIGFuZCBtb3VudCBQYXltZW50IEVsZW1lbnRcbiAgICovXG4gIGFzeW5jIGluaXRpYWxpemVTdHJpcGUoKSB7XG4gICAgLy8gV2FpdCBmb3IgU3RyaXBlLmpzIHRvIGJlIGF2YWlsYWJsZVxuICAgIGlmICh0eXBlb2YgU3RyaXBlID09PSAndW5kZWZpbmVkJykge1xuICAgICAgY29uc29sZS5sb2coJ1dhaXRpbmcgZm9yIFN0cmlwZS5qcyB0byBsb2FkLi4uJylcbiAgICAgIGF3YWl0IHRoaXMud2FpdEZvclN0cmlwZSgpXG4gICAgfVxuXG4gICAgdHJ5IHtcbiAgICAgIC8vIEluaXRpYWxpemUgU3RyaXBlXG4gICAgICB0aGlzLnN0cmlwZSA9IFN0cmlwZSh0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUpXG5cbiAgICAgIC8vIENyZWF0ZSBFbGVtZW50cyB3aXRoIHN0eWxpbmdcbiAgICAgIGNvbnN0IGFwcGVhcmFuY2UgPSB7XG4gICAgICAgIHRoZW1lOiAnc3RyaXBlJyxcbiAgICAgICAgdmFyaWFibGVzOiB7XG4gICAgICAgICAgY29sb3JQcmltYXJ5OiAnIzA1NzBkZScsXG4gICAgICAgICAgY29sb3JCYWNrZ3JvdW5kOiAnI2ZmZmZmZicsXG4gICAgICAgICAgY29sb3JUZXh0OiAnIzMwMzEzZCcsXG4gICAgICAgICAgZm9udEZhbWlseTogJ3N5c3RlbS11aSwgc2Fucy1zZXJpZicsXG4gICAgICAgICAgYm9yZGVyUmFkaXVzOiAnNHB4J1xuICAgICAgICB9XG4gICAgICB9XG5cbiAgICAgIHRoaXMuZWxlbWVudHMgPSB0aGlzLnN0cmlwZS5lbGVtZW50cyh7XG4gICAgICAgIGFwcGVhcmFuY2U6IGFwcGVhcmFuY2VcbiAgICAgIH0pXG5cbiAgICAgIHRoaXMuY2FyZCA9IHRoaXMuZWxlbWVudHMuY3JlYXRlKCdjYXJkJyk7XG4gICAgICB0aGlzLmNhcmQubW91bnQoJyNjYXJkLWVsZW1lbnQnKTtcblxuICAgICAgY29uc29sZS5sb2coJ1N0cmlwZSBQYXltZW50IEVsZW1lbnQgaW5pdGlhbGl6ZWQgc3VjY2Vzc2Z1bGx5JylcblxuICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdGYWlsZWQgdG8gaW5pdGlhbGl6ZSBTdHJpcGU6JywgZXJyb3IpXG4gICAgICB0aGlzLnNob3dFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uSU5JVF9GQUlMRUQgfHwgJ0ZhaWxlZCB0byBpbml0aWFsaXplIHBheW1lbnQgZm9ybS4gUGxlYXNlIHJlZnJlc2ggdGhlIHBhZ2UuJylcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogV2FpdCBmb3IgU3RyaXBlLmpzIGxpYnJhcnkgdG8gbG9hZFxuICAgKiBAcmV0dXJucyB7UHJvbWlzZX1cbiAgICovXG4gIHdhaXRGb3JTdHJpcGUoKSB7XG4gICAgcmV0dXJuIG5ldyBQcm9taXNlKChyZXNvbHZlKSA9PiB7XG4gICAgICBjb25zdCBjaGVja1N0cmlwZSA9ICgpID0+IHtcbiAgICAgICAgaWYgKHR5cGVvZiBTdHJpcGUgIT09ICd1bmRlZmluZWQnKSB7XG4gICAgICAgICAgcmVzb2x2ZSgpXG4gICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgc2V0VGltZW91dChjaGVja1N0cmlwZSwgMTAwKVxuICAgICAgICB9XG4gICAgICB9XG4gICAgICBjaGVja1N0cmlwZSgpXG4gICAgfSlcbiAgfVxuXG4gIC8qKlxuICAgKiBTaG93IGxvYWRpbmcgaW5kaWNhdG9yXG4gICAqL1xuICBzaG93TG9hZGluZygpIHtcbiAgICBpZiAodGhpcy5oYXNMb2FkaW5nVGFyZ2V0KSB7XG4gICAgICB0aGlzLmxvYWRpbmdUYXJnZXQuc3R5bGUuZGlzcGxheSA9ICdibG9jaydcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogU2hvdyBlcnJvciBtZXNzYWdlXG4gICAqIEBwYXJhbSB7U3RyaW5nfSBtZXNzYWdlXG4gICAqL1xuICBzaG93RXJyb3IobWVzc2FnZSkge1xuICAgIGNvbnN0IGVycm9yRGl2ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ3BheW1lbnQtZXJyb3JzJylcbiAgICBpZiAoZXJyb3JEaXYgJiYgdGhpcy5oYXNFcnJvck1lc3NhZ2VUYXJnZXQpIHtcbiAgICAgIGVycm9yRGl2LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG4gICAgICB0aGlzLmVycm9yTWVzc2FnZVRhcmdldC50ZXh0Q29udGVudCA9IG1lc3NhZ2VcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSGlkZSBlcnJvciBtZXNzYWdlXG4gICAqL1xuICBoaWRlRXJyb3IoKSB7XG4gICAgY29uc3QgZXJyb3JEaXYgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgncGF5bWVudC1lcnJvcnMnKVxuICAgIGlmIChlcnJvckRpdikge1xuICAgICAgZXJyb3JEaXYuc3R5bGUuZGlzcGxheSA9ICdub25lJ1xuICAgICAgaWYgKHRoaXMuaGFzRXJyb3JNZXNzYWdlVGFyZ2V0KSB7XG4gICAgICAgIHRoaXMuZXJyb3JNZXNzYWdlVGFyZ2V0LnRleHRDb250ZW50ID0gJydcbiAgICAgIH1cbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSGlkZSBsb2FkaW5nIGluZGljYXRvclxuICAgKi9cbiAgaGlkZUxvYWRpbmcoKSB7XG4gICAgaWYgKHRoaXMuaGFzTG9hZGluZ1RhcmdldCkge1xuICAgICAgdGhpcy5sb2FkaW5nVGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnbm9uZSdcbiAgICB9XG4gIH1cblxufVxuIiwgImltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tIFwiQGhvdHdpcmVkL3N0aW11bHVzXCJcblxuLyoqXG4gKiBTdGltdWx1cyBDb250cm9sbGVyIGZvciBPcmRlciBTdWJtaXQgQnV0dG9uXG4gKlxuICogSGFuZGxlcyBvcmRlciBzdWJtaXNzaW9uIG9uIHRoZSBjaGVja291dCBvcmRlciBwYWdlLlxuICogU3VwcG9ydHMgdHdvIHBheW1lbnQgZmxvd3M6XG4gKiAxLiBTdHJpcGUgQ2hlY2tvdXQgKGhvc3RlZCBwYWdlKSAtIGZvciB3YWxsZXQgcGF5bWVudHNcbiAqIDIuIFBheW1lbnQgSW50ZW50IChjYXJkIGVsZW1lbnQpIC0gZm9yIGNhcmQgcGF5bWVudHNcbiAqXG4gKiBVc2FnZSBpbiBUd2lnOlxuICogPGJ1dHRvbiBkYXRhLWNvbnRyb2xsZXI9XCJvcmRlci1zdWJtaXRcIlxuICogICAgICAgICBkYXRhLWFjdGlvbj1cImNsaWNrLT5vcmRlci1zdWJtaXQjaGFuZGxlU3VibWl0XCJcbiAqICAgICAgICAgZGF0YS1vcmRlci1zdWJtaXQtdXJsLXZhbHVlPVwiLi4uXCJcbiAqICAgICAgICAgZGF0YS1vcmRlci1zdWJtaXQtcGF5bWVudC10eXBlLXZhbHVlPVwid2FsbGV0fGNhcmRcIlxuICogICAgICAgICB0eXBlPVwiYnV0dG9uXCI+XG4gKiAgIFN1Ym1pdCBPcmRlclxuICogPC9idXR0b24+XG4gKi9cbmV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgQ29udHJvbGxlciB7XG4gIHN0YXRpYyB0YXJnZXRzID0gW1wic3RhdHVzXCJdXG4gIHN0YXRpYyB2YWx1ZXMgPSB7XG4gICAgdXJsOiBTdHJpbmcsXG4gICAgcGF5bWVudFR5cGU6IFN0cmluZyxcbiAgICBwdWJsaXNoYWJsZUtleTogU3RyaW5nXG4gIH1cblxuICAvKipcbiAgICogQ2FsbGVkIHdoZW4gY29udHJvbGxlciBpcyBjb25uZWN0ZWQgdG8gRE9NXG4gICAqL1xuICBjb25uZWN0KCkge1xuICAgIGNvbnNvbGUubG9nKCdPcmRlciBTdWJtaXQgY29udHJvbGxlciBjb25uZWN0ZWQnKVxuICAgIGNvbnNvbGUubG9nKCdCdXR0b24gZWxlbWVudDonLCB0aGlzLmVsZW1lbnQpXG4gIH1cblxuICAvKipcbiAgICogQ2FsbGVkIHdoZW4gY29udHJvbGxlciBpcyBkaXNjb25uZWN0ZWQgZnJvbSBET01cbiAgICovXG4gIGRpc2Nvbm5lY3QoKSB7XG4gICAgY29uc29sZS5sb2coJ09yZGVyIFN1Ym1pdCBjb250cm9sbGVyIGRpc2Nvbm5lY3RlZCcpXG4gIH1cblxuICAvKipcbiAgICogR2V0IHRoZSBzdHJpcGUtb3JkZXIgY29udHJvbGxlciBpbnN0YW5jZVxuICAgKiBAcmV0dXJucyB7Q29udHJvbGxlcnxudWxsfVxuICAgKi9cbiAgZ2V0U3RyaXBlT3JkZXJDb250cm9sbGVyKCkge1xuICAgIGNvbnN0IGNhcmRFbGVtZW50ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ2NhcmQtZWxlbWVudCcpXG4gICAgaWYgKCFjYXJkRWxlbWVudCkge1xuICAgICAgY29uc29sZS5lcnJvcignQ2FyZCBlbGVtZW50IG5vdCBmb3VuZCcpXG4gICAgICByZXR1cm4gbnVsbFxuICAgIH1cblxuICAgIGNvbnN0IGNvbnRyb2xsZXIgPSB0aGlzLmFwcGxpY2F0aW9uLmdldENvbnRyb2xsZXJGb3JFbGVtZW50QW5kSWRlbnRpZmllcihcbiAgICAgIGNhcmRFbGVtZW50LFxuICAgICAgJ3N0cmlwZS1vcmRlcidcbiAgICApXG5cbiAgICBpZiAoIWNvbnRyb2xsZXIpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ1N0cmlwZSBvcmRlciBjb250cm9sbGVyIG5vdCBmb3VuZCBvbiBjYXJkIGVsZW1lbnQnKVxuICAgICAgcmV0dXJuIG51bGxcbiAgICB9XG5cbiAgICBjb25zb2xlLmxvZygnRm91bmQgc3RyaXBlLW9yZGVyIGNvbnRyb2xsZXI6JywgY29udHJvbGxlcilcbiAgICByZXR1cm4gY29udHJvbGxlclxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBvcmRlciBzdWJtaXQgYnV0dG9uIGNsaWNrXG4gICAqIFJvdXRlcyB0byBhcHByb3ByaWF0ZSBwYXltZW50IGZsb3cgYmFzZWQgb24gcGF5bWVudCB0eXBlXG4gICAqIEBwYXJhbSB7RXZlbnR9IGV2ZW50IC0gVGhlIGNsaWNrIGV2ZW50XG4gICAqL1xuICBhc3luYyBoYW5kbGVTdWJtaXQoZXZlbnQpIHtcbiAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpXG5cbiAgICBjb25zb2xlLmxvZygnT3JkZXIgc3VibWl0IGJ1dHRvbiBjbGlja2VkJywge1xuICAgICAgYnV0dG9uSWQ6IHRoaXMuZWxlbWVudC5pZCxcbiAgICAgIHBheW1lbnRUeXBlOiB0aGlzLnBheW1lbnRUeXBlVmFsdWUsXG4gICAgICB0aW1lc3RhbXA6IG5ldyBEYXRlKCkudG9JU09TdHJpbmcoKVxuICAgIH0pXG5cbiAgICB0aGlzLnNob3dMb2FkaW5nKClcblxuICAgIHRyeSB7XG4gICAgICAvLyBSb3V0ZSB0byBhcHByb3ByaWF0ZSBwYXltZW50IGZsb3dcbiAgICAgIGlmICh0aGlzLnBheW1lbnRUeXBlVmFsdWUgPT09ICd3YWxsZXQnKSB7XG4gICAgICAgIGF3YWl0IHRoaXMuaGFuZGxlU3RyaXBlQ2hlY2tvdXQoKVxuICAgICAgfSBlbHNlIHtcbiAgICAgICAgYXdhaXQgdGhpcy5oYW5kbGVQYXltZW50SW50ZW50KClcbiAgICAgIH1cbiAgICB9IGNhdGNoIChlcnJvcikge1xuICAgICAgY29uc29sZS5lcnJvcignT3JkZXIgc3VibWlzc2lvbiBmYWlsZWQnLCBlcnJvcilcbiAgICAgIHRoaXMuc2hvd0Vycm9yKGVycm9yLm1lc3NhZ2UgfHwgd2luZG93Lm9TdHJpcGU/LmkxOG4/LlBBWU1FTlRfRkFJTEVEIHx8ICdQYXltZW50IHByb2Nlc3NpbmcgZmFpbGVkJylcbiAgICB9IGZpbmFsbHkge1xuICAgICAgdGhpcy5oaWRlTG9hZGluZygpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBTdHJpcGUgQ2hlY2tvdXQgZmxvdyAoaG9zdGVkIHBheW1lbnQgcGFnZSlcbiAgICogVXNlZCBmb3Igd2FsbGV0IHBheW1lbnRzIChBcHBsZSBQYXksIEdvb2dsZSBQYXkpXG4gICAqL1xuICBhc3luYyBoYW5kbGVTdHJpcGVDaGVja291dCgpIHtcbiAgICBpZiAoIXdpbmRvdy5TdHJpcGUpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uSlNfTk9UX0xPQURFRCB8fCAnU3RyaXBlLmpzIG5vdCBsb2FkZWQnKVxuICAgIH1cblxuICAgIC8vIEdldCBTdHJpcGUgcHVibGlzaGFibGUga2V5IGZyb20gU3RpbXVsdXMgdmFsdWVcbiAgICBpZiAoIXRoaXMuaGFzUHVibGlzaGFibGVLZXlWYWx1ZSB8fCAhdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LktFWV9OT1RfQ09ORklHVVJFRCB8fCAnU3RyaXBlIHB1Ymxpc2hhYmxlIGtleSBub3QgY29uZmlndXJlZCcpXG4gICAgfVxuXG4gICAgY29uc3Qgc3RyaXBlID0gU3RyaXBlKHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSlcblxuICAgIHRoaXMuc2V0U3RhdHVzKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5DUkVBVElOR19TRVNTSU9OIHx8ICdDcmVhdGluZyBjaGVja291dCBzZXNzaW9uLi4uJylcblxuICAgIC8vIENyZWF0ZSBDaGVja291dCBTZXNzaW9uXG4gICAgY29uc3QgcmVzcG9uc2UgPSBhd2FpdCBmZXRjaCh0aGlzLnVybFZhbHVlLCB7XG4gICAgICBtZXRob2Q6ICdQT1NUJyxcbiAgICAgIGhlYWRlcnM6IHtcbiAgICAgICAgJ0NvbnRlbnQtVHlwZSc6ICdhcHBsaWNhdGlvbi9qc29uJ1xuICAgICAgfSxcbiAgICAgIGJvZHk6IEpTT04uc3RyaW5naWZ5KHtcbiAgICAgICAgY2FwdHVyZTogJ2F1dG9tYXRpYycgLy8gQ2FuIGJlIG1hZGUgY29uZmlndXJhYmxlXG4gICAgICB9KSxcbiAgICAgIGNyZWRlbnRpYWxzOiAnc2FtZS1vcmlnaW4nXG4gICAgfSlcblxuICAgIGlmICghcmVzcG9uc2Uub2spIHtcbiAgICAgIGNvbnN0IGVycm9yRGF0YSA9IGF3YWl0IHJlc3BvbnNlLmpzb24oKS5jYXRjaCgoKSA9PiAoe30pKVxuICAgICAgdGhyb3cgbmV3IEVycm9yKGVycm9yRGF0YS5lcnJvciB8fCB3aW5kb3cub1N0cmlwZT8uaTE4bj8uU0VTU0lPTl9GQUlMRUQgfHwgJ0ZhaWxlZCB0byBjcmVhdGUgY2hlY2tvdXQgc2Vzc2lvbicpXG4gICAgfVxuXG4gICAgY29uc3QgZGF0YSA9IGF3YWl0IHJlc3BvbnNlLmpzb24oKVxuXG4gICAgaWYgKCFkYXRhLmlkKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LlNFU1NJT05fSU5WQUxJRCB8fCAnSW52YWxpZCBjaGVja291dCBzZXNzaW9uIHJlc3BvbnNlJylcbiAgICB9XG5cbiAgICBjb25zb2xlLmxvZygnQ2hlY2tvdXQgU2Vzc2lvbiBjcmVhdGVkOicsIGRhdGEuaWQsICdVUkw6JywgZGF0YS51cmwpXG4gICAgY29uc29sZS5sb2coJ0RlYnVnIGluZm86JywgZGF0YS5fZGVidWcpXG5cbiAgICAvLyBSZWRpcmVjdCB0byBTdHJpcGUgQ2hlY2tvdXQgdXNpbmcgZGlyZWN0IFVSTCAobW9yZSByZWxpYWJsZSlcbiAgICBpZiAoZGF0YS51cmwpIHtcbiAgICAgIHdpbmRvdy5sb2NhdGlvbi5ocmVmID0gZGF0YS51cmxcbiAgICAgIHJldHVyblxuICAgIH1cblxuICAgIC8vIEZhbGxiYWNrIHRvIHJlZGlyZWN0VG9DaGVja291dCBpZiBVUkwgbm90IGF2YWlsYWJsZVxuICAgIGNvbnN0IHsgZXJyb3IgfSA9IGF3YWl0IHN0cmlwZS5yZWRpcmVjdFRvQ2hlY2tvdXQoe1xuICAgICAgc2Vzc2lvbklkOiBkYXRhLmlkXG4gICAgfSlcblxuICAgIGlmIChlcnJvcikge1xuICAgICAgdGhyb3cgZXJyb3JcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIFBheW1lbnQgSW50ZW50IGZsb3cgKGNhcmQgZWxlbWVudClcbiAgICogVXNlZCBmb3IgY2FyZCBwYXltZW50c1xuICAgKi9cbiAgYXN5bmMgaGFuZGxlUGF5bWVudEludGVudCgpIHtcbiAgICAvLyBHZXQgc3RyaXBlLW9yZGVyIGNvbnRyb2xsZXIgaW5zdGFuY2VcbiAgICBjb25zdCBzdHJpcGVPcmRlckNvbnRyb2xsZXIgPSB0aGlzLmdldFN0cmlwZU9yZGVyQ29udHJvbGxlcigpXG5cbiAgICBpZiAoIXN0cmlwZU9yZGVyQ29udHJvbGxlcikge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5DT05UUk9MTEVSX05PVF9GT1VORCB8fCAnU3RyaXBlIHBheW1lbnQgY29udHJvbGxlciBub3QgZm91bmQuIFBsZWFzZSByZWZyZXNoIHRoZSBwYWdlLicpXG4gICAgfVxuXG4gICAgLy8gVmVyaWZ5IGNhcmQgZWxlbWVudCBhbmQgc3RyaXBlIGFyZSBhdmFpbGFibGVcbiAgICBpZiAoIXN0cmlwZU9yZGVyQ29udHJvbGxlci5jYXJkIHx8ICFzdHJpcGVPcmRlckNvbnRyb2xsZXIuc3RyaXBlKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdQYXltZW50IGZvcm0gbm90IHJlYWR5OicsIHtcbiAgICAgICAgaGFzQ2FyZDogISFzdHJpcGVPcmRlckNvbnRyb2xsZXIuY2FyZCxcbiAgICAgICAgaGFzU3RyaXBlOiAhIXN0cmlwZU9yZGVyQ29udHJvbGxlci5zdHJpcGVcbiAgICAgIH0pXG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LkZPUk1fTk9UX1JFQURZIHx8ICdQYXltZW50IGZvcm0gbm90IGluaXRpYWxpemVkLiBQbGVhc2UgcmVmcmVzaCB0aGUgcGFnZS4nKVxuICAgIH1cblxuICAgIGNvbnNvbGUubG9nKCdTdHJpcGUgY29udHJvbGxlciByZWFkeTonLCB7XG4gICAgICBoYXNDYXJkOiAhIXN0cmlwZU9yZGVyQ29udHJvbGxlci5jYXJkLFxuICAgICAgaGFzU3RyaXBlOiAhIXN0cmlwZU9yZGVyQ29udHJvbGxlci5zdHJpcGVcbiAgICB9KVxuXG4gICAgY29uc3QgcGF5bWVudEludGVudFJlc3BvbnNlID0gYXdhaXQgdGhpcy5oYW5kbGVQYXltZW50KClcbiAgICBjb25zdCBjbGllbnRTZWNyZXQgPSBwYXltZW50SW50ZW50UmVzcG9uc2UuY2xpZW50U2VjcmV0XG5cbiAgICBjb25zdCBjb25maXJtUGF5bWVudFJlc3BvbnNlID0gYXdhaXQgc3RyaXBlT3JkZXJDb250cm9sbGVyLnN0cmlwZS5jb25maXJtQ2FyZFBheW1lbnQoY2xpZW50U2VjcmV0LCB7XG4gICAgICBwYXltZW50X21ldGhvZDoge1xuICAgICAgICBjYXJkOiBzdHJpcGVPcmRlckNvbnRyb2xsZXIuY2FyZFxuICAgICAgfVxuICAgIH0pO1xuXG4gICAgaWYgKGNvbmZpcm1QYXltZW50UmVzcG9uc2UuZXJyb3IpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcihjb25maXJtUGF5bWVudFJlc3BvbnNlLmVycm9yLm1lc3NhZ2UpXG4gICAgfSBlbHNlIGlmIChjb25maXJtUGF5bWVudFJlc3BvbnNlLnBheW1lbnRJbnRlbnQgJiYgY29uZmlybVBheW1lbnRSZXNwb25zZS5wYXltZW50SW50ZW50LnN0YXR1cyA9PT0gJ3N1Y2NlZWRlZCcpIHtcbiAgICAgIGNvbnNvbGUubG9nKCdQYXltZW50IHN1Y2NlZWRlZCcsIGNvbmZpcm1QYXltZW50UmVzcG9uc2UucGF5bWVudEludGVudClcbiAgICAgIC8vIFRPRE86IFN1Ym1pdCBmaW5hbCBvcmRlciB0byBiYWNrZW5kXG4gICAgfSBlbHNlIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uUEFZTUVOVF9OT1RfQ09NUExFVEVEIHx8ICdQYXltZW50IG5vdCBjb21wbGV0ZWQnKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBGZXRjaCBwYXltZW50IGludGVudCBjcmVhdGlvbiBVUkwgYW5kIHJldHVybiByZXNwb25zZVxuICAgKiBAcmV0dXJucyB7UHJvbWlzZTxPYmplY3Q+fSBQYXltZW50IGludGVudCByZXNwb25zZSB3aXRoIGNsaWVudFNlY3JldCwgYW1vdW50LCBjdXJyZW5jeVxuICAgKiBAdGhyb3dzIHtFcnJvcn0gSWYgZmV0Y2ggZmFpbHMgb3IgcmVzcG9uc2UgaXMgbm90IG9rXG4gICAqL1xuICBhc3luYyBoYW5kbGVQYXltZW50KCkge1xuICAgIGlmICghdGhpcy5oYXNVcmxWYWx1ZSkge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5VUkxfTk9UX0NPTkZJR1VSRUQgfHwgJ1BheW1lbnQgVVJMIGlzIG5vdCBjb25maWd1cmVkJylcbiAgICB9XG5cbiAgICBjb25zb2xlLmxvZygnQ3JlYXRpbmcgcGF5bWVudCBpbnRlbnQgdmlhIFVSTDonLCB0aGlzLnVybFZhbHVlKVxuXG4gICAgY29uc3QgcmVzcG9uc2UgPSBhd2FpdCBmZXRjaCh0aGlzLnVybFZhbHVlLCB7XG4gICAgICBtZXRob2Q6ICdQT1NUJyxcbiAgICAgIGhlYWRlcnM6IHtcbiAgICAgICAgJ0NvbnRlbnQtVHlwZSc6ICdhcHBsaWNhdGlvbi9qc29uJ1xuICAgICAgfSxcbiAgICAgIGNyZWRlbnRpYWxzOiAnc2FtZS1vcmlnaW4nXG4gICAgfSlcblxuICAgIGlmICghcmVzcG9uc2Uub2spIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcihgSFRUUCBlcnJvciEgc3RhdHVzOiAke3Jlc3BvbnNlLnN0YXR1c31gKVxuICAgIH1cblxuICAgIGNvbnN0IHJlc3BvbnNlRGF0YSA9IGF3YWl0IHJlc3BvbnNlLmpzb24oKVxuXG4gICAgaWYgKHJlc3BvbnNlRGF0YS5lcnJvcikge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHJlc3BvbnNlRGF0YS5lcnJvcilcbiAgICB9XG5cbiAgICBpZiAoIXJlc3BvbnNlRGF0YS5zdWNjZXNzIHx8ICFyZXNwb25zZURhdGEuY2xpZW50U2VjcmV0KSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LklOVEVOVF9JTlZBTElEIHx8ICdJbnZhbGlkIHBheW1lbnQgaW50ZW50IHJlc3BvbnNlJylcbiAgICB9XG5cbiAgICByZXR1cm4gcmVzcG9uc2VEYXRhXG4gIH1cblxuICAvKipcbiAgICogU2hvdyBsb2FkaW5nIHN0YXRlIG9uIGJ1dHRvblxuICAgKi9cbiAgc2hvd0xvYWRpbmcoKSB7XG4gICAgdGhpcy5lbGVtZW50LmRpc2FibGVkID0gdHJ1ZVxuICAgIHRoaXMub3JpZ2luYWxUZXh0ID0gdGhpcy5lbGVtZW50LnRleHRDb250ZW50XG4gICAgdGhpcy5lbGVtZW50LnRleHRDb250ZW50ID0gd2luZG93Lm9TdHJpcGU/LmkxOG4/LlBST0NFU1NJTkcgfHwgJ1Byb2Nlc3NpbmcuLi4nXG4gIH1cblxuICAvKipcbiAgICogSGlkZSBsb2FkaW5nIHN0YXRlIG9uIGJ1dHRvblxuICAgKi9cbiAgaGlkZUxvYWRpbmcoKSB7XG4gICAgdGhpcy5lbGVtZW50LmRpc2FibGVkID0gZmFsc2VcbiAgICBpZiAodGhpcy5vcmlnaW5hbFRleHQpIHtcbiAgICAgIHRoaXMuZWxlbWVudC50ZXh0Q29udGVudCA9IHRoaXMub3JpZ2luYWxUZXh0XG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFNldCBzdGF0dXMgbWVzc2FnZVxuICAgKiBAcGFyYW0ge3N0cmluZ30gbWVzc2FnZSAtIFN0YXR1cyBtZXNzYWdlIHRvIGRpc3BsYXlcbiAgICovXG4gIHNldFN0YXR1cyhtZXNzYWdlKSB7XG4gICAgaWYgKHRoaXMuaGFzU3RhdHVzVGFyZ2V0KSB7XG4gICAgICB0aGlzLnN0YXR1c1RhcmdldC50ZXh0Q29udGVudCA9IG1lc3NhZ2VcbiAgICAgIHRoaXMuc3RhdHVzVGFyZ2V0LmNsYXNzTmFtZSA9ICdtdC0yIHRleHQtY2VudGVyIHRleHQtbXV0ZWQnXG4gICAgfVxuICAgIGNvbnNvbGUubG9nKCdTdGF0dXM6JywgbWVzc2FnZSlcbiAgfVxuXG4gIC8qKlxuICAgKiBTaG93IGVycm9yIG1lc3NhZ2VcbiAgICogQHBhcmFtIHtzdHJpbmd9IG1lc3NhZ2UgLSBFcnJvciBtZXNzYWdlIHRvIGRpc3BsYXlcbiAgICovXG4gIHNob3dFcnJvcihtZXNzYWdlKSB7XG4gICAgaWYgKHRoaXMuaGFzU3RhdHVzVGFyZ2V0KSB7XG4gICAgICB0aGlzLnN0YXR1c1RhcmdldC50ZXh0Q29udGVudCA9IG1lc3NhZ2VcbiAgICAgIHRoaXMuc3RhdHVzVGFyZ2V0LmNsYXNzTmFtZSA9ICdtdC0yIHRleHQtY2VudGVyIHRleHQtZGFuZ2VyJ1xuICAgIH0gZWxzZSB7XG4gICAgICBhbGVydCgnRXJyb3I6ICcgKyBtZXNzYWdlKVxuICAgIH1cbiAgfVxufVxuIiwgImltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tICdAaG90d2lyZWQvc3RpbXVsdXMnXG5cbi8qKlxuICogU3RpbXVsdXMgY29udHJvbGxlciBmb3IgQUdCIChUZXJtcyBhbmQgQ29uZGl0aW9ucykgY2hlY2tib3ggdmFsaWRhdGlvblxuICpcbiAqIFRoaXMgY29udHJvbGxlciBoYW5kbGVzIHRoZSB2YWxpZGF0aW9uIG9mIHRoZSBBR0IgY2hlY2tib3ggb24gdGhlIG9yZGVyIHBhZ2UuXG4gKiBXaGVuIGJsQ29uZmlybUFHQiBpcyBlbmFibGVkLCBpdCBwcmV2ZW50cyBvcmRlciBzdWJtaXNzaW9uIHVudGlsIHRoZSBjaGVja2JveCBpcyBjaGVja2VkLlxuICpcbiAqIFVzYWdlIGluIHRlbXBsYXRlOlxuICogPGRpdiBkYXRhLWNvbnRyb2xsZXI9XCJhZ2ItdmFsaWRhdGlvblwiIGRhdGEtYWdiLXZhbGlkYXRpb24tZW5hYmxlZC12YWx1ZT1cInRydWVcIj5cbiAqICAgPGlucHV0IHR5cGU9XCJjaGVja2JveFwiIGRhdGEtYWdiLXZhbGlkYXRpb24tdGFyZ2V0PVwiY2hlY2tib3hcIiAvPlxuICogICA8YnV0dG9uIGRhdGEtYWdiLXZhbGlkYXRpb24tdGFyZ2V0PVwic3VibWl0QnV0dG9uXCI+T3JkZXI8L2J1dHRvbj5cbiAqIDwvZGl2PlxuICovXG5leHBvcnQgZGVmYXVsdCBjbGFzcyBleHRlbmRzIENvbnRyb2xsZXIge1xuICBzdGF0aWMgdGFyZ2V0cyA9IFsnY2hlY2tib3gnLCAnc3VibWl0QnV0dG9uJ11cbiAgc3RhdGljIHZhbHVlcyA9IHtcbiAgICBlbmFibGVkOiBCb29sZWFuXG4gIH1cblxuICAvKipcbiAgICogSW5pdGlhbGl6ZSB0aGUgY29udHJvbGxlciB3aGVuIGNvbm5lY3RlZCB0byB0aGUgRE9NXG4gICAqL1xuICBjb25uZWN0KCkge1xuICAgIGNvbnNvbGUubG9nKCdBR0IgVmFsaWRhdGlvbiBjb250cm9sbGVyIGNvbm5lY3RlZCcsIHtcbiAgICAgIGVuYWJsZWQ6IHRoaXMuZW5hYmxlZFZhbHVlLFxuICAgICAgaGFzQ2hlY2tib3g6IHRoaXMuaGFzQ2hlY2tib3hUYXJnZXQsXG4gICAgICBoYXNTdWJtaXRCdXR0b25zOiB0aGlzLmhhc1N1Ym1pdEJ1dHRvblRhcmdldFxuICAgIH0pXG5cbiAgICAvLyBPbmx5IGFwcGx5IHZhbGlkYXRpb24gaWYgYmxDb25maXJtQUdCIGlzIGVuYWJsZWRcbiAgICBpZiAodGhpcy5lbmFibGVkVmFsdWUpIHtcbiAgICAgIHRoaXMudXBkYXRlQnV0dG9uU3RhdGVzKClcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIGNoZWNrYm94IHN0YXRlIGNoYW5nZXNcbiAgICovXG4gIGNoZWNrYm94Q2hhbmdlZCgpIHtcbiAgICBpZiAodGhpcy5lbmFibGVkVmFsdWUpIHtcbiAgICAgIHRoaXMudXBkYXRlQnV0dG9uU3RhdGVzKClcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogVXBkYXRlIHRoZSBkaXNhYmxlZCBzdGF0ZSBvZiBhbGwgc3VibWl0IGJ1dHRvbnMgYmFzZWQgb24gY2hlY2tib3ggc3RhdGVcbiAgICovXG4gIHVwZGF0ZUJ1dHRvblN0YXRlcygpIHtcbiAgICBpZiAoIXRoaXMuaGFzQ2hlY2tib3hUYXJnZXQgfHwgIXRoaXMuaGFzU3VibWl0QnV0dG9uVGFyZ2V0KSB7XG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICBjb25zdCBpc0NoZWNrZWQgPSB0aGlzLmNoZWNrYm94VGFyZ2V0LmNoZWNrZWRcblxuICAgIC8vIFVwZGF0ZSBhbGwgc3VibWl0IGJ1dHRvbnNcbiAgICB0aGlzLnN1Ym1pdEJ1dHRvblRhcmdldHMuZm9yRWFjaChidXR0b24gPT4ge1xuICAgICAgYnV0dG9uLmRpc2FibGVkID0gIWlzQ2hlY2tlZFxuXG4gICAgICAvLyBBZGQgdmlzdWFsIGZlZWRiYWNrXG4gICAgICBpZiAoaXNDaGVja2VkKSB7XG4gICAgICAgIGJ1dHRvbi5jbGFzc0xpc3QucmVtb3ZlKCdkaXNhYmxlZCcpXG4gICAgICAgIGJ1dHRvbi5yZW1vdmVBdHRyaWJ1dGUoJ3RpdGxlJylcbiAgICAgIH0gZWxzZSB7XG4gICAgICAgIGJ1dHRvbi5jbGFzc0xpc3QuYWRkKCdkaXNhYmxlZCcpXG4gICAgICAgIGJ1dHRvbi5zZXRBdHRyaWJ1dGUoJ3RpdGxlJywgd2luZG93Lm9TdHJpcGU/LmkxOG4/LkFHQl9SRVFVSVJFRCB8fCAnUGxlYXNlIGFjY2VwdCB0aGUgdGVybXMgYW5kIGNvbmRpdGlvbnMnKVxuICAgICAgfVxuICAgIH0pXG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIGZvcm0gc3VibWlzc2lvbiBhdHRlbXB0c1xuICAgKiBAcGFyYW0ge0V2ZW50fSBldmVudCAtIFRoZSBzdWJtaXQgZXZlbnRcbiAgICovXG4gIGhhbmRsZVN1Ym1pdChldmVudCkge1xuICAgIGlmICghdGhpcy5lbmFibGVkVmFsdWUpIHtcbiAgICAgIHJldHVybiB0cnVlXG4gICAgfVxuXG4gICAgaWYgKCF0aGlzLmhhc0NoZWNrYm94VGFyZ2V0IHx8ICF0aGlzLmNoZWNrYm94VGFyZ2V0LmNoZWNrZWQpIHtcbiAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KClcbiAgICAgIGV2ZW50LnN0b3BQcm9wYWdhdGlvbigpXG5cbiAgICAgIC8vIFNob3cgdmlzdWFsIGZlZWRiYWNrXG4gICAgICBpZiAodGhpcy5oYXNDaGVja2JveFRhcmdldCkge1xuICAgICAgICBjb25zdCBjaGVja2JveFdyYXBwZXIgPSB0aGlzLmNoZWNrYm94VGFyZ2V0LmNsb3Nlc3QoJy5mb3JtLWNoZWNrJylcbiAgICAgICAgaWYgKGNoZWNrYm94V3JhcHBlcikge1xuICAgICAgICAgIGNoZWNrYm94V3JhcHBlci5jbGFzc0xpc3QuYWRkKCdib3JkZXInLCAnYm9yZGVyLWRhbmdlcicsICdwLTInLCAncm91bmRlZCcpXG5cbiAgICAgICAgICAvLyBSZW1vdmUgdGhlIGhpZ2hsaWdodCBhZnRlciAzIHNlY29uZHNcbiAgICAgICAgICBzZXRUaW1lb3V0KCgpID0+IHtcbiAgICAgICAgICAgIGNoZWNrYm94V3JhcHBlci5jbGFzc0xpc3QucmVtb3ZlKCdib3JkZXInLCAnYm9yZGVyLWRhbmdlcicsICdwLTInLCAncm91bmRlZCcpXG4gICAgICAgICAgfSwgMzAwMClcbiAgICAgICAgfVxuICAgICAgfVxuXG4gICAgICByZXR1cm4gZmFsc2VcbiAgICB9XG5cbiAgICByZXR1cm4gdHJ1ZVxuICB9XG59XG4iLCAiLyoqXG4gKiBFdmVudEJ1cyAtIENlbnRyYWxuYSBzenluYSBldmVudG93YSBkbGEgYXBsaWthY2ppIE9uZS1QYWdlIENoZWNrb3V0XG4gKlxuICogUHJvYmxlbTpcbiAqIEtvbnRyb2xlcnkgZGlzcGF0Y2h1alx1MDEwNSBldmVudHkgbmEgclx1MDBGM1x1MDE3Q255Y2ggdGFyZ2V0YWNoIChkb2N1bWVudCwgdGhpcy5lbGVtZW50LCB3aW5kb3cpLFxuICogY28gcG93b2R1amUgcHJvYmxlbXkgeiB0aW1pbmcnaWVtIGkgdHJ1ZG5vXHUwMTVCY2kgdyB0ZXN0b3dhbml1LlxuICpcbiAqIFJvendpXHUwMTA1emFuaWU6XG4gKiBTaW5nbGV0b24gRXZlbnRCdXMgemFwZXduaWEgamVkZW4gY2VudHJhbG55IHB1bmt0IGtvbXVuaWthY2ppLlxuICogV3N6eXN0a2llIGV2ZW50eSBwcnplY2hvZHpcdTAxMDUgcHJ6ZXogdFx1MDExOSBzenluXHUwMTE5LCBjbyB1XHUwMTQyYXR3aWE6XG4gKiAtIERlYnVnb3dhbmllICh3c3p5c3RraWUgZXZlbnR5IHcgamVkbnltIG1pZWpzY3UpXG4gKiAtIFRlc3Rvd2FuaWUgKG1vXHUwMTdDbmEgbW9ja293YVx1MDEwNyBFdmVudEJ1cylcbiAqIC0gS29udHJvbFx1MDExOSAobW9cdTAxN0NuYSBsb2dvd2FcdTAxMDcsIGZpbHRyb3dhXHUwMTA3LCB0cmFuc2Zvcm1vd2FcdTAxMDcgZXZlbnR5KVxuICpcbiAqIFVcdTAxN0N5Y2llIHcga29udHJvbGVyYWNoOlxuICpcbiAqIGltcG9ydCB7IGV2ZW50QnVzIH0gZnJvbSAnLi4vdXRpbHMvZXZlbnRfYnVzLmpzJ1xuICpcbiAqIC8vIE5hc1x1MDE0MnVjaGl3YW5pZVxuICogZXZlbnRCdXMub24oJ29lOmJhc2tldDp1cGRhdGVkJywgKGRhdGEpID0+IHtcbiAqICAgY29uc29sZS5sb2coJ0Jhc2tldCB1cGRhdGVkOicsIGRhdGEpXG4gKiB9KVxuICpcbiAqIC8vIEVtaXNqYVxuICogZXZlbnRCdXMuZW1pdCgnb2U6YmFza2V0OnVwZGF0ZWQnLCB7IGl0ZW1zOiBbLi4uXSwgdG90YWw6IDEwMCB9KVxuICpcbiAqIC8vIEplZG5vcmF6b3dlIG5hc1x1MDE0MnVjaGl3YW5pZVxuICogZXZlbnRCdXMub25jZSgnb2U6Y2hlY2tvdXQ6Y29tcGxldGUnLCAoZGF0YSkgPT4ge1xuICogICBjb25zb2xlLmxvZygnQ2hlY2tvdXQgY29tcGxldGU6JywgZGF0YSlcbiAqIH0pXG4gKlxuICogLy8gVXN1bmlcdTAxMTljaWUgbGlzdGVuZXJhICh3YVx1MDE3Q25lIGRsYSBjbGVhbnVwISlcbiAqIGNvbnN0IGhhbmRsZXIgPSAoZGF0YSkgPT4gY29uc29sZS5sb2coZGF0YSlcbiAqIGV2ZW50QnVzLm9uKCdldmVudCcsIGhhbmRsZXIpXG4gKiBldmVudEJ1cy5vZmYoJ2V2ZW50JywgaGFuZGxlcilcbiAqL1xuXG5jbGFzcyBFdmVudEJ1cyB7XG4gIGNvbnN0cnVjdG9yKCkge1xuICAgIC8vIFNpbmdsZXRvbiBwYXR0ZXJuXG4gICAgaWYgKEV2ZW50QnVzLmluc3RhbmNlKSB7XG4gICAgICByZXR1cm4gRXZlbnRCdXMuaW5zdGFuY2VcbiAgICB9XG5cbiAgICB0aGlzLmxpc3RlbmVycyA9IG5ldyBNYXAoKSAvLyBldmVudE5hbWUgLT4gU2V0IG9mIGhhbmRsZXJzXG4gICAgdGhpcy5kZWJ1ZyA9IGZhbHNlXG4gICAgdGhpcy5ldmVudEhpc3RvcnkgPSBbXSAvLyBGb3IgZGVidWdnaW5nXG4gICAgdGhpcy5tYXhIaXN0b3J5U2l6ZSA9IDEwMFxuXG4gICAgRXZlbnRCdXMuaW5zdGFuY2UgPSB0aGlzXG4gIH1cblxuICAvKipcbiAgICogV1x1MDE0Mlx1MDEwNWN6L3d5XHUwMTQyXHUwMTA1Y3ogdHJ5YiBkZWJ1Z1xuICAgKi9cbiAgc2V0RGVidWcoZW5hYmxlZCkge1xuICAgIHRoaXMuZGVidWcgPSBlbmFibGVkXG4gIH1cblxuICAvKipcbiAgICogWmFyZWplc3RydWogbGlzdGVuZXIgZGxhIGV2ZW50dVxuICAgKiBAcGFyYW0ge3N0cmluZ30gZXZlbnROYW1lIC0gTmF6d2EgZXZlbnR1IChucC4gJ29lOmJhc2tldDp1cGRhdGVkJylcbiAgICogQHBhcmFtIHtmdW5jdGlvbn0gaGFuZGxlciAtIEZ1bmtjamEgaGFuZGxlcmEgKGRhdGEpID0+IHZvaWRcbiAgICogQHJldHVybnMge2Z1bmN0aW9ufSBGdW5rY2phIGRvIHVzdW5pXHUwMTE5Y2lhIGxpc3RlbmVyYVxuICAgKi9cbiAgb24oZXZlbnROYW1lLCBoYW5kbGVyKSB7XG4gICAgaWYgKCF0aGlzLmxpc3RlbmVycy5oYXMoZXZlbnROYW1lKSkge1xuICAgICAgdGhpcy5saXN0ZW5lcnMuc2V0KGV2ZW50TmFtZSwgbmV3IFNldCgpKVxuICAgIH1cblxuICAgIHRoaXMubGlzdGVuZXJzLmdldChldmVudE5hbWUpLmFkZChoYW5kbGVyKVxuXG4gICAgaWYgKHRoaXMuZGVidWcpIHtcbiAgICAgIGNvbnNvbGUubG9nKGBbRXZlbnRCdXNdIFJlZ2lzdGVyZWQgbGlzdGVuZXIgZm9yIFwiJHtldmVudE5hbWV9XCJgLCB7XG4gICAgICAgIGxpc3RlbmVyc0NvdW50OiB0aGlzLmxpc3RlbmVycy5nZXQoZXZlbnROYW1lKS5zaXplXG4gICAgICB9KVxuICAgIH1cblxuICAgIC8vIFp3clx1MDBGM1x1MDEwNyBmdW5rY2pcdTAxMTkgZG8gdXN1bmlcdTAxMTljaWEgbGlzdGVuZXJhXG4gICAgcmV0dXJuICgpID0+IHRoaXMub2ZmKGV2ZW50TmFtZSwgaGFuZGxlcilcbiAgfVxuXG4gIC8qKlxuICAgKiBaYXJlamVzdHJ1aiBsaXN0ZW5lciwga3RcdTAwRjNyeSB3eWtvbmEgc2lcdTAxMTkgdHlsa28gcmF6XG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICogQHBhcmFtIHtmdW5jdGlvbn0gaGFuZGxlciAtIEZ1bmtjamEgaGFuZGxlcmFcbiAgICogQHJldHVybnMge2Z1bmN0aW9ufSBGdW5rY2phIGRvIHVzdW5pXHUwMTE5Y2lhIGxpc3RlbmVyYVxuICAgKi9cbiAgb25jZShldmVudE5hbWUsIGhhbmRsZXIpIHtcbiAgICBjb25zdCBvbmNlSGFuZGxlciA9IChkYXRhKSA9PiB7XG4gICAgICBoYW5kbGVyKGRhdGEpXG4gICAgICB0aGlzLm9mZihldmVudE5hbWUsIG9uY2VIYW5kbGVyKVxuICAgIH1cblxuICAgIHJldHVybiB0aGlzLm9uKGV2ZW50TmFtZSwgb25jZUhhbmRsZXIpXG4gIH1cblxuICAvKipcbiAgICogVXN1XHUwMTQ0IGxpc3RlbmVyXG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICogQHBhcmFtIHtmdW5jdGlvbn0gaGFuZGxlciAtIEZ1bmtjamEgaGFuZGxlcmEgZG8gdXN1bmlcdTAxMTljaWFcbiAgICovXG4gIG9mZihldmVudE5hbWUsIGhhbmRsZXIpIHtcbiAgICBpZiAoIXRoaXMubGlzdGVuZXJzLmhhcyhldmVudE5hbWUpKSB7XG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICBjb25zdCBoYW5kbGVycyA9IHRoaXMubGlzdGVuZXJzLmdldChldmVudE5hbWUpXG4gICAgaGFuZGxlcnMuZGVsZXRlKGhhbmRsZXIpXG5cbiAgICAvLyBVc3VcdTAxNDQgZXZlbnQgeiBtYXB5IGplXHUwMTVCbGkgbmllIG1hIGp1XHUwMTdDIGxpc3RlbmVyXHUwMEYzd1xuICAgIGlmIChoYW5kbGVycy5zaXplID09PSAwKSB7XG4gICAgICB0aGlzLmxpc3RlbmVycy5kZWxldGUoZXZlbnROYW1lKVxuICAgIH1cblxuICAgIGlmICh0aGlzLmRlYnVnKSB7XG4gICAgICBjb25zb2xlLmxvZyhgW0V2ZW50QnVzXSBSZW1vdmVkIGxpc3RlbmVyIGZvciBcIiR7ZXZlbnROYW1lfVwiYCwge1xuICAgICAgICBsaXN0ZW5lcnNDb3VudDogaGFuZGxlcnMuc2l6ZVxuICAgICAgfSlcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogVXN1XHUwMTQ0IHdzenlzdGtpZSBsaXN0ZW5lcnkgZGxhIGRhbmVnbyBldmVudHVcbiAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgKi9cbiAgb2ZmQWxsKGV2ZW50TmFtZSkge1xuICAgIGlmICh0aGlzLmxpc3RlbmVycy5oYXMoZXZlbnROYW1lKSkge1xuICAgICAgdGhpcy5saXN0ZW5lcnMuZGVsZXRlKGV2ZW50TmFtZSlcblxuICAgICAgaWYgKHRoaXMuZGVidWcpIHtcbiAgICAgICAgY29uc29sZS5sb2coYFtFdmVudEJ1c10gUmVtb3ZlZCBhbGwgbGlzdGVuZXJzIGZvciBcIiR7ZXZlbnROYW1lfVwiYClcbiAgICAgIH1cbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogV3llbWl0dWogZXZlbnRcbiAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgKiBAcGFyYW0geyp9IGRhdGEgLSBEYW5lIGRvIHByemVrYXphbmlhXG4gICAqL1xuICBlbWl0KGV2ZW50TmFtZSwgZGF0YSA9IHt9KSB7XG4gICAgY29uc3QgdGltZXN0YW1wID0gRGF0ZS5ub3coKVxuXG4gICAgLy8gWmFwaXN6IGRvIGhpc3RvcmlpXG4gICAgdGhpcy5ldmVudEhpc3RvcnkucHVzaCh7XG4gICAgICBldmVudE5hbWUsXG4gICAgICBkYXRhLFxuICAgICAgdGltZXN0YW1wLFxuICAgICAgbGlzdGVuZXJzQ291bnQ6IHRoaXMubGlzdGVuZXJzLmhhcyhldmVudE5hbWUpID8gdGhpcy5saXN0ZW5lcnMuZ2V0KGV2ZW50TmFtZSkuc2l6ZSA6IDBcbiAgICB9KVxuXG4gICAgLy8gT2dyYW5pY3ogcm96bWlhciBoaXN0b3JpaVxuICAgIGlmICh0aGlzLmV2ZW50SGlzdG9yeS5sZW5ndGggPiB0aGlzLm1heEhpc3RvcnlTaXplKSB7XG4gICAgICB0aGlzLmV2ZW50SGlzdG9yeS5zaGlmdCgpXG4gICAgfVxuXG4gICAgaWYgKHRoaXMuZGVidWcpIHtcbiAgICAgIGNvbnNvbGUubG9nKGBbRXZlbnRCdXNdIEV2ZW50IGVtaXR0ZWQ6IFwiJHtldmVudE5hbWV9XCJgLCB7XG4gICAgICAgIGRhdGEsXG4gICAgICAgIGxpc3RlbmVyc0NvdW50OiB0aGlzLmxpc3RlbmVycy5oYXMoZXZlbnROYW1lKSA/IHRoaXMubGlzdGVuZXJzLmdldChldmVudE5hbWUpLnNpemUgOiAwLFxuICAgICAgICB0aW1lc3RhbXA6IG5ldyBEYXRlKHRpbWVzdGFtcCkudG9JU09TdHJpbmcoKVxuICAgICAgfSlcbiAgICB9XG5cbiAgICAvLyBXeXdvXHUwMTQyYWogd3N6eXN0a2llIGxpc3RlbmVyeVxuICAgIGlmICh0aGlzLmxpc3RlbmVycy5oYXMoZXZlbnROYW1lKSkge1xuICAgICAgY29uc3QgaGFuZGxlcnMgPSBBcnJheS5mcm9tKHRoaXMubGlzdGVuZXJzLmdldChldmVudE5hbWUpKVxuXG4gICAgICBoYW5kbGVycy5mb3JFYWNoKGhhbmRsZXIgPT4ge1xuICAgICAgICB0cnkge1xuICAgICAgICAgIGhhbmRsZXIoZGF0YSlcbiAgICAgICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICBjb25zb2xlLmVycm9yKGBbRXZlbnRCdXNdIEVycm9yIGluIGhhbmRsZXIgZm9yIFwiJHtldmVudE5hbWV9XCI6YCwgZXJyb3IpXG4gICAgICAgICAgLy8gTmllIHByemVyeXdhaiB3eWtvbnl3YW5pYSBpbm55Y2ggaGFuZGxlclx1MDBGM3dcbiAgICAgICAgfVxuICAgICAgfSlcbiAgICB9IGVsc2UgaWYgKHRoaXMuZGVidWcpIHtcbiAgICAgIGNvbnNvbGUud2FybihgW0V2ZW50QnVzXSBObyBsaXN0ZW5lcnMgZm9yIFwiJHtldmVudE5hbWV9XCJgKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBXeWVtaXR1aiBldmVudCBhc3luY2hyb25pY3puaWUgKG5hc3RcdTAxMTlwbnkgdGljaylcbiAgICogUHJ6eWRhdG5lIGdkeSBjaGNlbXkgcG96d29saVx1MDEwNyBVSSBzaVx1MDExOSB3eXJlbmRlcm93YVx1MDEwNyBwcnplZCBoYW5kbGVyZW1cbiAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgKiBAcGFyYW0geyp9IGRhdGEgLSBEYW5lIGRvIHByemVrYXphbmlhXG4gICAqIEByZXR1cm5zIHtQcm9taXNlfSBQcm9taXNlIGt0XHUwMEYzcnkgcmVzb2x2ZSd1amUgc2lcdTAxMTkgcG8gZW1pc2ppXG4gICAqL1xuICBhc3luYyBlbWl0QXN5bmMoZXZlbnROYW1lLCBkYXRhID0ge30pIHtcbiAgICByZXR1cm4gbmV3IFByb21pc2UoKHJlc29sdmUpID0+IHtcbiAgICAgIHNldFRpbWVvdXQoKCkgPT4ge1xuICAgICAgICB0aGlzLmVtaXQoZXZlbnROYW1lLCBkYXRhKVxuICAgICAgICByZXNvbHZlKClcbiAgICAgIH0sIDApXG4gICAgfSlcbiAgfVxuXG4gIC8qKlxuICAgKiBXeWVtaXR1aiBldmVudCB6IG9wXHUwMEYzXHUwMTdBbmllbmllbVxuICAgKiBAcGFyYW0ge3N0cmluZ30gZXZlbnROYW1lIC0gTmF6d2EgZXZlbnR1XG4gICAqIEBwYXJhbSB7Kn0gZGF0YSAtIERhbmUgZG8gcHJ6ZWthemFuaWFcbiAgICogQHBhcmFtIHtudW1iZXJ9IGRlbGF5IC0gT3BcdTAwRjNcdTAxN0FuaWVuaWUgdyBtc1xuICAgKiBAcmV0dXJucyB7bnVtYmVyfSBUaW1lciBJRCAoZG8gY2xlYXJUaW1lb3V0KVxuICAgKi9cbiAgZW1pdERlbGF5ZWQoZXZlbnROYW1lLCBkYXRhID0ge30sIGRlbGF5ID0gMCkge1xuICAgIHJldHVybiBzZXRUaW1lb3V0KCgpID0+IHtcbiAgICAgIHRoaXMuZW1pdChldmVudE5hbWUsIGRhdGEpXG4gICAgfSwgZGVsYXkpXG4gIH1cblxuICAvKipcbiAgICogUG9jemVrYWogbmEgZXZlbnQgKHp3cmFjYSBQcm9taXNlKVxuICAgKiBQcnp5ZGF0bmUgdyB0ZXN0YWNoIGkgYXN5bmMgZmxvd3NcbiAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgKiBAcGFyYW0ge251bWJlcn0gdGltZW91dCAtIFRpbWVvdXQgdyBtcyAob3Bjam9uYWxueSlcbiAgICogQHJldHVybnMge1Byb21pc2V9IFByb21pc2Uga3RcdTAwRjNyeSByZXNvbHZlJ3VqZSBzaVx1MDExOSB6IGRhbnltaSBldmVudHVcbiAgICovXG4gIHdhaXRGb3IoZXZlbnROYW1lLCB0aW1lb3V0ID0gNTAwMCkge1xuICAgIHJldHVybiBuZXcgUHJvbWlzZSgocmVzb2x2ZSwgcmVqZWN0KSA9PiB7XG4gICAgICBjb25zdCB0aW1lciA9IHRpbWVvdXQgPiAwID8gc2V0VGltZW91dCgoKSA9PiB7XG4gICAgICAgIHRoaXMub2ZmKGV2ZW50TmFtZSwgaGFuZGxlcilcbiAgICAgICAgcmVqZWN0KG5ldyBFcnJvcihgW0V2ZW50QnVzXSBUaW1lb3V0IHdhaXRpbmcgZm9yIGV2ZW50IFwiJHtldmVudE5hbWV9XCJgKSlcbiAgICAgIH0sIHRpbWVvdXQpIDogbnVsbFxuXG4gICAgICBjb25zdCBoYW5kbGVyID0gKGRhdGEpID0+IHtcbiAgICAgICAgaWYgKHRpbWVyKSBjbGVhclRpbWVvdXQodGltZXIpXG4gICAgICAgIHJlc29sdmUoZGF0YSlcbiAgICAgIH1cblxuICAgICAgdGhpcy5vbmNlKGV2ZW50TmFtZSwgaGFuZGxlcilcbiAgICB9KVxuICB9XG5cbiAgLyoqXG4gICAqIFNwcmF3ZFx1MDE3QSBjenkgc1x1MDEwNSBsaXN0ZW5lcnkgZGxhIGRhbmVnbyBldmVudHVcbiAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgKiBAcmV0dXJucyB7Ym9vbGVhbn1cbiAgICovXG4gIGhhc0xpc3RlbmVycyhldmVudE5hbWUpIHtcbiAgICByZXR1cm4gdGhpcy5saXN0ZW5lcnMuaGFzKGV2ZW50TmFtZSkgJiYgdGhpcy5saXN0ZW5lcnMuZ2V0KGV2ZW50TmFtZSkuc2l6ZSA+IDBcbiAgfVxuXG4gIC8qKlxuICAgKiBQb2JpZXJ6IGxpY3piXHUwMTE5IGxpc3RlbmVyXHUwMEYzdyBkbGEgZXZlbnR1XG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICogQHJldHVybnMge251bWJlcn1cbiAgICovXG4gIGdldExpc3RlbmVyc0NvdW50KGV2ZW50TmFtZSkge1xuICAgIHJldHVybiB0aGlzLmxpc3RlbmVycy5oYXMoZXZlbnROYW1lKSA/IHRoaXMubGlzdGVuZXJzLmdldChldmVudE5hbWUpLnNpemUgOiAwXG4gIH1cblxuICAvKipcbiAgICogUG9iaWVyeiB3c3p5c3RraWUgemFyZWplc3Ryb3dhbmUgZXZlbnR5XG4gICAqIEByZXR1cm5zIHtzdHJpbmdbXX1cbiAgICovXG4gIGdldFJlZ2lzdGVyZWRFdmVudHMoKSB7XG4gICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5saXN0ZW5lcnMua2V5cygpKVxuICB9XG5cbiAgLyoqXG4gICAqIFBvYmllcnogaGlzdG9yaVx1MDExOSBldmVudFx1MDBGM3dcbiAgICogQHBhcmFtIHtudW1iZXJ9IGxpbWl0IC0gTGltaXQgZXZlbnRcdTAwRjN3IGRvIHp3clx1MDBGM2NlbmlhIChvcGNqb25hbG55KVxuICAgKiBAcmV0dXJucyB7QXJyYXl9XG4gICAqL1xuICBnZXRFdmVudEhpc3RvcnkobGltaXQgPSA1MCkge1xuICAgIHJldHVybiB0aGlzLmV2ZW50SGlzdG9yeS5zbGljZSgtbGltaXQpXG4gIH1cblxuICAvKipcbiAgICogV3ljenlcdTAxNUJcdTAxMDcgaGlzdG9yaVx1MDExOSBldmVudFx1MDBGM3dcbiAgICovXG4gIGNsZWFySGlzdG9yeSgpIHtcbiAgICB0aGlzLmV2ZW50SGlzdG9yeSA9IFtdXG4gIH1cblxuICAvKipcbiAgICogV3ljenlcdTAxNUJcdTAxMDcgd3N6eXN0a2llIGxpc3RlbmVyeSAodVx1MDE3Q3lqIG9zdHJvXHUwMTdDbmllISlcbiAgICovXG4gIGNsZWFyQWxsKCkge1xuICAgIHRoaXMubGlzdGVuZXJzLmNsZWFyKClcblxuICAgIGlmICh0aGlzLmRlYnVnKSB7XG4gICAgICBjb25zb2xlLmxvZygnW0V2ZW50QnVzXSBBbGwgbGlzdGVuZXJzIGNsZWFyZWQnKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBXeXBpc3ogc3RhdHlzdHlraSBFdmVudEJ1c1xuICAgKi9cbiAgcHJpbnRTdGF0cygpIHtcbiAgICBjb25zb2xlLmdyb3VwKCdbRXZlbnRCdXNdIFN0YXRpc3RpY3MnKVxuICAgIGNvbnNvbGUubG9nKCdSZWdpc3RlcmVkIGV2ZW50czonLCB0aGlzLmdldFJlZ2lzdGVyZWRFdmVudHMoKSlcbiAgICBjb25zb2xlLmxvZygnVG90YWwgbGlzdGVuZXJzOicsIEFycmF5LmZyb20odGhpcy5saXN0ZW5lcnMudmFsdWVzKCkpLnJlZHVjZSgoc3VtLCBzZXQpID0+IHN1bSArIHNldC5zaXplLCAwKSlcbiAgICBjb25zb2xlLmxvZygnRXZlbnQgaGlzdG9yeSBzaXplOicsIHRoaXMuZXZlbnRIaXN0b3J5Lmxlbmd0aClcblxuICAgIGNvbnNvbGUuZ3JvdXAoJ0xpc3RlbmVycyBwZXIgZXZlbnQ6JylcbiAgICB0aGlzLmxpc3RlbmVycy5mb3JFYWNoKChoYW5kbGVycywgZXZlbnROYW1lKSA9PiB7XG4gICAgICBjb25zb2xlLmxvZyhgICAke2V2ZW50TmFtZX06ICR7aGFuZGxlcnMuc2l6ZX1gKVxuICAgIH0pXG4gICAgY29uc29sZS5ncm91cEVuZCgpXG5cbiAgICBjb25zb2xlLmdyb3VwKCdSZWNlbnQgZXZlbnRzOicpXG4gICAgdGhpcy5nZXRFdmVudEhpc3RvcnkoMTApLmZvckVhY2goZXZlbnQgPT4ge1xuICAgICAgY29uc29sZS5sb2coYCAgJHtldmVudC5ldmVudE5hbWV9ICgke2V2ZW50Lmxpc3RlbmVyc0NvdW50fSBsaXN0ZW5lcnMpIC0gJHtuZXcgRGF0ZShldmVudC50aW1lc3RhbXApLnRvTG9jYWxlVGltZVN0cmluZygpfWApXG4gICAgfSlcbiAgICBjb25zb2xlLmdyb3VwRW5kKClcblxuICAgIGNvbnNvbGUuZ3JvdXBFbmQoKVxuICB9XG59XG5cbi8vIEVrc3BvcnR1aiBzaW5nbGV0b24gaW5zdGFuY2VcbmV4cG9ydCBjb25zdCBldmVudEJ1cyA9IG5ldyBFdmVudEJ1cygpXG5cbi8vIE9wY2pvbmFsbmllOiB3XHUwMTQyXHUwMTA1Y3ogZGVidWcgdyBkZXYgbW9kZVxuaWYgKHR5cGVvZiB3aW5kb3cgIT09ICd1bmRlZmluZWQnICYmIHdpbmRvdy5sb2NhdGlvbj8uaG9zdG5hbWUgPT09ICdsb2NhbGhvc3QnKSB7XG4gIGV2ZW50QnVzLnNldERlYnVnKHRydWUpXG59XG5cbi8vIFVkb3N0XHUwMTE5cG5paiBnbG9iYWxuaWUgZGxhIFx1MDE0MmF0d2VnbyBkZWJ1Z293YW5pYSB3IGtvbnNvbGlcbmlmICh0eXBlb2Ygd2luZG93ICE9PSAndW5kZWZpbmVkJykge1xuICB3aW5kb3cuZXZlbnRCdXMgPSBldmVudEJ1c1xufVxuXG5leHBvcnQgZGVmYXVsdCBldmVudEJ1c1xuIiwgIi8qKlxuICogRXZlbnRCdXMgTWl4aW4gZGxhIFN0aW11bHVzIENvbnRyb2xsZXJzXG4gKlxuICogRG9kYWplIG1ldG9keSBkbyBcdTAxNDJhdHdlZ28ga29yenlzdGFuaWEgeiBFdmVudEJ1cyB3IGtvbnRyb2xlcmFjaCBTdGltdWx1cy5cbiAqXG4gKiBVXHUwMTdDeWNpZTpcbiAqXG4gKiBpbXBvcnQgeyBDb250cm9sbGVyIH0gZnJvbSBcIkBob3R3aXJlZC9zdGltdWx1c1wiXG4gKiBpbXBvcnQgeyB3aXRoRXZlbnRCdXMgfSBmcm9tIFwiLi4vbWl4aW5zL2V2ZW50X2J1c19taXhpbi5qc1wiXG4gKlxuICogZXhwb3J0IGRlZmF1bHQgY2xhc3MgZXh0ZW5kcyB3aXRoRXZlbnRCdXMoQ29udHJvbGxlcikge1xuICogICBjb25uZWN0KCkge1xuICogICAgIC8vIE5hc1x1MDE0MnVjaHVqIGV2ZW50dVxuICogICAgIHRoaXMubGlzdGVuKCdvZTpiYXNrZXQ6dXBkYXRlZCcsIHRoaXMuaGFuZGxlQmFza2V0VXBkYXRlKVxuICpcbiAqICAgICAvLyBsdWIgeiBhdXRvLWNsZWFudXA6XG4gKiAgICAgdGhpcy5saXN0ZW4oJ29lOmJhc2tldDp1cGRhdGVkJywgKGRhdGEpID0+IHtcbiAqICAgICAgIGNvbnNvbGUubG9nKCdCYXNrZXQgdXBkYXRlZDonLCBkYXRhKVxuICogICAgIH0pXG4gKiAgIH1cbiAqXG4gKiAgIGhhbmRsZUJhc2tldFVwZGF0ZShkYXRhKSB7XG4gKiAgICAgY29uc29sZS5sb2coJ0Jhc2tldCB1cGRhdGVkOicsIGRhdGEpXG4gKiAgIH1cbiAqXG4gKiAgIHNvbWVBY3Rpb24oKSB7XG4gKiAgICAgLy8gV3llbWl0dWogZXZlbnRcbiAqICAgICB0aGlzLmJyb2FkY2FzdCgnb2U6YmFza2V0Oml0ZW0tYWRkZWQnLCB7IGl0ZW1JZDogMTIzIH0pXG4gKiAgIH1cbiAqXG4gKiAgIC8vIGRpc2Nvbm5lY3QoKSBhdXRvbWF0eWN6bmllIGN6eVx1MDE1QmNpIHdzenlzdGtpZSBsaXN0ZW5lcnkhXG4gKiB9XG4gKlxuICogS29yenlcdTAxNUJjaTpcbiAqIC0gQXV0b21hdHljem5lIGN6eXN6Y3plbmllIGxpc3RlbmVyXHUwMEYzdyB3IGRpc2Nvbm5lY3QoKVxuICogLSBLclx1MDBGM3RzemUgQVBJOiBsaXN0ZW4oKSwgYnJvYWRjYXN0KClcbiAqIC0gWmFjaG93YW5pZSBrb250ZWtzdHUgKHRoaXMpIHcgaGFuZGxlcmFjaFxuICogLSBEZWJ1ZyBpbmZvIHogbmF6d1x1MDEwNSBrb250cm9sZXJhXG4gKi9cblxuaW1wb3J0IHsgZXZlbnRCdXMgfSBmcm9tICcuLi91dGlscy9ldmVudF9idXMuanMnXG5cbmV4cG9ydCBmdW5jdGlvbiB3aXRoRXZlbnRCdXMoQmFzZUNvbnRyb2xsZXIpIHtcbiAgcmV0dXJuIGNsYXNzIGV4dGVuZHMgQmFzZUNvbnRyb2xsZXIge1xuICAgIGNvbnN0cnVjdG9yKC4uLmFyZ3MpIHtcbiAgICAgIHN1cGVyKC4uLmFyZ3MpXG5cbiAgICAgIC8vIFByemVjaG93dWogcmVmZXJlbmNqZSBkbyBsaXN0ZW5lclx1MDBGM3cgZGxhIGNsZWFudXBcbiAgICAgIHRoaXMuX2V2ZW50QnVzTGlzdGVuZXJzID0gW11cbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBOYXNcdTAxNDJ1Y2h1aiBldmVudHUgcHJ6ZXogRXZlbnRCdXNcbiAgICAgKiBBdXRvbWF0eWN6bmllIHVzdXdhIGxpc3RlbmVyYSB3IGRpc2Nvbm5lY3QoKVxuICAgICAqXG4gICAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgICAqIEBwYXJhbSB7ZnVuY3Rpb259IGhhbmRsZXIgLSBIYW5kbGVyIGZ1bmN0aW9uXG4gICAgICogQHBhcmFtIHtvYmplY3R9IG9wdGlvbnMgLSBPcGNqZVxuICAgICAqIEBwYXJhbSB7Ym9vbGVhbn0gb3B0aW9ucy5vbmNlIC0gQ3p5IHd5a29uYVx1MDEwNyB0eWxrbyByYXogKGRlZmF1bHQ6IGZhbHNlKVxuICAgICAqIEByZXR1cm5zIHtmdW5jdGlvbn0gRnVua2NqYSBkbyBtYW51YWxuZWdvIHVzdW5pXHUwMTE5Y2lhIGxpc3RlbmVyYVxuICAgICAqL1xuICAgIGxpc3RlbihldmVudE5hbWUsIGhhbmRsZXIsIG9wdGlvbnMgPSB7fSkge1xuICAgICAgY29uc3QgeyBvbmNlID0gZmFsc2UgfSA9IG9wdGlvbnNcblxuICAgICAgLy8gQmluZCBoYW5kbGVyIGRvIHRoaXMga29udHJvbGVyYVxuICAgICAgY29uc3QgYm91bmRIYW5kbGVyID0gaGFuZGxlci5iaW5kKHRoaXMpXG5cbiAgICAgIC8vIERvZGFqIHByZWZpeCB6IG5hendcdTAxMDUga29udHJvbGVyYSBkbGEgZGVidWdvd2FuaWFcbiAgICAgIGNvbnN0IGNvbnRyb2xsZXJOYW1lID0gdGhpcy5pZGVudGlmaWVyIHx8IHRoaXMuY29uc3RydWN0b3IubmFtZVxuICAgICAgY29uc3QgZGVidWdIYW5kbGVyID0gKGRhdGEpID0+IHtcbiAgICAgICAgaWYgKGV2ZW50QnVzLmRlYnVnKSB7XG4gICAgICAgICAgY29uc29sZS5sb2coYFske2NvbnRyb2xsZXJOYW1lfV0gUmVjZWl2ZWQgZXZlbnQgXCIke2V2ZW50TmFtZX1cImAsIGRhdGEpXG4gICAgICAgIH1cbiAgICAgICAgYm91bmRIYW5kbGVyKGRhdGEpXG4gICAgICB9XG5cbiAgICAgIC8vIFphcmVqZXN0cnVqIGxpc3RlbmVyXG4gICAgICBjb25zdCByZW1vdmVMaXN0ZW5lciA9IG9uY2VcbiAgICAgICAgPyBldmVudEJ1cy5vbmNlKGV2ZW50TmFtZSwgZGVidWdIYW5kbGVyKVxuICAgICAgICA6IGV2ZW50QnVzLm9uKGV2ZW50TmFtZSwgZGVidWdIYW5kbGVyKVxuXG4gICAgICAvLyBaYWNob3dhaiByZWZlcmVuY2pcdTAxMTkgZG8gY2xlYW51cFxuICAgICAgdGhpcy5fZXZlbnRCdXNMaXN0ZW5lcnMucHVzaCh7IGV2ZW50TmFtZSwgaGFuZGxlcjogZGVidWdIYW5kbGVyLCByZW1vdmVMaXN0ZW5lciB9KVxuXG4gICAgICAvLyBad3JcdTAwRjNcdTAxMDcgZnVua2NqXHUwMTE5IGRvIG1hbnVhbG5lZ28gdXN1bmlcdTAxMTljaWFcbiAgICAgIHJldHVybiByZW1vdmVMaXN0ZW5lclxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIE5hc1x1MDE0MnVjaHVqIGV2ZW50dSB0eWxrbyByYXpcbiAgICAgKiBTaG9ydGhhbmQgZGxhIGxpc3RlbihldmVudE5hbWUsIGhhbmRsZXIsIHsgb25jZTogdHJ1ZSB9KVxuICAgICAqXG4gICAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgICAqIEBwYXJhbSB7ZnVuY3Rpb259IGhhbmRsZXIgLSBIYW5kbGVyIGZ1bmN0aW9uXG4gICAgICogQHJldHVybnMge2Z1bmN0aW9ufSBGdW5rY2phIGRvIG1hbnVhbG5lZ28gdXN1bmlcdTAxMTljaWEgbGlzdGVuZXJhXG4gICAgICovXG4gICAgbGlzdGVuT25jZShldmVudE5hbWUsIGhhbmRsZXIpIHtcbiAgICAgIHJldHVybiB0aGlzLmxpc3RlbihldmVudE5hbWUsIGhhbmRsZXIsIHsgb25jZTogdHJ1ZSB9KVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIFd5ZW1pdHVqIGV2ZW50IHByemV6IEV2ZW50QnVzXG4gICAgICpcbiAgICAgKiBAcGFyYW0ge3N0cmluZ30gZXZlbnROYW1lIC0gTmF6d2EgZXZlbnR1XG4gICAgICogQHBhcmFtIHsqfSBkYXRhIC0gRGFuZSBkbyBwcnpla2F6YW5pYVxuICAgICAqL1xuICAgIGJyb2FkY2FzdChldmVudE5hbWUsIGRhdGEgPSB7fSkge1xuICAgICAgY29uc3QgY29udHJvbGxlck5hbWUgPSB0aGlzLmlkZW50aWZpZXIgfHwgdGhpcy5jb25zdHJ1Y3Rvci5uYW1lXG5cbiAgICAgIGlmIChldmVudEJ1cy5kZWJ1Zykge1xuICAgICAgICBjb25zb2xlLmxvZyhgWyR7Y29udHJvbGxlck5hbWV9XSBCcm9hZGNhc3RpbmcgZXZlbnQgXCIke2V2ZW50TmFtZX1cImAsIGRhdGEpXG4gICAgICB9XG5cbiAgICAgIGV2ZW50QnVzLmVtaXQoZXZlbnROYW1lLCBkYXRhKVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIFd5ZW1pdHVqIGV2ZW50IGFzeW5jaHJvbmljem5pZVxuICAgICAqXG4gICAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgICAqIEBwYXJhbSB7Kn0gZGF0YSAtIERhbmUgZG8gcHJ6ZWthemFuaWFcbiAgICAgKiBAcmV0dXJucyB7UHJvbWlzZX1cbiAgICAgKi9cbiAgICBhc3luYyBicm9hZGNhc3RBc3luYyhldmVudE5hbWUsIGRhdGEgPSB7fSkge1xuICAgICAgcmV0dXJuIGV2ZW50QnVzLmVtaXRBc3luYyhldmVudE5hbWUsIGRhdGEpXG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogUG9jemVrYWogbmEgZXZlbnRcbiAgICAgKiBQcnp5ZGF0bmUgdyBhc3luYyBmbG93c1xuICAgICAqXG4gICAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgICAqIEBwYXJhbSB7bnVtYmVyfSB0aW1lb3V0IC0gVGltZW91dCB3IG1zXG4gICAgICogQHJldHVybnMge1Byb21pc2V9XG4gICAgICovXG4gICAgYXN5bmMgd2FpdEZvckV2ZW50KGV2ZW50TmFtZSwgdGltZW91dCA9IDUwMDApIHtcbiAgICAgIHJldHVybiBldmVudEJ1cy53YWl0Rm9yKGV2ZW50TmFtZSwgdGltZW91dClcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBVc3VcdTAxNDQga29ua3JldG55IGxpc3RlbmVyXG4gICAgICpcbiAgICAgKiBAcGFyYW0ge3N0cmluZ30gZXZlbnROYW1lIC0gTmF6d2EgZXZlbnR1XG4gICAgICogQHBhcmFtIHtmdW5jdGlvbn0gaGFuZGxlciAtIEhhbmRsZXIgZG8gdXN1bmlcdTAxMTljaWFcbiAgICAgKi9cbiAgICBzdG9wTGlzdGVuaW5nKGV2ZW50TmFtZSwgaGFuZGxlcikge1xuICAgICAgZXZlbnRCdXMub2ZmKGV2ZW50TmFtZSwgaGFuZGxlcilcblxuICAgICAgLy8gVXN1XHUwMTQ0IHogbmFzemVqIGxpc3R5XG4gICAgICB0aGlzLl9ldmVudEJ1c0xpc3RlbmVycyA9IHRoaXMuX2V2ZW50QnVzTGlzdGVuZXJzLmZpbHRlcihcbiAgICAgICAgbGlzdGVuZXIgPT4gIShsaXN0ZW5lci5ldmVudE5hbWUgPT09IGV2ZW50TmFtZSAmJiBsaXN0ZW5lci5oYW5kbGVyID09PSBoYW5kbGVyKVxuICAgICAgKVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIFVzdVx1MDE0NCB3c3p5c3RraWUgbGlzdGVuZXJ5IHRlZ28ga29udHJvbGVyYVxuICAgICAqIEF1dG9tYXR5Y3puaWUgd3l3b1x1MDE0Mnl3YW5lIHcgZGlzY29ubmVjdCgpXG4gICAgICovXG4gICAgc3RvcExpc3RlbmluZ0FsbCgpIHtcbiAgICAgIHRoaXMuX2V2ZW50QnVzTGlzdGVuZXJzLmZvckVhY2goKHsgcmVtb3ZlTGlzdGVuZXIgfSkgPT4ge1xuICAgICAgICByZW1vdmVMaXN0ZW5lcigpXG4gICAgICB9KVxuXG4gICAgICB0aGlzLl9ldmVudEJ1c0xpc3RlbmVycyA9IFtdXG5cbiAgICAgIGlmIChldmVudEJ1cy5kZWJ1Zykge1xuICAgICAgICBjb25zdCBjb250cm9sbGVyTmFtZSA9IHRoaXMuaWRlbnRpZmllciB8fCB0aGlzLmNvbnN0cnVjdG9yLm5hbWVcbiAgICAgICAgY29uc29sZS5sb2coYFske2NvbnRyb2xsZXJOYW1lfV0gQWxsIEV2ZW50QnVzIGxpc3RlbmVycyByZW1vdmVkYClcbiAgICAgIH1cbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBPdmVycmlkZSBkaXNjb25uZWN0KCkgXHUwMTdDZWJ5IGF1dG9tYXR5Y3puaWUgY3p5XHUwMTVCY2lcdTAxMDcgbGlzdGVuZXJ5XG4gICAgICovXG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICAgIHRoaXMuc3RvcExpc3RlbmluZ0FsbCgpXG5cbiAgICAgIC8vIFd5d29cdTAxNDJhaiBvcnlnaW5hbG55IGRpc2Nvbm5lY3QgamVcdTAxNUJsaSBpc3RuaWVqZVxuICAgICAgaWYgKHN1cGVyLmRpc2Nvbm5lY3QpIHtcbiAgICAgICAgc3VwZXIuZGlzY29ubmVjdCgpXG4gICAgICB9XG4gICAgfVxuICB9XG59XG5cbmV4cG9ydCBkZWZhdWx0IHdpdGhFdmVudEJ1c1xuIiwgIi8qKlxuICogT25lLVBhZ2UgQ2hlY2tvdXQgU3RyaXBlIEludGVncmF0aW9uIENvbnRyb2xsZXJcbiAqXG4gKiBJbnRlZ3JhdGVzIFN0cmlwZSBwYXltZW50cyB3aXRoIHRoZSBvbmUtcGFnZSBjaGVja291dCBtb2R1bGUgdmlhIEV2ZW50QnVzLlxuICogSW1wbGVtZW50cyB0aGUgZXZlbnQgY29udHJhY3QgZGVmaW5lZCBpbiBvbmUtcGFnZSBjaGVja291dCBkb2N1bWVudGF0aW9uLlxuICpcbiAqIFJlcXVpcmVkIEV2ZW50cyB0byBMaXN0ZW46XG4gKiAtIG9lOnBheW1lbnQ6bWV0aG9kLXNlbGVjdGVkIC0gVXNlciBzZWxlY3RzIHBheW1lbnQgbWV0aG9kXG4gKiAtIG9lOnBheW1lbnQ6Y29uZmlybS1yZXF1ZXN0ZWQgLSBDb3JlIHJlcXVlc3RzIHBheW1lbnQgY29uZmlybWF0aW9uXG4gKlxuICogUmVxdWlyZWQgRXZlbnRzIHRvIEVtaXQ6XG4gKiAtIG9lOnBheW1lbnQ6Y29uZmlybWVkIC0gUGF5bWVudCBzdWNjZXNzZnVsbHkgY29uZmlybWVkXG4gKiAtIG9lOnBheW1lbnQ6ZmFpbGVkIC0gUGF5bWVudCBmYWlsZWRcbiAqXG4gKiBAc2VlIGRvY3MvUEFZTUVOVF9QUk9WSURFUl9JTlRFR1JBVElPTl9HVUlERS5tZFxuICogQHNlZSBkb2NzL2RpYWdyYW1zL3BheW1lbnQtcHJvdmlkZXItaW50ZWdyYXRpb24vMDMtZXZlbnQtY29udHJhY3QtZGV0YWlscy5wdW1sXG4gKi9cblxuaW1wb3J0IHsgQ29udHJvbGxlciB9IGZyb20gXCJAaG90d2lyZWQvc3RpbXVsdXNcIlxuaW1wb3J0IHsgd2l0aEV2ZW50QnVzIH0gZnJvbSBcIi4uLy4uLy4uLy4uLy4uL29uZXBhZ2UtY2hlY2tvdXQvcmVzb3VyY2VzL2J1aWxkL2pzL21peGlucy9ldmVudF9idXNfbWl4aW4uanNcIlxuXG5leHBvcnQgZGVmYXVsdCBjbGFzcyBleHRlbmRzIHdpdGhFdmVudEJ1cyhDb250cm9sbGVyKSB7XG4gIHN0YXRpYyB2YWx1ZXMgPSB7XG4gICAgcHVibGlzaGFibGVLZXk6IFN0cmluZyxcbiAgICBtb2RlOiBTdHJpbmcsXG4gICAgcmV0dXJuVXJsOiBTdHJpbmcsXG4gIH1cblxuICBzdGF0aWMgdGFyZ2V0cyA9IFtcImVsZW1lbnRcIiwgXCJsb2FkZXJcIiwgXCJlcnJvclwiXVxuXG4gIC8qKlxuICAgKiBTdGltdWx1cyBsaWZlY3ljbGU6IENvbnRyb2xsZXIgY29ubmVjdGVkIHRvIERPTVxuICAgKi9cbiAgY29ubmVjdCgpIHtcbiAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBDb25uZWN0ZWQnKVxuXG4gICAgLy8gUmVnaXN0ZXIgRXZlbnRCdXMgbGlzdGVuZXJzIChhdXRvbWF0aWMgY2xlYW51cCB2aWEgd2l0aEV2ZW50QnVzIG1peGluKVxuICAgIHRoaXMubGlzdGVuKCdvZTpwYXltZW50Om1ldGhvZC1zZWxlY3RlZCcsIHRoaXMuaGFuZGxlTWV0aG9kU2VsZWN0ZWQuYmluZCh0aGlzKSlcbiAgICB0aGlzLmxpc3Rlbignb2U6cGF5bWVudDpjb25maXJtLXJlcXVlc3RlZCcsIHRoaXMuaGFuZGxlQ29uZmlybVJlcXVlc3QuYmluZCh0aGlzKSlcblxuICAgIC8vIEluaXRpYWxpemUgc3RhdGVcbiAgICB0aGlzLnN0cmlwZSA9IG51bGxcbiAgICB0aGlzLmVsZW1lbnRzID0gbnVsbFxuICAgIHRoaXMucGF5bWVudEVsZW1lbnQgPSBudWxsXG4gICAgdGhpcy5jdXJyZW50Q29udHJhY3RJZCA9IG51bGxcbiAgICB0aGlzLmN1cnJlbnRPcmRlcklkID0gbnVsbFxuICB9XG5cbiAgLyoqXG4gICAqIFN0aW11bHVzIGxpZmVjeWNsZTogQ29udHJvbGxlciBkaXNjb25uZWN0ZWQgZnJvbSBET01cbiAgICovXG4gIGRpc2Nvbm5lY3QoKSB7XG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gRGlzY29ubmVjdGVkJylcblxuICAgIC8vIEV2ZW50QnVzIGxpc3RlbmVycyBhcmUgYXV0b21hdGljYWxseSBjbGVhbmVkIHVwIGJ5IHdpdGhFdmVudEJ1cyBtaXhpblxuXG4gICAgLy8gQ2xlYW51cCBTdHJpcGUgcmVzb3VyY2VzXG4gICAgaWYgKHRoaXMucGF5bWVudEVsZW1lbnQpIHtcbiAgICAgIHRoaXMucGF5bWVudEVsZW1lbnQuZGVzdHJveSgpXG4gICAgICB0aGlzLnBheW1lbnRFbGVtZW50ID0gbnVsbFxuICAgIH1cbiAgICB0aGlzLmVsZW1lbnRzID0gbnVsbFxuICAgIHRoaXMuc3RyaXBlID0gbnVsbFxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBvZTpwYXltZW50Om1ldGhvZC1zZWxlY3RlZCBldmVudFxuICAgKlxuICAgKiBFdmVudCBEZXRhaWw6XG4gICAqIHtcbiAgICogICBwYXltZW50TWV0aG9kSWQ6IHN0cmluZywgIC8vIGUuZy4sICdveGlkc3RyaXBlJywgJ3BheXBhbCdcbiAgICogICBwYXltZW50TWV0aG9kVGl0bGU6IHN0cmluZyAvLyBlLmcuLCAnQ3JlZGl0IENhcmQgKFN0cmlwZSknXG4gICAqIH1cbiAgICpcbiAgICogUmVzcG9uc2liaWxpdHk6XG4gICAqIC0gQ2hlY2sgaWYgcGF5bWVudE1ldGhvZElkIG1hdGNoZXMgU3RyaXBlXG4gICAqIC0gU2hvdyBTdHJpcGUgVUkgaWYgbWF0Y2hcbiAgICogLSBIaWRlIFN0cmlwZSBVSSBpZiBubyBtYXRjaFxuICAgKi9cbiAgYXN5bmMgaGFuZGxlTWV0aG9kU2VsZWN0ZWQoZXZlbnQpIHtcbiAgICBjb25zdCB7IHBheW1lbnRNZXRob2RJZCB9ID0gZXZlbnQuZGV0YWlsXG5cbiAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBQYXltZW50IG1ldGhvZCBzZWxlY3RlZDonLCBwYXltZW50TWV0aG9kSWQpXG5cbiAgICBpZiAoIXRoaXMuaXNTdHJpcGVNZXRob2QocGF5bWVudE1ldGhvZElkKSkge1xuICAgICAgdGhpcy5oaWRlU3RyaXBlVUkoKVxuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgLy8gU2hvdyBTdHJpcGUgVUlcbiAgICB0aGlzLnNob3dTdHJpcGVVSSgpXG5cbiAgICAvLyBMb2FkIFN0cmlwZS5qcyBTREsgaWYgbm90IGxvYWRlZFxuICAgIGlmICghdGhpcy5zdHJpcGUpIHtcbiAgICAgIGF3YWl0IHRoaXMubG9hZFN0cmlwZVNESygpXG4gICAgfVxuXG4gICAgLy8gSW5pdGlhbGl6ZSBQYXltZW50IEVsZW1lbnRcbiAgICBhd2FpdCB0aGlzLmluaXRpYWxpemVQYXltZW50RWxlbWVudCgpXG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIG9lOnBheW1lbnQ6Y29uZmlybS1yZXF1ZXN0ZWQgZXZlbnRcbiAgICpcbiAgICogRXZlbnQgRGV0YWlsOlxuICAgKiB7XG4gICAqICAgY29udHJhY3RJZDogc3RyaW5nLCAgICAgICAvLyBQYXltZW50Q29udHJhY3QgSURcbiAgICogICBjbGllbnRTZWNyZXQ6IHN0cmluZywgICAgIC8vIFN0cmlwZSBjbGllbnQgc2VjcmV0IChmcm9tIFBheW1lbnRJbnRlbnQpXG4gICAqICAgcGF5bWVudE1ldGhvZElkOiBzdHJpbmcsICAvLyBlLmcuLCAnb3hpZHN0cmlwZSdcbiAgICogICByZXR1cm5Vcmw6IHN0cmluZyAgICAgICAgIC8vIFVSTCB0byByZWRpcmVjdCBhZnRlciBTQ0FcbiAgICogfVxuICAgKlxuICAgKiBSZXNwb25zaWJpbGl0eTpcbiAgICogLSBDaGVjayBpZiBwYXltZW50TWV0aG9kSWQgbWF0Y2hlcyBTdHJpcGVcbiAgICogLSBQcm9jZXNzIHBheW1lbnQgd2l0aCBTdHJpcGUgU0RLXG4gICAqIC0gRW1pdCBvZTpwYXltZW50OmNvbmZpcm1lZCBvciBvZTpwYXltZW50OmZhaWxlZFxuICAgKi9cbiAgYXN5bmMgaGFuZGxlQ29uZmlybVJlcXVlc3QoZXZlbnQpIHtcbiAgICBjb25zdCB7IHBheW1lbnRNZXRob2RJZCwgY2xpZW50U2VjcmV0LCBjb250cmFjdElkLCBvcmRlcklkIH0gPSBldmVudC5kZXRhaWxcblxuICAgIGNvbnNvbGUubG9nKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIENvbmZpcm0gcmVxdWVzdDonLCB7XG4gICAgICBwYXltZW50TWV0aG9kSWQsXG4gICAgICBjbGllbnRTZWNyZXQ6IGNsaWVudFNlY3JldCA/ICcqKionIDogJ21pc3NpbmcnLFxuICAgICAgY29udHJhY3RJZCxcbiAgICAgIG9yZGVySWRcbiAgICB9KVxuXG4gICAgaWYgKCF0aGlzLmlzU3RyaXBlTWV0aG9kKHBheW1lbnRNZXRob2RJZCkpIHtcbiAgICAgIHJldHVybiAvLyBOb3QgbXkgcmVzcG9uc2liaWxpdHlcbiAgICB9XG5cbiAgICAvLyBTYXZlIHN0YXRlXG4gICAgdGhpcy5jdXJyZW50Q29udHJhY3RJZCA9IGNvbnRyYWN0SWRcbiAgICB0aGlzLmN1cnJlbnRPcmRlcklkID0gb3JkZXJJZFxuXG4gICAgLy8gU2hvdyBsb2FkZXJcbiAgICB0aGlzLnNob3dMb2FkZXIoKVxuICAgIHRoaXMuaGlkZUVycm9yKClcblxuICAgIHRyeSB7XG4gICAgICAvLyBDb25maXJtIHBheW1lbnQgd2l0aCBTdHJpcGVcbiAgICAgIGNvbnN0IHJlc3VsdCA9IGF3YWl0IHRoaXMuY29uZmlybVBheW1lbnQoY2xpZW50U2VjcmV0KVxuXG4gICAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBQYXltZW50IGNvbmZpcm1lZDonLCByZXN1bHQpXG5cbiAgICAgIC8vIEVtaXQgc3VjY2VzcyBldmVudFxuICAgICAgdGhpcy5icm9hZGNhc3RQYXltZW50Q29uZmlybWVkKHJlc3VsdClcbiAgICB9IGNhdGNoIChlcnJvcikge1xuICAgICAgY29uc29sZS5lcnJvcignW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBQYXltZW50IGZhaWxlZDonLCBlcnJvcilcblxuICAgICAgLy8gU2hvdyBlcnJvclxuICAgICAgdGhpcy5zaG93RXJyb3IoZXJyb3IubWVzc2FnZSlcblxuICAgICAgLy8gRW1pdCBmYWlsdXJlIGV2ZW50XG4gICAgICB0aGlzLmJyb2FkY2FzdFBheW1lbnRGYWlsZWQoZXJyb3IpXG4gICAgfSBmaW5hbGx5IHtcbiAgICAgIHRoaXMuaGlkZUxvYWRlcigpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIENoZWNrIGlmIHBheW1lbnQgbWV0aG9kIElEIGJlbG9uZ3MgdG8gU3RyaXBlXG4gICAqL1xuICBpc1N0cmlwZU1ldGhvZChwYXltZW50TWV0aG9kSWQpIHtcbiAgICBpZiAoIXBheW1lbnRNZXRob2RJZCkge1xuICAgICAgcmV0dXJuIGZhbHNlXG4gICAgfVxuXG4gICAgY29uc3Qgc3RyaXBlUGF5bWVudE1ldGhvZHMgPSBbXG4gICAgICAnb3hpZHN0cmlwZScsXG4gICAgICAnb3hpZHN0cmlwZV9jYXJkJyxcbiAgICAgICdveGlkc3RyaXBlX3dhbGxldCcsXG4gICAgXVxuXG4gICAgcmV0dXJuIHN0cmlwZVBheW1lbnRNZXRob2RzLnNvbWUobWV0aG9kID0+XG4gICAgICBwYXltZW50TWV0aG9kSWQudG9Mb3dlckNhc2UoKS5pbmNsdWRlcyhtZXRob2QudG9Mb3dlckNhc2UoKSlcbiAgICApXG4gIH1cblxuICAvKipcbiAgICogTG9hZCBTdHJpcGUuanMgU0RLIGR5bmFtaWNhbGx5XG4gICAqL1xuICBhc3luYyBsb2FkU3RyaXBlU0RLKCkge1xuICAgIGlmICh3aW5kb3cuU3RyaXBlKSB7XG4gICAgICB0aGlzLnN0cmlwZSA9IHdpbmRvdy5TdHJpcGUodGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlKVxuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gTG9hZGluZyBTdHJpcGUuanMgU0RLLi4uJylcblxuICAgIC8vIExvYWQgU3RyaXBlLmpzIHNjcmlwdFxuICAgIGF3YWl0IG5ldyBQcm9taXNlKChyZXNvbHZlLCByZWplY3QpID0+IHtcbiAgICAgIGNvbnN0IHNjcmlwdCA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ3NjcmlwdCcpXG4gICAgICBzY3JpcHQuc3JjID0gJ2h0dHBzOi8vanMuc3RyaXBlLmNvbS92My8nXG4gICAgICBzY3JpcHQuYXN5bmMgPSB0cnVlXG4gICAgICBzY3JpcHQub25sb2FkID0gcmVzb2x2ZVxuICAgICAgc2NyaXB0Lm9uZXJyb3IgPSByZWplY3RcbiAgICAgIGRvY3VtZW50LmhlYWQuYXBwZW5kQ2hpbGQoc2NyaXB0KVxuICAgIH0pXG5cbiAgICB0aGlzLnN0cmlwZSA9IHdpbmRvdy5TdHJpcGUodGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlKVxuICAgIGNvbnNvbGUubG9nKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIFN0cmlwZS5qcyBTREsgbG9hZGVkJylcbiAgfVxuXG4gIC8qKlxuICAgKiBJbml0aWFsaXplIFN0cmlwZSBQYXltZW50IEVsZW1lbnRcbiAgICovXG4gIGFzeW5jIGluaXRpYWxpemVQYXltZW50RWxlbWVudCgpIHtcbiAgICBpZiAoIXRoaXMuc3RyaXBlKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIFN0cmlwZSBTREsgbm90IGxvYWRlZCcpXG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICBpZiAodGhpcy5wYXltZW50RWxlbWVudCkge1xuICAgICAgLy8gQWxyZWFkeSBpbml0aWFsaXplZFxuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gSW5pdGlhbGl6aW5nIFBheW1lbnQgRWxlbWVudC4uLicpXG5cbiAgICAvLyBDcmVhdGUgRWxlbWVudHMgaW5zdGFuY2UgKHdpbGwgYmUgY29uZmlndXJlZCB3aXRoIGNsaWVudCBzZWNyZXQgbGF0ZXIpXG4gICAgdGhpcy5lbGVtZW50cyA9IHRoaXMuc3RyaXBlLmVsZW1lbnRzKHtcbiAgICAgIG1vZGU6ICdwYXltZW50JyxcbiAgICAgIGFtb3VudDogMTAwMCwgLy8gUGxhY2Vob2xkZXIsIHdpbGwgYmUgdXBkYXRlZCB3aXRoIHJlYWwgY2xpZW50IHNlY3JldFxuICAgICAgY3VycmVuY3k6ICdldXInLFxuICAgICAgYXBwZWFyYW5jZToge1xuICAgICAgICB0aGVtZTogJ3N0cmlwZScsXG4gICAgICB9LFxuICAgIH0pXG5cbiAgICAvLyBDcmVhdGUgYW5kIG1vdW50IFBheW1lbnQgRWxlbWVudFxuICAgIHRoaXMucGF5bWVudEVsZW1lbnQgPSB0aGlzLmVsZW1lbnRzLmNyZWF0ZSgncGF5bWVudCcpXG4gICAgdGhpcy5wYXltZW50RWxlbWVudC5tb3VudCh0aGlzLmVsZW1lbnRUYXJnZXQpXG5cbiAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBQYXltZW50IEVsZW1lbnQgaW5pdGlhbGl6ZWQnKVxuICB9XG5cbiAgLyoqXG4gICAqIENvbmZpcm0gcGF5bWVudCB3aXRoIFN0cmlwZSBTREtcbiAgICpcbiAgICogQHBhcmFtIHtzdHJpbmd9IGNsaWVudFNlY3JldCAtIFN0cmlwZSBQYXltZW50SW50ZW50IGNsaWVudCBzZWNyZXRcbiAgICogQHJldHVybnMge1Byb21pc2U8T2JqZWN0Pn0gLSBQYXltZW50IHJlc3VsdFxuICAgKi9cbiAgYXN5bmMgY29uZmlybVBheW1lbnQoY2xpZW50U2VjcmV0KSB7XG4gICAgaWYgKCF0aGlzLnN0cmlwZSB8fCAhdGhpcy5lbGVtZW50cykge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKCdTdHJpcGUgU0RLIG5vdCBpbml0aWFsaXplZCcpXG4gICAgfVxuXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gQ29uZmlybWluZyBwYXltZW50IHdpdGggU3RyaXBlLi4uJylcblxuICAgIC8vIFVwZGF0ZSBlbGVtZW50cyB3aXRoIGNsaWVudCBzZWNyZXRcbiAgICB0aGlzLmVsZW1lbnRzLnVwZGF0ZSh7XG4gICAgICBjbGllbnRTZWNyZXQ6IGNsaWVudFNlY3JldCxcbiAgICB9KVxuXG4gICAgLy8gQ29uZmlybSBwYXltZW50XG4gICAgY29uc3QgcmVzdWx0ID0gYXdhaXQgdGhpcy5zdHJpcGUuY29uZmlybVBheW1lbnQoe1xuICAgICAgZWxlbWVudHM6IHRoaXMuZWxlbWVudHMsXG4gICAgICBjb25maXJtUGFyYW1zOiB7XG4gICAgICAgIHJldHVybl91cmw6IHRoaXMucmV0dXJuVXJsVmFsdWUgfHwgd2luZG93LmxvY2F0aW9uLm9yaWdpbiArICcvb3JkZXInLFxuICAgICAgfSxcbiAgICAgIHJlZGlyZWN0OiAnaWZfcmVxdWlyZWQnLCAvLyBPbmx5IHJlZGlyZWN0IGlmIDNEIFNlY3VyZSBpcyBuZWVkZWRcbiAgICB9KVxuXG4gICAgLy8gSGFuZGxlIHJlc3VsdFxuICAgIGlmIChyZXN1bHQuZXJyb3IpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcihyZXN1bHQuZXJyb3IubWVzc2FnZSB8fCAnUGF5bWVudCBjb25maXJtYXRpb24gZmFpbGVkJylcbiAgICB9XG5cbiAgICBpZiAocmVzdWx0LnBheW1lbnRJbnRlbnQ/LnN0YXR1cyA9PT0gJ3N1Y2NlZWRlZCcpIHtcbiAgICAgIHJldHVybiB7XG4gICAgICAgIHBheW1lbnRJbnRlbnRJZDogcmVzdWx0LnBheW1lbnRJbnRlbnQuaWQsXG4gICAgICAgIHN0YXR1czogcmVzdWx0LnBheW1lbnRJbnRlbnQuc3RhdHVzLFxuICAgICAgICBhbW91bnQ6IHJlc3VsdC5wYXltZW50SW50ZW50LmFtb3VudCxcbiAgICAgICAgY3VycmVuY3k6IHJlc3VsdC5wYXltZW50SW50ZW50LmN1cnJlbmN5LFxuICAgICAgfVxuICAgIH1cblxuICAgIC8vIFBheW1lbnQgbm90IHN1Y2NlZWRlZCB5ZXQgKGUuZy4sIHJlcXVpcmVzIGFjdGlvbilcbiAgICB0aHJvdyBuZXcgRXJyb3IoYFBheW1lbnQgbm90IGNvbmZpcm1lZC4gU3RhdHVzOiAke3Jlc3VsdC5wYXltZW50SW50ZW50Py5zdGF0dXMgfHwgJ3Vua25vd24nfWApXG4gIH1cblxuICAvKipcbiAgICogQnJvYWRjYXN0IG9lOnBheW1lbnQ6Y29uZmlybWVkIGV2ZW50XG4gICAqL1xuICBicm9hZGNhc3RQYXltZW50Q29uZmlybWVkKHBheW1lbnRSZXN1bHQpIHtcbiAgICB0aGlzLmJyb2FkY2FzdCgnb2U6cGF5bWVudDpjb25maXJtZWQnLCB7XG4gICAgICBwcm92aWRlcjogJ3N0cmlwZScsXG4gICAgICBjb250cmFjdElkOiB0aGlzLmN1cnJlbnRDb250cmFjdElkLFxuICAgICAgb3JkZXJJZDogdGhpcy5jdXJyZW50T3JkZXJJZCxcbiAgICAgIHRyYW5zYWN0aW9uSWQ6IHBheW1lbnRSZXN1bHQucGF5bWVudEludGVudElkLFxuICAgICAgbWV0YWRhdGE6IHBheW1lbnRSZXN1bHQsXG4gICAgfSlcblxuICAgIGNvbnNvbGUubG9nKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIFBheW1lbnQgY29uZmlybWVkIGV2ZW50IGRpc3BhdGNoZWQnKVxuICB9XG5cbiAgLyoqXG4gICAqIEJyb2FkY2FzdCBvZTpwYXltZW50OmZhaWxlZCBldmVudFxuICAgKi9cbiAgYnJvYWRjYXN0UGF5bWVudEZhaWxlZChlcnJvcikge1xuICAgIHRoaXMuYnJvYWRjYXN0KCdvZTpwYXltZW50OmZhaWxlZCcsIHtcbiAgICAgIHByb3ZpZGVyOiAnc3RyaXBlJyxcbiAgICAgIGNvbnRyYWN0SWQ6IHRoaXMuY3VycmVudENvbnRyYWN0SWQsXG4gICAgICBvcmRlcklkOiB0aGlzLmN1cnJlbnRPcmRlcklkLFxuICAgICAgZXJyb3I6IGVycm9yLm1lc3NhZ2UgfHwgJ1BheW1lbnQgZmFpbGVkJyxcbiAgICAgIGVycm9yQ29kZTogZXJyb3IuY29kZSB8fCAnU1RSSVBFX0VSUk9SJyxcbiAgICB9KVxuXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gUGF5bWVudCBmYWlsZWQgZXZlbnQgZGlzcGF0Y2hlZCcpXG4gIH1cblxuICAvKipcbiAgICogVUkgSGVscGVyOiBTaG93IFN0cmlwZSBVSVxuICAgKiBTaG93cyB0aGUgZW50aXJlIFN0cmlwZSBwcm92aWRlciB3cmFwcGVyIChub3QganVzdCB0aGUgcGF5bWVudCBlbGVtZW50KVxuICAgKi9cbiAgc2hvd1N0cmlwZVVJKCkge1xuICAgIC8vIFNob3cgdGhlIHdyYXBwZXIgKGNvbnRyb2xsZXIgZWxlbWVudClcbiAgICB0aGlzLmVsZW1lbnQuc3R5bGUuZGlzcGxheSA9ICdibG9jaydcblxuICAgIC8vIEFsc28gc2hvdyB0aGUgcGF5bWVudCBlbGVtZW50IGNvbnRhaW5lciBpZiBpdCBleGlzdHNcbiAgICBpZiAodGhpcy5oYXNFbGVtZW50VGFyZ2V0KSB7XG4gICAgICB0aGlzLmVsZW1lbnRUYXJnZXQuc3R5bGUuZGlzcGxheSA9ICdibG9jaydcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogVUkgSGVscGVyOiBIaWRlIFN0cmlwZSBVSVxuICAgKiBIaWRlcyB0aGUgZW50aXJlIFN0cmlwZSBwcm92aWRlciB3cmFwcGVyXG4gICAqL1xuICBoaWRlU3RyaXBlVUkoKSB7XG4gICAgLy8gSGlkZSB0aGUgd3JhcHBlciAoY29udHJvbGxlciBlbGVtZW50KVxuICAgIHRoaXMuZWxlbWVudC5zdHlsZS5kaXNwbGF5ID0gJ25vbmUnXG4gIH1cblxuICAvKipcbiAgICogVUkgSGVscGVyOiBTaG93IGxvYWRlclxuICAgKi9cbiAgc2hvd0xvYWRlcigpIHtcbiAgICBpZiAodGhpcy5oYXNMb2FkZXJUYXJnZXQpIHtcbiAgICAgIHRoaXMubG9hZGVyVGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFVJIEhlbHBlcjogSGlkZSBsb2FkZXJcbiAgICovXG4gIGhpZGVMb2FkZXIoKSB7XG4gICAgaWYgKHRoaXMuaGFzTG9hZGVyVGFyZ2V0KSB7XG4gICAgICB0aGlzLmxvYWRlclRhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ25vbmUnXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFVJIEhlbHBlcjogU2hvdyBlcnJvciBtZXNzYWdlXG4gICAqL1xuICBzaG93RXJyb3IobWVzc2FnZSkge1xuICAgIGlmICh0aGlzLmhhc0Vycm9yVGFyZ2V0KSB7XG4gICAgICB0aGlzLmVycm9yVGFyZ2V0LnRleHRDb250ZW50ID0gbWVzc2FnZVxuICAgICAgdGhpcy5lcnJvclRhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ2Jsb2NrJ1xuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBVSSBIZWxwZXI6IEhpZGUgZXJyb3IgbWVzc2FnZVxuICAgKi9cbiAgaGlkZUVycm9yKCkge1xuICAgIGlmICh0aGlzLmhhc0Vycm9yVGFyZ2V0KSB7XG4gICAgICB0aGlzLmVycm9yVGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnbm9uZSdcbiAgICAgIHRoaXMuZXJyb3JUYXJnZXQudGV4dENvbnRlbnQgPSAnJ1xuICAgIH1cbiAgfVxufSIsICIvKipcbiAqIFN0cmlwZSBNb2R1bGUgLSBKYXZhU2NyaXB0IEVudHJ5IFBvaW50XG4gKlxuICogSW5pdGlhbGl6ZXMgU3RpbXVsdXMuanMgYW5kIHJlZ2lzdGVycyBhbGwgY29udHJvbGxlcnNcbiAqL1xuXG5pbXBvcnQgeyBBcHBsaWNhdGlvbiB9IGZyb20gXCJAaG90d2lyZWQvc3RpbXVsdXNcIlxuXG4vLyBJbXBvcnQgY29udHJvbGxlcnNcbmltcG9ydCBTdHJpcGVPcmRlckNvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvc3RyaXBlX29yZGVyX2NvbnRyb2xsZXJcIlxuaW1wb3J0IE9yZGVyU3VibWl0Q29udHJvbGxlciBmcm9tIFwiLi9jb250cm9sbGVycy9vcmRlcl9zdWJtaXRfY29udHJvbGxlclwiXG5pbXBvcnQgQWdiVmFsaWRhdGlvbkNvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvYWdiX3ZhbGlkYXRpb25fY29udHJvbGxlclwiXG5pbXBvcnQgT25lUGFnZVN0cmlwZUNvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvb25lcGFnZV9zdHJpcGVfY29udHJvbGxlclwiXG5cbi8vIFN0YXJ0IFN0aW11bHVzIGFwcGxpY2F0aW9uXG53aW5kb3cuU3RpbXVsdXMgPSBBcHBsaWNhdGlvbi5zdGFydCgpXG5cbi8vIFJlZ2lzdGVyIGNvbnRyb2xsZXJzXG5TdGltdWx1cy5yZWdpc3RlcihcInN0cmlwZS1vcmRlclwiLCBTdHJpcGVPcmRlckNvbnRyb2xsZXIpXG5TdGltdWx1cy5yZWdpc3RlcihcIm9yZGVyLXN1Ym1pdFwiLCBPcmRlclN1Ym1pdENvbnRyb2xsZXIpXG5TdGltdWx1cy5yZWdpc3RlcihcImFnYi12YWxpZGF0aW9uXCIsIEFnYlZhbGlkYXRpb25Db250cm9sbGVyKVxuU3RpbXVsdXMucmVnaXN0ZXIoXCJvbmVwYWdlLXN0cmlwZVwiLCBPbmVQYWdlU3RyaXBlQ29udHJvbGxlcilcblxuLy8gRGVidWcgbW9kZSBpbiBkZXZlbG9wbWVudFxuaWYgKHByb2Nlc3MuZW52Lk5PREVfRU5WID09PSAnZGV2ZWxvcG1lbnQnKSB7XG4gIFN0aW11bHVzLmRlYnVnID0gdHJ1ZVxuICBjb25zb2xlLmxvZygnU3RyaXBlIE1vZHVsZTogU3RpbXVsdXMgaW5pdGlhbGl6ZWQgd2l0aCBjb250cm9sbGVyczonLCBTdGltdWx1cy5yb3V0ZXIubW9kdWxlc0J5SWRlbnRpZmllcilcbn1cblxuY29uc29sZS5sb2coJ1N0cmlwZSBNb2R1bGU6IEphdmFTY3JpcHQgbG9hZGVkIGFuZCByZWFkeScpXG4iXSwKICAibWFwcGluZ3MiOiAiOzs7Ozs7Ozs7QUFJQSxNQUFNLGdCQUFOLE1BQW9CO0FBQUEsSUFDaEIsWUFBWSxhQUFhLFdBQVcsY0FBYztBQUM5QyxXQUFLLGNBQWM7QUFDbkIsV0FBSyxZQUFZO0FBQ2pCLFdBQUssZUFBZTtBQUNwQixXQUFLLG9CQUFvQixvQkFBSSxJQUFJO0FBQUEsSUFDckM7QUFBQSxJQUNBLFVBQVU7QUFDTixXQUFLLFlBQVksaUJBQWlCLEtBQUssV0FBVyxNQUFNLEtBQUssWUFBWTtBQUFBLElBQzdFO0FBQUEsSUFDQSxhQUFhO0FBQ1QsV0FBSyxZQUFZLG9CQUFvQixLQUFLLFdBQVcsTUFBTSxLQUFLLFlBQVk7QUFBQSxJQUNoRjtBQUFBLElBQ0EsaUJBQWlCLFNBQVM7QUFDdEIsV0FBSyxrQkFBa0IsSUFBSSxPQUFPO0FBQUEsSUFDdEM7QUFBQSxJQUNBLG9CQUFvQixTQUFTO0FBQ3pCLFdBQUssa0JBQWtCLE9BQU8sT0FBTztBQUFBLElBQ3pDO0FBQUEsSUFDQSxZQUFZLE9BQU87QUFDZixZQUFNLGdCQUFnQixZQUFZLEtBQUs7QUFDdkMsaUJBQVcsV0FBVyxLQUFLLFVBQVU7QUFDakMsWUFBSSxjQUFjLDZCQUE2QjtBQUMzQztBQUFBLFFBQ0osT0FDSztBQUNELGtCQUFRLFlBQVksYUFBYTtBQUFBLFFBQ3JDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGNBQWM7QUFDVixhQUFPLEtBQUssa0JBQWtCLE9BQU87QUFBQSxJQUN6QztBQUFBLElBQ0EsSUFBSSxXQUFXO0FBQ1gsYUFBTyxNQUFNLEtBQUssS0FBSyxpQkFBaUIsRUFBRSxLQUFLLENBQUMsTUFBTSxVQUFVO0FBQzVELGNBQU0sWUFBWSxLQUFLLE9BQU8sYUFBYSxNQUFNO0FBQ2pELGVBQU8sWUFBWSxhQUFhLEtBQUssWUFBWSxhQUFhLElBQUk7QUFBQSxNQUN0RSxDQUFDO0FBQUEsSUFDTDtBQUFBLEVBQ0o7QUFDQSxXQUFTLFlBQVksT0FBTztBQUN4QixRQUFJLGlDQUFpQyxPQUFPO0FBQ3hDLGFBQU87QUFBQSxJQUNYLE9BQ0s7QUFDRCxZQUFNLEVBQUUseUJBQXlCLElBQUk7QUFDckMsYUFBTyxPQUFPLE9BQU8sT0FBTztBQUFBLFFBQ3hCLDZCQUE2QjtBQUFBLFFBQzdCLDJCQUEyQjtBQUN2QixlQUFLLDhCQUE4QjtBQUNuQyxtQ0FBeUIsS0FBSyxJQUFJO0FBQUEsUUFDdEM7QUFBQSxNQUNKLENBQUM7QUFBQSxJQUNMO0FBQUEsRUFDSjtBQUVBLE1BQU0sYUFBTixNQUFpQjtBQUFBLElBQ2IsWUFBWSxhQUFhO0FBQ3JCLFdBQUssY0FBYztBQUNuQixXQUFLLG9CQUFvQixvQkFBSSxJQUFJO0FBQ2pDLFdBQUssVUFBVTtBQUFBLElBQ25CO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssU0FBUztBQUNmLGFBQUssVUFBVTtBQUNmLGFBQUssZUFBZSxRQUFRLENBQUMsa0JBQWtCLGNBQWMsUUFBUSxDQUFDO0FBQUEsTUFDMUU7QUFBQSxJQUNKO0FBQUEsSUFDQSxPQUFPO0FBQ0gsVUFBSSxLQUFLLFNBQVM7QUFDZCxhQUFLLFVBQVU7QUFDZixhQUFLLGVBQWUsUUFBUSxDQUFDLGtCQUFrQixjQUFjLFdBQVcsQ0FBQztBQUFBLE1BQzdFO0FBQUEsSUFDSjtBQUFBLElBQ0EsSUFBSSxpQkFBaUI7QUFDakIsYUFBTyxNQUFNLEtBQUssS0FBSyxrQkFBa0IsT0FBTyxDQUFDLEVBQUUsT0FBTyxDQUFDLFdBQVcsUUFBUSxVQUFVLE9BQU8sTUFBTSxLQUFLLElBQUksT0FBTyxDQUFDLENBQUMsR0FBRyxDQUFDLENBQUM7QUFBQSxJQUNoSTtBQUFBLElBQ0EsaUJBQWlCLFNBQVM7QUFDdEIsV0FBSyw2QkFBNkIsT0FBTyxFQUFFLGlCQUFpQixPQUFPO0FBQUEsSUFDdkU7QUFBQSxJQUNBLG9CQUFvQixTQUFTLHNCQUFzQixPQUFPO0FBQ3RELFdBQUssNkJBQTZCLE9BQU8sRUFBRSxvQkFBb0IsT0FBTztBQUN0RSxVQUFJO0FBQ0EsYUFBSyw4QkFBOEIsT0FBTztBQUFBLElBQ2xEO0FBQUEsSUFDQSxZQUFZQSxRQUFPLFNBQVMsU0FBUyxDQUFDLEdBQUc7QUFDckMsV0FBSyxZQUFZLFlBQVlBLFFBQU8sU0FBUyxPQUFPLElBQUksTUFBTTtBQUFBLElBQ2xFO0FBQUEsSUFDQSw4QkFBOEIsU0FBUztBQUNuQyxZQUFNLGdCQUFnQixLQUFLLDZCQUE2QixPQUFPO0FBQy9ELFVBQUksQ0FBQyxjQUFjLFlBQVksR0FBRztBQUM5QixzQkFBYyxXQUFXO0FBQ3pCLGFBQUssNkJBQTZCLE9BQU87QUFBQSxNQUM3QztBQUFBLElBQ0o7QUFBQSxJQUNBLDZCQUE2QixTQUFTO0FBQ2xDLFlBQU0sRUFBRSxhQUFhLFdBQVcsYUFBYSxJQUFJO0FBQ2pELFlBQU0sbUJBQW1CLEtBQUssb0NBQW9DLFdBQVc7QUFDN0UsWUFBTSxXQUFXLEtBQUssU0FBUyxXQUFXLFlBQVk7QUFDdEQsdUJBQWlCLE9BQU8sUUFBUTtBQUNoQyxVQUFJLGlCQUFpQixRQUFRO0FBQ3pCLGFBQUssa0JBQWtCLE9BQU8sV0FBVztBQUFBLElBQ2pEO0FBQUEsSUFDQSw2QkFBNkIsU0FBUztBQUNsQyxZQUFNLEVBQUUsYUFBYSxXQUFXLGFBQWEsSUFBSTtBQUNqRCxhQUFPLEtBQUssbUJBQW1CLGFBQWEsV0FBVyxZQUFZO0FBQUEsSUFDdkU7QUFBQSxJQUNBLG1CQUFtQixhQUFhLFdBQVcsY0FBYztBQUNyRCxZQUFNLG1CQUFtQixLQUFLLG9DQUFvQyxXQUFXO0FBQzdFLFlBQU0sV0FBVyxLQUFLLFNBQVMsV0FBVyxZQUFZO0FBQ3RELFVBQUksZ0JBQWdCLGlCQUFpQixJQUFJLFFBQVE7QUFDakQsVUFBSSxDQUFDLGVBQWU7QUFDaEIsd0JBQWdCLEtBQUssb0JBQW9CLGFBQWEsV0FBVyxZQUFZO0FBQzdFLHlCQUFpQixJQUFJLFVBQVUsYUFBYTtBQUFBLE1BQ2hEO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLG9CQUFvQixhQUFhLFdBQVcsY0FBYztBQUN0RCxZQUFNLGdCQUFnQixJQUFJLGNBQWMsYUFBYSxXQUFXLFlBQVk7QUFDNUUsVUFBSSxLQUFLLFNBQVM7QUFDZCxzQkFBYyxRQUFRO0FBQUEsTUFDMUI7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0Esb0NBQW9DLGFBQWE7QUFDN0MsVUFBSSxtQkFBbUIsS0FBSyxrQkFBa0IsSUFBSSxXQUFXO0FBQzdELFVBQUksQ0FBQyxrQkFBa0I7QUFDbkIsMkJBQW1CLG9CQUFJLElBQUk7QUFDM0IsYUFBSyxrQkFBa0IsSUFBSSxhQUFhLGdCQUFnQjtBQUFBLE1BQzVEO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLFNBQVMsV0FBVyxjQUFjO0FBQzlCLFlBQU0sUUFBUSxDQUFDLFNBQVM7QUFDeEIsYUFBTyxLQUFLLFlBQVksRUFDbkIsS0FBSyxFQUNMLFFBQVEsQ0FBQyxRQUFRO0FBQ2xCLGNBQU0sS0FBSyxHQUFHLGFBQWEsR0FBRyxJQUFJLEtBQUssR0FBRyxHQUFHLEdBQUcsRUFBRTtBQUFBLE1BQ3RELENBQUM7QUFDRCxhQUFPLE1BQU0sS0FBSyxHQUFHO0FBQUEsSUFDekI7QUFBQSxFQUNKO0FBRUEsTUFBTSxpQ0FBaUM7QUFBQSxJQUNuQyxLQUFLLEVBQUUsT0FBTyxNQUFNLEdBQUc7QUFDbkIsVUFBSTtBQUNBLGNBQU0sZ0JBQWdCO0FBQzFCLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxRQUFRLEVBQUUsT0FBTyxNQUFNLEdBQUc7QUFDdEIsVUFBSTtBQUNBLGNBQU0sZUFBZTtBQUN6QixhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsS0FBSyxFQUFFLE9BQU8sT0FBTyxRQUFRLEdBQUc7QUFDNUIsVUFBSSxPQUFPO0FBQ1AsZUFBTyxZQUFZLE1BQU07QUFBQSxNQUM3QixPQUNLO0FBQ0QsZUFBTztBQUFBLE1BQ1g7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUNBLE1BQU0sb0JBQW9CO0FBQzFCLFdBQVMsNEJBQTRCLGtCQUFrQjtBQUNuRCxVQUFNLFNBQVMsaUJBQWlCLEtBQUs7QUFDckMsVUFBTSxVQUFVLE9BQU8sTUFBTSxpQkFBaUIsS0FBSyxDQUFDO0FBQ3BELFFBQUksWUFBWSxRQUFRLENBQUM7QUFDekIsUUFBSSxZQUFZLFFBQVEsQ0FBQztBQUN6QixRQUFJLGFBQWEsQ0FBQyxDQUFDLFdBQVcsU0FBUyxVQUFVLEVBQUUsU0FBUyxTQUFTLEdBQUc7QUFDcEUsbUJBQWEsSUFBSSxTQUFTO0FBQzFCLGtCQUFZO0FBQUEsSUFDaEI7QUFDQSxXQUFPO0FBQUEsTUFDSCxhQUFhLGlCQUFpQixRQUFRLENBQUMsQ0FBQztBQUFBLE1BQ3hDO0FBQUEsTUFDQSxjQUFjLFFBQVEsQ0FBQyxJQUFJLGtCQUFrQixRQUFRLENBQUMsQ0FBQyxJQUFJLENBQUM7QUFBQSxNQUM1RCxZQUFZLFFBQVEsQ0FBQztBQUFBLE1BQ3JCLFlBQVksUUFBUSxDQUFDO0FBQUEsTUFDckIsV0FBVyxRQUFRLENBQUMsS0FBSztBQUFBLElBQzdCO0FBQUEsRUFDSjtBQUNBLFdBQVMsaUJBQWlCLGlCQUFpQjtBQUN2QyxRQUFJLG1CQUFtQixVQUFVO0FBQzdCLGFBQU87QUFBQSxJQUNYLFdBQ1MsbUJBQW1CLFlBQVk7QUFDcEMsYUFBTztBQUFBLElBQ1g7QUFBQSxFQUNKO0FBQ0EsV0FBUyxrQkFBa0IsY0FBYztBQUNyQyxXQUFPLGFBQ0YsTUFBTSxHQUFHLEVBQ1QsT0FBTyxDQUFDLFNBQVMsVUFBVSxPQUFPLE9BQU8sU0FBUyxFQUFFLENBQUMsTUFBTSxRQUFRLE1BQU0sRUFBRSxDQUFDLEdBQUcsQ0FBQyxLQUFLLEtBQUssS0FBSyxFQUFFLENBQUMsR0FBRyxDQUFDLENBQUM7QUFBQSxFQUNoSDtBQUNBLFdBQVMscUJBQXFCLGFBQWE7QUFDdkMsUUFBSSxlQUFlLFFBQVE7QUFDdkIsYUFBTztBQUFBLElBQ1gsV0FDUyxlQUFlLFVBQVU7QUFDOUIsYUFBTztBQUFBLElBQ1g7QUFBQSxFQUNKO0FBRUEsV0FBUyxTQUFTLE9BQU87QUFDckIsV0FBTyxNQUFNLFFBQVEsdUJBQXVCLENBQUMsR0FBRyxTQUFTLEtBQUssWUFBWSxDQUFDO0FBQUEsRUFDL0U7QUFDQSxXQUFTLGtCQUFrQixPQUFPO0FBQzlCLFdBQU8sU0FBUyxNQUFNLFFBQVEsT0FBTyxHQUFHLEVBQUUsUUFBUSxPQUFPLEdBQUcsQ0FBQztBQUFBLEVBQ2pFO0FBQ0EsV0FBUyxXQUFXLE9BQU87QUFDdkIsV0FBTyxNQUFNLE9BQU8sQ0FBQyxFQUFFLFlBQVksSUFBSSxNQUFNLE1BQU0sQ0FBQztBQUFBLEVBQ3hEO0FBQ0EsV0FBUyxVQUFVLE9BQU87QUFDdEIsV0FBTyxNQUFNLFFBQVEsWUFBWSxDQUFDLEdBQUcsU0FBUyxJQUFJLEtBQUssWUFBWSxDQUFDLEVBQUU7QUFBQSxFQUMxRTtBQUNBLFdBQVMsU0FBUyxPQUFPO0FBQ3JCLFdBQU8sTUFBTSxNQUFNLFNBQVMsS0FBSyxDQUFDO0FBQUEsRUFDdEM7QUFFQSxXQUFTLFlBQVksUUFBUTtBQUN6QixXQUFPLFdBQVcsUUFBUSxXQUFXO0FBQUEsRUFDekM7QUFDQSxXQUFTLFlBQVksUUFBUSxVQUFVO0FBQ25DLFdBQU8sT0FBTyxVQUFVLGVBQWUsS0FBSyxRQUFRLFFBQVE7QUFBQSxFQUNoRTtBQUVBLE1BQU0sZUFBZSxDQUFDLFFBQVEsUUFBUSxPQUFPLE9BQU87QUFDcEQsTUFBTSxTQUFOLE1BQWE7QUFBQSxJQUNULFlBQVksU0FBUyxPQUFPLFlBQVksUUFBUTtBQUM1QyxXQUFLLFVBQVU7QUFDZixXQUFLLFFBQVE7QUFDYixXQUFLLGNBQWMsV0FBVyxlQUFlO0FBQzdDLFdBQUssWUFBWSxXQUFXLGFBQWEsOEJBQThCLE9BQU8sS0FBSyxNQUFNLG9CQUFvQjtBQUM3RyxXQUFLLGVBQWUsV0FBVyxnQkFBZ0IsQ0FBQztBQUNoRCxXQUFLLGFBQWEsV0FBVyxjQUFjLE1BQU0sb0JBQW9CO0FBQ3JFLFdBQUssYUFBYSxXQUFXLGNBQWMsTUFBTSxxQkFBcUI7QUFDdEUsV0FBSyxZQUFZLFdBQVcsYUFBYTtBQUN6QyxXQUFLLFNBQVM7QUFBQSxJQUNsQjtBQUFBLElBQ0EsT0FBTyxTQUFTLE9BQU8sUUFBUTtBQUMzQixhQUFPLElBQUksS0FBSyxNQUFNLFNBQVMsTUFBTSxPQUFPLDRCQUE0QixNQUFNLE9BQU8sR0FBRyxNQUFNO0FBQUEsSUFDbEc7QUFBQSxJQUNBLFdBQVc7QUFDUCxZQUFNLGNBQWMsS0FBSyxZQUFZLElBQUksS0FBSyxTQUFTLEtBQUs7QUFDNUQsWUFBTSxjQUFjLEtBQUssa0JBQWtCLElBQUksS0FBSyxlQUFlLEtBQUs7QUFDeEUsYUFBTyxHQUFHLEtBQUssU0FBUyxHQUFHLFdBQVcsR0FBRyxXQUFXLEtBQUssS0FBSyxVQUFVLElBQUksS0FBSyxVQUFVO0FBQUEsSUFDL0Y7QUFBQSxJQUNBLDBCQUEwQixPQUFPO0FBQzdCLFVBQUksQ0FBQyxLQUFLLFdBQVc7QUFDakIsZUFBTztBQUFBLE1BQ1g7QUFDQSxZQUFNLFVBQVUsS0FBSyxVQUFVLE1BQU0sR0FBRztBQUN4QyxVQUFJLEtBQUssc0JBQXNCLE9BQU8sT0FBTyxHQUFHO0FBQzVDLGVBQU87QUFBQSxNQUNYO0FBQ0EsWUFBTSxpQkFBaUIsUUFBUSxPQUFPLENBQUMsUUFBUSxDQUFDLGFBQWEsU0FBUyxHQUFHLENBQUMsRUFBRSxDQUFDO0FBQzdFLFVBQUksQ0FBQyxnQkFBZ0I7QUFDakIsZUFBTztBQUFBLE1BQ1g7QUFDQSxVQUFJLENBQUMsWUFBWSxLQUFLLGFBQWEsY0FBYyxHQUFHO0FBQ2hELGNBQU0sZ0NBQWdDLEtBQUssU0FBUyxFQUFFO0FBQUEsTUFDMUQ7QUFDQSxhQUFPLEtBQUssWUFBWSxjQUFjLEVBQUUsWUFBWSxNQUFNLE1BQU0sSUFBSSxZQUFZO0FBQUEsSUFDcEY7QUFBQSxJQUNBLHVCQUF1QixPQUFPO0FBQzFCLFVBQUksQ0FBQyxLQUFLLFdBQVc7QUFDakIsZUFBTztBQUFBLE1BQ1g7QUFDQSxZQUFNLFVBQVUsQ0FBQyxLQUFLLFNBQVM7QUFDL0IsVUFBSSxLQUFLLHNCQUFzQixPQUFPLE9BQU8sR0FBRztBQUM1QyxlQUFPO0FBQUEsTUFDWDtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxZQUFNLFNBQVMsQ0FBQztBQUNoQixZQUFNLFVBQVUsSUFBSSxPQUFPLFNBQVMsS0FBSyxVQUFVLGdCQUFnQixHQUFHO0FBQ3RFLGlCQUFXLEVBQUUsTUFBTSxNQUFNLEtBQUssTUFBTSxLQUFLLEtBQUssUUFBUSxVQUFVLEdBQUc7QUFDL0QsY0FBTSxRQUFRLEtBQUssTUFBTSxPQUFPO0FBQ2hDLGNBQU0sTUFBTSxTQUFTLE1BQU0sQ0FBQztBQUM1QixZQUFJLEtBQUs7QUFDTCxpQkFBTyxTQUFTLEdBQUcsQ0FBQyxJQUFJLFNBQVMsS0FBSztBQUFBLFFBQzFDO0FBQUEsTUFDSjtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxJQUFJLGtCQUFrQjtBQUNsQixhQUFPLHFCQUFxQixLQUFLLFdBQVc7QUFBQSxJQUNoRDtBQUFBLElBQ0EsSUFBSSxjQUFjO0FBQ2QsYUFBTyxLQUFLLE9BQU87QUFBQSxJQUN2QjtBQUFBLElBQ0Esc0JBQXNCLE9BQU8sU0FBUztBQUNsQyxZQUFNLENBQUMsTUFBTSxNQUFNLEtBQUssS0FBSyxJQUFJLGFBQWEsSUFBSSxDQUFDLGFBQWEsUUFBUSxTQUFTLFFBQVEsQ0FBQztBQUMxRixhQUFPLE1BQU0sWUFBWSxRQUFRLE1BQU0sWUFBWSxRQUFRLE1BQU0sV0FBVyxPQUFPLE1BQU0sYUFBYTtBQUFBLElBQzFHO0FBQUEsRUFDSjtBQUNBLE1BQU0sb0JBQW9CO0FBQUEsSUFDdEIsR0FBRyxNQUFNO0FBQUEsSUFDVCxRQUFRLE1BQU07QUFBQSxJQUNkLE1BQU0sTUFBTTtBQUFBLElBQ1osU0FBUyxNQUFNO0FBQUEsSUFDZixPQUFPLENBQUMsTUFBTyxFQUFFLGFBQWEsTUFBTSxLQUFLLFdBQVcsVUFBVTtBQUFBLElBQzlELFFBQVEsTUFBTTtBQUFBLElBQ2QsVUFBVSxNQUFNO0FBQUEsRUFDcEI7QUFDQSxXQUFTLDhCQUE4QixTQUFTO0FBQzVDLFVBQU0sVUFBVSxRQUFRLFFBQVEsWUFBWTtBQUM1QyxRQUFJLFdBQVcsbUJBQW1CO0FBQzlCLGFBQU8sa0JBQWtCLE9BQU8sRUFBRSxPQUFPO0FBQUEsSUFDN0M7QUFBQSxFQUNKO0FBQ0EsV0FBUyxNQUFNLFNBQVM7QUFDcEIsVUFBTSxJQUFJLE1BQU0sT0FBTztBQUFBLEVBQzNCO0FBQ0EsV0FBUyxTQUFTLE9BQU87QUFDckIsUUFBSTtBQUNBLGFBQU8sS0FBSyxNQUFNLEtBQUs7QUFBQSxJQUMzQixTQUNPLEtBQUs7QUFDUixhQUFPO0FBQUEsSUFDWDtBQUFBLEVBQ0o7QUFFQSxNQUFNLFVBQU4sTUFBYztBQUFBLElBQ1YsWUFBWSxTQUFTLFFBQVE7QUFDekIsV0FBSyxVQUFVO0FBQ2YsV0FBSyxTQUFTO0FBQUEsSUFDbEI7QUFBQSxJQUNBLElBQUksUUFBUTtBQUNSLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksY0FBYztBQUNkLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksZUFBZTtBQUNmLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLFlBQVksT0FBTztBQUNmLFlBQU0sY0FBYyxLQUFLLG1CQUFtQixLQUFLO0FBQ2pELFVBQUksS0FBSyxxQkFBcUIsS0FBSyxLQUFLLEtBQUssb0JBQW9CLFdBQVcsR0FBRztBQUMzRSxhQUFLLGdCQUFnQixXQUFXO0FBQUEsTUFDcEM7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLFlBQVk7QUFDWixhQUFPLEtBQUssT0FBTztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxZQUFNLFNBQVMsS0FBSyxXQUFXLEtBQUssVUFBVTtBQUM5QyxVQUFJLE9BQU8sVUFBVSxZQUFZO0FBQzdCLGVBQU87QUFBQSxNQUNYO0FBQ0EsWUFBTSxJQUFJLE1BQU0sV0FBVyxLQUFLLE1BQU0sa0NBQWtDLEtBQUssVUFBVSxHQUFHO0FBQUEsSUFDOUY7QUFBQSxJQUNBLG9CQUFvQixPQUFPO0FBQ3ZCLFlBQU0sRUFBRSxRQUFRLElBQUksS0FBSztBQUN6QixZQUFNLEVBQUUsd0JBQXdCLElBQUksS0FBSyxRQUFRO0FBQ2pELFlBQU0sRUFBRSxXQUFXLElBQUksS0FBSztBQUM1QixVQUFJLFNBQVM7QUFDYixpQkFBVyxDQUFDLE1BQU0sS0FBSyxLQUFLLE9BQU8sUUFBUSxLQUFLLFlBQVksR0FBRztBQUMzRCxZQUFJLFFBQVEseUJBQXlCO0FBQ2pDLGdCQUFNLFNBQVMsd0JBQXdCLElBQUk7QUFDM0MsbUJBQVMsVUFBVSxPQUFPLEVBQUUsTUFBTSxPQUFPLE9BQU8sU0FBUyxXQUFXLENBQUM7QUFBQSxRQUN6RSxPQUNLO0FBQ0Q7QUFBQSxRQUNKO0FBQUEsTUFDSjtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxtQkFBbUIsT0FBTztBQUN0QixhQUFPLE9BQU8sT0FBTyxPQUFPLEVBQUUsUUFBUSxLQUFLLE9BQU8sT0FBTyxDQUFDO0FBQUEsSUFDOUQ7QUFBQSxJQUNBLGdCQUFnQixPQUFPO0FBQ25CLFlBQU0sRUFBRSxRQUFRLGNBQWMsSUFBSTtBQUNsQyxVQUFJO0FBQ0EsYUFBSyxPQUFPLEtBQUssS0FBSyxZQUFZLEtBQUs7QUFDdkMsYUFBSyxRQUFRLGlCQUFpQixLQUFLLFlBQVksRUFBRSxPQUFPLFFBQVEsZUFBZSxRQUFRLEtBQUssV0FBVyxDQUFDO0FBQUEsTUFDNUcsU0FDT0EsUUFBTztBQUNWLGNBQU0sRUFBRSxZQUFZLFlBQVksU0FBUyxNQUFNLElBQUk7QUFDbkQsY0FBTSxTQUFTLEVBQUUsWUFBWSxZQUFZLFNBQVMsT0FBTyxNQUFNO0FBQy9ELGFBQUssUUFBUSxZQUFZQSxRQUFPLG9CQUFvQixLQUFLLE1BQU0sS0FBSyxNQUFNO0FBQUEsTUFDOUU7QUFBQSxJQUNKO0FBQUEsSUFDQSxxQkFBcUIsT0FBTztBQUN4QixZQUFNLGNBQWMsTUFBTTtBQUMxQixVQUFJLGlCQUFpQixpQkFBaUIsS0FBSyxPQUFPLDBCQUEwQixLQUFLLEdBQUc7QUFDaEYsZUFBTztBQUFBLE1BQ1g7QUFDQSxVQUFJLGlCQUFpQixjQUFjLEtBQUssT0FBTyx1QkFBdUIsS0FBSyxHQUFHO0FBQzFFLGVBQU87QUFBQSxNQUNYO0FBQ0EsVUFBSSxLQUFLLFlBQVksYUFBYTtBQUM5QixlQUFPO0FBQUEsTUFDWCxXQUNTLHVCQUF1QixXQUFXLEtBQUssUUFBUSxTQUFTLFdBQVcsR0FBRztBQUMzRSxlQUFPLEtBQUssTUFBTSxnQkFBZ0IsV0FBVztBQUFBLE1BQ2pELE9BQ0s7QUFDRCxlQUFPLEtBQUssTUFBTSxnQkFBZ0IsS0FBSyxPQUFPLE9BQU87QUFBQSxNQUN6RDtBQUFBLElBQ0o7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksUUFBUTtBQUNSLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxFQUNKO0FBRUEsTUFBTSxrQkFBTixNQUFzQjtBQUFBLElBQ2xCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssdUJBQXVCLEVBQUUsWUFBWSxNQUFNLFdBQVcsTUFBTSxTQUFTLEtBQUs7QUFDL0UsV0FBSyxVQUFVO0FBQ2YsV0FBSyxVQUFVO0FBQ2YsV0FBSyxXQUFXO0FBQ2hCLFdBQUssV0FBVyxvQkFBSSxJQUFJO0FBQ3hCLFdBQUssbUJBQW1CLElBQUksaUJBQWlCLENBQUMsY0FBYyxLQUFLLGlCQUFpQixTQUFTLENBQUM7QUFBQSxJQUNoRztBQUFBLElBQ0EsUUFBUTtBQUNKLFVBQUksQ0FBQyxLQUFLLFNBQVM7QUFDZixhQUFLLFVBQVU7QUFDZixhQUFLLGlCQUFpQixRQUFRLEtBQUssU0FBUyxLQUFLLG9CQUFvQjtBQUNyRSxhQUFLLFFBQVE7QUFBQSxNQUNqQjtBQUFBLElBQ0o7QUFBQSxJQUNBLE1BQU0sVUFBVTtBQUNaLFVBQUksS0FBSyxTQUFTO0FBQ2QsYUFBSyxpQkFBaUIsV0FBVztBQUNqQyxhQUFLLFVBQVU7QUFBQSxNQUNuQjtBQUNBLGVBQVM7QUFDVCxVQUFJLENBQUMsS0FBSyxTQUFTO0FBQ2YsYUFBSyxpQkFBaUIsUUFBUSxLQUFLLFNBQVMsS0FBSyxvQkFBb0I7QUFDckUsYUFBSyxVQUFVO0FBQUEsTUFDbkI7QUFBQSxJQUNKO0FBQUEsSUFDQSxPQUFPO0FBQ0gsVUFBSSxLQUFLLFNBQVM7QUFDZCxhQUFLLGlCQUFpQixZQUFZO0FBQ2xDLGFBQUssaUJBQWlCLFdBQVc7QUFDakMsYUFBSyxVQUFVO0FBQUEsTUFDbkI7QUFBQSxJQUNKO0FBQUEsSUFDQSxVQUFVO0FBQ04sVUFBSSxLQUFLLFNBQVM7QUFDZCxjQUFNLFVBQVUsSUFBSSxJQUFJLEtBQUssb0JBQW9CLENBQUM7QUFDbEQsbUJBQVcsV0FBVyxNQUFNLEtBQUssS0FBSyxRQUFRLEdBQUc7QUFDN0MsY0FBSSxDQUFDLFFBQVEsSUFBSSxPQUFPLEdBQUc7QUFDdkIsaUJBQUssY0FBYyxPQUFPO0FBQUEsVUFDOUI7QUFBQSxRQUNKO0FBQ0EsbUJBQVcsV0FBVyxNQUFNLEtBQUssT0FBTyxHQUFHO0FBQ3ZDLGVBQUssV0FBVyxPQUFPO0FBQUEsUUFDM0I7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsaUJBQWlCLFdBQVc7QUFDeEIsVUFBSSxLQUFLLFNBQVM7QUFDZCxtQkFBVyxZQUFZLFdBQVc7QUFDOUIsZUFBSyxnQkFBZ0IsUUFBUTtBQUFBLFFBQ2pDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGdCQUFnQixVQUFVO0FBQ3RCLFVBQUksU0FBUyxRQUFRLGNBQWM7QUFDL0IsYUFBSyx1QkFBdUIsU0FBUyxRQUFRLFNBQVMsYUFBYTtBQUFBLE1BQ3ZFLFdBQ1MsU0FBUyxRQUFRLGFBQWE7QUFDbkMsYUFBSyxvQkFBb0IsU0FBUyxZQUFZO0FBQzlDLGFBQUssa0JBQWtCLFNBQVMsVUFBVTtBQUFBLE1BQzlDO0FBQUEsSUFDSjtBQUFBLElBQ0EsdUJBQXVCLFNBQVMsZUFBZTtBQUMzQyxVQUFJLEtBQUssU0FBUyxJQUFJLE9BQU8sR0FBRztBQUM1QixZQUFJLEtBQUssU0FBUywyQkFBMkIsS0FBSyxhQUFhLE9BQU8sR0FBRztBQUNyRSxlQUFLLFNBQVMsd0JBQXdCLFNBQVMsYUFBYTtBQUFBLFFBQ2hFLE9BQ0s7QUFDRCxlQUFLLGNBQWMsT0FBTztBQUFBLFFBQzlCO0FBQUEsTUFDSixXQUNTLEtBQUssYUFBYSxPQUFPLEdBQUc7QUFDakMsYUFBSyxXQUFXLE9BQU87QUFBQSxNQUMzQjtBQUFBLElBQ0o7QUFBQSxJQUNBLG9CQUFvQixPQUFPO0FBQ3ZCLGlCQUFXLFFBQVEsTUFBTSxLQUFLLEtBQUssR0FBRztBQUNsQyxjQUFNLFVBQVUsS0FBSyxnQkFBZ0IsSUFBSTtBQUN6QyxZQUFJLFNBQVM7QUFDVCxlQUFLLFlBQVksU0FBUyxLQUFLLGFBQWE7QUFBQSxRQUNoRDtBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxrQkFBa0IsT0FBTztBQUNyQixpQkFBVyxRQUFRLE1BQU0sS0FBSyxLQUFLLEdBQUc7QUFDbEMsY0FBTSxVQUFVLEtBQUssZ0JBQWdCLElBQUk7QUFDekMsWUFBSSxXQUFXLEtBQUssZ0JBQWdCLE9BQU8sR0FBRztBQUMxQyxlQUFLLFlBQVksU0FBUyxLQUFLLFVBQVU7QUFBQSxRQUM3QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxhQUFhLFNBQVM7QUFDbEIsYUFBTyxLQUFLLFNBQVMsYUFBYSxPQUFPO0FBQUEsSUFDN0M7QUFBQSxJQUNBLG9CQUFvQixPQUFPLEtBQUssU0FBUztBQUNyQyxhQUFPLEtBQUssU0FBUyxvQkFBb0IsSUFBSTtBQUFBLElBQ2pEO0FBQUEsSUFDQSxZQUFZLE1BQU0sV0FBVztBQUN6QixpQkFBVyxXQUFXLEtBQUssb0JBQW9CLElBQUksR0FBRztBQUNsRCxrQkFBVSxLQUFLLE1BQU0sT0FBTztBQUFBLE1BQ2hDO0FBQUEsSUFDSjtBQUFBLElBQ0EsZ0JBQWdCLE1BQU07QUFDbEIsVUFBSSxLQUFLLFlBQVksS0FBSyxjQUFjO0FBQ3BDLGVBQU87QUFBQSxNQUNYO0FBQUEsSUFDSjtBQUFBLElBQ0EsZ0JBQWdCLFNBQVM7QUFDckIsVUFBSSxRQUFRLGVBQWUsS0FBSyxRQUFRLGFBQWE7QUFDakQsZUFBTztBQUFBLE1BQ1gsT0FDSztBQUNELGVBQU8sS0FBSyxRQUFRLFNBQVMsT0FBTztBQUFBLE1BQ3hDO0FBQUEsSUFDSjtBQUFBLElBQ0EsV0FBVyxTQUFTO0FBQ2hCLFVBQUksQ0FBQyxLQUFLLFNBQVMsSUFBSSxPQUFPLEdBQUc7QUFDN0IsWUFBSSxLQUFLLGdCQUFnQixPQUFPLEdBQUc7QUFDL0IsZUFBSyxTQUFTLElBQUksT0FBTztBQUN6QixjQUFJLEtBQUssU0FBUyxnQkFBZ0I7QUFDOUIsaUJBQUssU0FBUyxlQUFlLE9BQU87QUFBQSxVQUN4QztBQUFBLFFBQ0o7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsY0FBYyxTQUFTO0FBQ25CLFVBQUksS0FBSyxTQUFTLElBQUksT0FBTyxHQUFHO0FBQzVCLGFBQUssU0FBUyxPQUFPLE9BQU87QUFDNUIsWUFBSSxLQUFLLFNBQVMsa0JBQWtCO0FBQ2hDLGVBQUssU0FBUyxpQkFBaUIsT0FBTztBQUFBLFFBQzFDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxFQUNKO0FBRUEsTUFBTSxvQkFBTixNQUF3QjtBQUFBLElBQ3BCLFlBQVksU0FBUyxlQUFlLFVBQVU7QUFDMUMsV0FBSyxnQkFBZ0I7QUFDckIsV0FBSyxXQUFXO0FBQ2hCLFdBQUssa0JBQWtCLElBQUksZ0JBQWdCLFNBQVMsSUFBSTtBQUFBLElBQzVEO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssZ0JBQWdCO0FBQUEsSUFDaEM7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sSUFBSSxLQUFLLGFBQWE7QUFBQSxJQUNqQztBQUFBLElBQ0EsUUFBUTtBQUNKLFdBQUssZ0JBQWdCLE1BQU07QUFBQSxJQUMvQjtBQUFBLElBQ0EsTUFBTSxVQUFVO0FBQ1osV0FBSyxnQkFBZ0IsTUFBTSxRQUFRO0FBQUEsSUFDdkM7QUFBQSxJQUNBLE9BQU87QUFDSCxXQUFLLGdCQUFnQixLQUFLO0FBQUEsSUFDOUI7QUFBQSxJQUNBLFVBQVU7QUFDTixXQUFLLGdCQUFnQixRQUFRO0FBQUEsSUFDakM7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxnQkFBZ0I7QUFBQSxJQUNoQztBQUFBLElBQ0EsYUFBYSxTQUFTO0FBQ2xCLGFBQU8sUUFBUSxhQUFhLEtBQUssYUFBYTtBQUFBLElBQ2xEO0FBQUEsSUFDQSxvQkFBb0IsTUFBTTtBQUN0QixZQUFNLFFBQVEsS0FBSyxhQUFhLElBQUksSUFBSSxDQUFDLElBQUksSUFBSSxDQUFDO0FBQ2xELFlBQU0sVUFBVSxNQUFNLEtBQUssS0FBSyxpQkFBaUIsS0FBSyxRQUFRLENBQUM7QUFDL0QsYUFBTyxNQUFNLE9BQU8sT0FBTztBQUFBLElBQy9CO0FBQUEsSUFDQSxlQUFlLFNBQVM7QUFDcEIsVUFBSSxLQUFLLFNBQVMseUJBQXlCO0FBQ3ZDLGFBQUssU0FBUyx3QkFBd0IsU0FBUyxLQUFLLGFBQWE7QUFBQSxNQUNyRTtBQUFBLElBQ0o7QUFBQSxJQUNBLGlCQUFpQixTQUFTO0FBQ3RCLFVBQUksS0FBSyxTQUFTLDJCQUEyQjtBQUN6QyxhQUFLLFNBQVMsMEJBQTBCLFNBQVMsS0FBSyxhQUFhO0FBQUEsTUFDdkU7QUFBQSxJQUNKO0FBQUEsSUFDQSx3QkFBd0IsU0FBUyxlQUFlO0FBQzVDLFVBQUksS0FBSyxTQUFTLGdDQUFnQyxLQUFLLGlCQUFpQixlQUFlO0FBQ25GLGFBQUssU0FBUyw2QkFBNkIsU0FBUyxhQUFhO0FBQUEsTUFDckU7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLFdBQVMsSUFBSSxLQUFLLEtBQUssT0FBTztBQUMxQixJQUFBQyxPQUFNLEtBQUssR0FBRyxFQUFFLElBQUksS0FBSztBQUFBLEVBQzdCO0FBQ0EsV0FBUyxJQUFJLEtBQUssS0FBSyxPQUFPO0FBQzFCLElBQUFBLE9BQU0sS0FBSyxHQUFHLEVBQUUsT0FBTyxLQUFLO0FBQzVCLFVBQU0sS0FBSyxHQUFHO0FBQUEsRUFDbEI7QUFDQSxXQUFTQSxPQUFNLEtBQUssS0FBSztBQUNyQixRQUFJLFNBQVMsSUFBSSxJQUFJLEdBQUc7QUFDeEIsUUFBSSxDQUFDLFFBQVE7QUFDVCxlQUFTLG9CQUFJLElBQUk7QUFDakIsVUFBSSxJQUFJLEtBQUssTUFBTTtBQUFBLElBQ3ZCO0FBQ0EsV0FBTztBQUFBLEVBQ1g7QUFDQSxXQUFTLE1BQU0sS0FBSyxLQUFLO0FBQ3JCLFVBQU0sU0FBUyxJQUFJLElBQUksR0FBRztBQUMxQixRQUFJLFVBQVUsUUFBUSxPQUFPLFFBQVEsR0FBRztBQUNwQyxVQUFJLE9BQU8sR0FBRztBQUFBLElBQ2xCO0FBQUEsRUFDSjtBQUVBLE1BQU0sV0FBTixNQUFlO0FBQUEsSUFDWCxjQUFjO0FBQ1YsV0FBSyxjQUFjLG9CQUFJLElBQUk7QUFBQSxJQUMvQjtBQUFBLElBQ0EsSUFBSSxPQUFPO0FBQ1AsYUFBTyxNQUFNLEtBQUssS0FBSyxZQUFZLEtBQUssQ0FBQztBQUFBLElBQzdDO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxZQUFNLE9BQU8sTUFBTSxLQUFLLEtBQUssWUFBWSxPQUFPLENBQUM7QUFDakQsYUFBTyxLQUFLLE9BQU8sQ0FBQyxRQUFRLFFBQVEsT0FBTyxPQUFPLE1BQU0sS0FBSyxHQUFHLENBQUMsR0FBRyxDQUFDLENBQUM7QUFBQSxJQUMxRTtBQUFBLElBQ0EsSUFBSSxPQUFPO0FBQ1AsWUFBTSxPQUFPLE1BQU0sS0FBSyxLQUFLLFlBQVksT0FBTyxDQUFDO0FBQ2pELGFBQU8sS0FBSyxPQUFPLENBQUMsTUFBTSxRQUFRLE9BQU8sSUFBSSxNQUFNLENBQUM7QUFBQSxJQUN4RDtBQUFBLElBQ0EsSUFBSSxLQUFLLE9BQU87QUFDWixVQUFJLEtBQUssYUFBYSxLQUFLLEtBQUs7QUFBQSxJQUNwQztBQUFBLElBQ0EsT0FBTyxLQUFLLE9BQU87QUFDZixVQUFJLEtBQUssYUFBYSxLQUFLLEtBQUs7QUFBQSxJQUNwQztBQUFBLElBQ0EsSUFBSSxLQUFLLE9BQU87QUFDWixZQUFNLFNBQVMsS0FBSyxZQUFZLElBQUksR0FBRztBQUN2QyxhQUFPLFVBQVUsUUFBUSxPQUFPLElBQUksS0FBSztBQUFBLElBQzdDO0FBQUEsSUFDQSxPQUFPLEtBQUs7QUFDUixhQUFPLEtBQUssWUFBWSxJQUFJLEdBQUc7QUFBQSxJQUNuQztBQUFBLElBQ0EsU0FBUyxPQUFPO0FBQ1osWUFBTSxPQUFPLE1BQU0sS0FBSyxLQUFLLFlBQVksT0FBTyxDQUFDO0FBQ2pELGFBQU8sS0FBSyxLQUFLLENBQUMsUUFBUSxJQUFJLElBQUksS0FBSyxDQUFDO0FBQUEsSUFDNUM7QUFBQSxJQUNBLGdCQUFnQixLQUFLO0FBQ2pCLFlBQU0sU0FBUyxLQUFLLFlBQVksSUFBSSxHQUFHO0FBQ3ZDLGFBQU8sU0FBUyxNQUFNLEtBQUssTUFBTSxJQUFJLENBQUM7QUFBQSxJQUMxQztBQUFBLElBQ0EsZ0JBQWdCLE9BQU87QUFDbkIsYUFBTyxNQUFNLEtBQUssS0FBSyxXQUFXLEVBQzdCLE9BQU8sQ0FBQyxDQUFDLE1BQU0sTUFBTSxNQUFNLE9BQU8sSUFBSSxLQUFLLENBQUMsRUFDNUMsSUFBSSxDQUFDLENBQUMsS0FBSyxPQUFPLE1BQU0sR0FBRztBQUFBLElBQ3BDO0FBQUEsRUFDSjtBQTJCQSxNQUFNLG1CQUFOLE1BQXVCO0FBQUEsSUFDbkIsWUFBWSxTQUFTLFVBQVUsVUFBVSxTQUFTO0FBQzlDLFdBQUssWUFBWTtBQUNqQixXQUFLLFVBQVU7QUFDZixXQUFLLGtCQUFrQixJQUFJLGdCQUFnQixTQUFTLElBQUk7QUFDeEQsV0FBSyxXQUFXO0FBQ2hCLFdBQUssbUJBQW1CLElBQUksU0FBUztBQUFBLElBQ3pDO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssZ0JBQWdCO0FBQUEsSUFDaEM7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sS0FBSztBQUFBLElBQ2hCO0FBQUEsSUFDQSxJQUFJLFNBQVMsVUFBVTtBQUNuQixXQUFLLFlBQVk7QUFDakIsV0FBSyxRQUFRO0FBQUEsSUFDakI7QUFBQSxJQUNBLFFBQVE7QUFDSixXQUFLLGdCQUFnQixNQUFNO0FBQUEsSUFDL0I7QUFBQSxJQUNBLE1BQU0sVUFBVTtBQUNaLFdBQUssZ0JBQWdCLE1BQU0sUUFBUTtBQUFBLElBQ3ZDO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxnQkFBZ0IsS0FBSztBQUFBLElBQzlCO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxnQkFBZ0IsUUFBUTtBQUFBLElBQ2pDO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssZ0JBQWdCO0FBQUEsSUFDaEM7QUFBQSxJQUNBLGFBQWEsU0FBUztBQUNsQixZQUFNLEVBQUUsU0FBUyxJQUFJO0FBQ3JCLFVBQUksVUFBVTtBQUNWLGNBQU0sVUFBVSxRQUFRLFFBQVEsUUFBUTtBQUN4QyxZQUFJLEtBQUssU0FBUyxzQkFBc0I7QUFDcEMsaUJBQU8sV0FBVyxLQUFLLFNBQVMscUJBQXFCLFNBQVMsS0FBSyxPQUFPO0FBQUEsUUFDOUU7QUFDQSxlQUFPO0FBQUEsTUFDWCxPQUNLO0FBQ0QsZUFBTztBQUFBLE1BQ1g7QUFBQSxJQUNKO0FBQUEsSUFDQSxvQkFBb0IsTUFBTTtBQUN0QixZQUFNLEVBQUUsU0FBUyxJQUFJO0FBQ3JCLFVBQUksVUFBVTtBQUNWLGNBQU0sUUFBUSxLQUFLLGFBQWEsSUFBSSxJQUFJLENBQUMsSUFBSSxJQUFJLENBQUM7QUFDbEQsY0FBTSxVQUFVLE1BQU0sS0FBSyxLQUFLLGlCQUFpQixRQUFRLENBQUMsRUFBRSxPQUFPLENBQUNDLFdBQVUsS0FBSyxhQUFhQSxNQUFLLENBQUM7QUFDdEcsZUFBTyxNQUFNLE9BQU8sT0FBTztBQUFBLE1BQy9CLE9BQ0s7QUFDRCxlQUFPLENBQUM7QUFBQSxNQUNaO0FBQUEsSUFDSjtBQUFBLElBQ0EsZUFBZSxTQUFTO0FBQ3BCLFlBQU0sRUFBRSxTQUFTLElBQUk7QUFDckIsVUFBSSxVQUFVO0FBQ1YsYUFBSyxnQkFBZ0IsU0FBUyxRQUFRO0FBQUEsTUFDMUM7QUFBQSxJQUNKO0FBQUEsSUFDQSxpQkFBaUIsU0FBUztBQUN0QixZQUFNLFlBQVksS0FBSyxpQkFBaUIsZ0JBQWdCLE9BQU87QUFDL0QsaUJBQVcsWUFBWSxXQUFXO0FBQzlCLGFBQUssa0JBQWtCLFNBQVMsUUFBUTtBQUFBLE1BQzVDO0FBQUEsSUFDSjtBQUFBLElBQ0Esd0JBQXdCLFNBQVMsZ0JBQWdCO0FBQzdDLFlBQU0sRUFBRSxTQUFTLElBQUk7QUFDckIsVUFBSSxVQUFVO0FBQ1YsY0FBTSxVQUFVLEtBQUssYUFBYSxPQUFPO0FBQ3pDLGNBQU0sZ0JBQWdCLEtBQUssaUJBQWlCLElBQUksVUFBVSxPQUFPO0FBQ2pFLFlBQUksV0FBVyxDQUFDLGVBQWU7QUFDM0IsZUFBSyxnQkFBZ0IsU0FBUyxRQUFRO0FBQUEsUUFDMUMsV0FDUyxDQUFDLFdBQVcsZUFBZTtBQUNoQyxlQUFLLGtCQUFrQixTQUFTLFFBQVE7QUFBQSxRQUM1QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxnQkFBZ0IsU0FBUyxVQUFVO0FBQy9CLFdBQUssU0FBUyxnQkFBZ0IsU0FBUyxVQUFVLEtBQUssT0FBTztBQUM3RCxXQUFLLGlCQUFpQixJQUFJLFVBQVUsT0FBTztBQUFBLElBQy9DO0FBQUEsSUFDQSxrQkFBa0IsU0FBUyxVQUFVO0FBQ2pDLFdBQUssU0FBUyxrQkFBa0IsU0FBUyxVQUFVLEtBQUssT0FBTztBQUMvRCxXQUFLLGlCQUFpQixPQUFPLFVBQVUsT0FBTztBQUFBLElBQ2xEO0FBQUEsRUFDSjtBQUVBLE1BQU0sb0JBQU4sTUFBd0I7QUFBQSxJQUNwQixZQUFZLFNBQVMsVUFBVTtBQUMzQixXQUFLLFVBQVU7QUFDZixXQUFLLFdBQVc7QUFDaEIsV0FBSyxVQUFVO0FBQ2YsV0FBSyxZQUFZLG9CQUFJLElBQUk7QUFDekIsV0FBSyxtQkFBbUIsSUFBSSxpQkFBaUIsQ0FBQyxjQUFjLEtBQUssaUJBQWlCLFNBQVMsQ0FBQztBQUFBLElBQ2hHO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssU0FBUztBQUNmLGFBQUssVUFBVTtBQUNmLGFBQUssaUJBQWlCLFFBQVEsS0FBSyxTQUFTLEVBQUUsWUFBWSxNQUFNLG1CQUFtQixLQUFLLENBQUM7QUFDekYsYUFBSyxRQUFRO0FBQUEsTUFDakI7QUFBQSxJQUNKO0FBQUEsSUFDQSxPQUFPO0FBQ0gsVUFBSSxLQUFLLFNBQVM7QUFDZCxhQUFLLGlCQUFpQixZQUFZO0FBQ2xDLGFBQUssaUJBQWlCLFdBQVc7QUFDakMsYUFBSyxVQUFVO0FBQUEsTUFDbkI7QUFBQSxJQUNKO0FBQUEsSUFDQSxVQUFVO0FBQ04sVUFBSSxLQUFLLFNBQVM7QUFDZCxtQkFBVyxpQkFBaUIsS0FBSyxxQkFBcUI7QUFDbEQsZUFBSyxpQkFBaUIsZUFBZSxJQUFJO0FBQUEsUUFDN0M7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsaUJBQWlCLFdBQVc7QUFDeEIsVUFBSSxLQUFLLFNBQVM7QUFDZCxtQkFBVyxZQUFZLFdBQVc7QUFDOUIsZUFBSyxnQkFBZ0IsUUFBUTtBQUFBLFFBQ2pDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGdCQUFnQixVQUFVO0FBQ3RCLFlBQU0sZ0JBQWdCLFNBQVM7QUFDL0IsVUFBSSxlQUFlO0FBQ2YsYUFBSyxpQkFBaUIsZUFBZSxTQUFTLFFBQVE7QUFBQSxNQUMxRDtBQUFBLElBQ0o7QUFBQSxJQUNBLGlCQUFpQixlQUFlLFVBQVU7QUFDdEMsWUFBTSxNQUFNLEtBQUssU0FBUyw0QkFBNEIsYUFBYTtBQUNuRSxVQUFJLE9BQU8sTUFBTTtBQUNiLFlBQUksQ0FBQyxLQUFLLFVBQVUsSUFBSSxhQUFhLEdBQUc7QUFDcEMsZUFBSyxrQkFBa0IsS0FBSyxhQUFhO0FBQUEsUUFDN0M7QUFDQSxjQUFNLFFBQVEsS0FBSyxRQUFRLGFBQWEsYUFBYTtBQUNyRCxZQUFJLEtBQUssVUFBVSxJQUFJLGFBQWEsS0FBSyxPQUFPO0FBQzVDLGVBQUssc0JBQXNCLE9BQU8sS0FBSyxRQUFRO0FBQUEsUUFDbkQ7QUFDQSxZQUFJLFNBQVMsTUFBTTtBQUNmLGdCQUFNQyxZQUFXLEtBQUssVUFBVSxJQUFJLGFBQWE7QUFDakQsZUFBSyxVQUFVLE9BQU8sYUFBYTtBQUNuQyxjQUFJQTtBQUNBLGlCQUFLLG9CQUFvQixLQUFLLGVBQWVBLFNBQVE7QUFBQSxRQUM3RCxPQUNLO0FBQ0QsZUFBSyxVQUFVLElBQUksZUFBZSxLQUFLO0FBQUEsUUFDM0M7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0Esa0JBQWtCLEtBQUssZUFBZTtBQUNsQyxVQUFJLEtBQUssU0FBUyxtQkFBbUI7QUFDakMsYUFBSyxTQUFTLGtCQUFrQixLQUFLLGFBQWE7QUFBQSxNQUN0RDtBQUFBLElBQ0o7QUFBQSxJQUNBLHNCQUFzQixPQUFPLEtBQUssVUFBVTtBQUN4QyxVQUFJLEtBQUssU0FBUyx1QkFBdUI7QUFDckMsYUFBSyxTQUFTLHNCQUFzQixPQUFPLEtBQUssUUFBUTtBQUFBLE1BQzVEO0FBQUEsSUFDSjtBQUFBLElBQ0Esb0JBQW9CLEtBQUssZUFBZSxVQUFVO0FBQzlDLFVBQUksS0FBSyxTQUFTLHFCQUFxQjtBQUNuQyxhQUFLLFNBQVMsb0JBQW9CLEtBQUssZUFBZSxRQUFRO0FBQUEsTUFDbEU7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLHNCQUFzQjtBQUN0QixhQUFPLE1BQU0sS0FBSyxJQUFJLElBQUksS0FBSyxzQkFBc0IsT0FBTyxLQUFLLHNCQUFzQixDQUFDLENBQUM7QUFBQSxJQUM3RjtBQUFBLElBQ0EsSUFBSSx3QkFBd0I7QUFDeEIsYUFBTyxNQUFNLEtBQUssS0FBSyxRQUFRLFVBQVUsRUFBRSxJQUFJLENBQUMsY0FBYyxVQUFVLElBQUk7QUFBQSxJQUNoRjtBQUFBLElBQ0EsSUFBSSx5QkFBeUI7QUFDekIsYUFBTyxNQUFNLEtBQUssS0FBSyxVQUFVLEtBQUssQ0FBQztBQUFBLElBQzNDO0FBQUEsRUFDSjtBQUVBLE1BQU0sb0JBQU4sTUFBd0I7QUFBQSxJQUNwQixZQUFZLFNBQVMsZUFBZSxVQUFVO0FBQzFDLFdBQUssb0JBQW9CLElBQUksa0JBQWtCLFNBQVMsZUFBZSxJQUFJO0FBQzNFLFdBQUssV0FBVztBQUNoQixXQUFLLGtCQUFrQixJQUFJLFNBQVM7QUFBQSxJQUN4QztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLGtCQUFrQjtBQUFBLElBQ2xDO0FBQUEsSUFDQSxRQUFRO0FBQ0osV0FBSyxrQkFBa0IsTUFBTTtBQUFBLElBQ2pDO0FBQUEsSUFDQSxNQUFNLFVBQVU7QUFDWixXQUFLLGtCQUFrQixNQUFNLFFBQVE7QUFBQSxJQUN6QztBQUFBLElBQ0EsT0FBTztBQUNILFdBQUssa0JBQWtCLEtBQUs7QUFBQSxJQUNoQztBQUFBLElBQ0EsVUFBVTtBQUNOLFdBQUssa0JBQWtCLFFBQVE7QUFBQSxJQUNuQztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLGtCQUFrQjtBQUFBLElBQ2xDO0FBQUEsSUFDQSxJQUFJLGdCQUFnQjtBQUNoQixhQUFPLEtBQUssa0JBQWtCO0FBQUEsSUFDbEM7QUFBQSxJQUNBLHdCQUF3QixTQUFTO0FBQzdCLFdBQUssY0FBYyxLQUFLLHFCQUFxQixPQUFPLENBQUM7QUFBQSxJQUN6RDtBQUFBLElBQ0EsNkJBQTZCLFNBQVM7QUFDbEMsWUFBTSxDQUFDLGlCQUFpQixhQUFhLElBQUksS0FBSyx3QkFBd0IsT0FBTztBQUM3RSxXQUFLLGdCQUFnQixlQUFlO0FBQ3BDLFdBQUssY0FBYyxhQUFhO0FBQUEsSUFDcEM7QUFBQSxJQUNBLDBCQUEwQixTQUFTO0FBQy9CLFdBQUssZ0JBQWdCLEtBQUssZ0JBQWdCLGdCQUFnQixPQUFPLENBQUM7QUFBQSxJQUN0RTtBQUFBLElBQ0EsY0FBYyxRQUFRO0FBQ2xCLGFBQU8sUUFBUSxDQUFDLFVBQVUsS0FBSyxhQUFhLEtBQUssQ0FBQztBQUFBLElBQ3REO0FBQUEsSUFDQSxnQkFBZ0IsUUFBUTtBQUNwQixhQUFPLFFBQVEsQ0FBQyxVQUFVLEtBQUssZUFBZSxLQUFLLENBQUM7QUFBQSxJQUN4RDtBQUFBLElBQ0EsYUFBYSxPQUFPO0FBQ2hCLFdBQUssU0FBUyxhQUFhLEtBQUs7QUFDaEMsV0FBSyxnQkFBZ0IsSUFBSSxNQUFNLFNBQVMsS0FBSztBQUFBLElBQ2pEO0FBQUEsSUFDQSxlQUFlLE9BQU87QUFDbEIsV0FBSyxTQUFTLGVBQWUsS0FBSztBQUNsQyxXQUFLLGdCQUFnQixPQUFPLE1BQU0sU0FBUyxLQUFLO0FBQUEsSUFDcEQ7QUFBQSxJQUNBLHdCQUF3QixTQUFTO0FBQzdCLFlBQU0saUJBQWlCLEtBQUssZ0JBQWdCLGdCQUFnQixPQUFPO0FBQ25FLFlBQU0sZ0JBQWdCLEtBQUsscUJBQXFCLE9BQU87QUFDdkQsWUFBTSxzQkFBc0IsSUFBSSxnQkFBZ0IsYUFBYSxFQUFFLFVBQVUsQ0FBQyxDQUFDLGVBQWUsWUFBWSxNQUFNLENBQUMsZUFBZSxlQUFlLFlBQVksQ0FBQztBQUN4SixVQUFJLHVCQUF1QixJQUFJO0FBQzNCLGVBQU8sQ0FBQyxDQUFDLEdBQUcsQ0FBQyxDQUFDO0FBQUEsTUFDbEIsT0FDSztBQUNELGVBQU8sQ0FBQyxlQUFlLE1BQU0sbUJBQW1CLEdBQUcsY0FBYyxNQUFNLG1CQUFtQixDQUFDO0FBQUEsTUFDL0Y7QUFBQSxJQUNKO0FBQUEsSUFDQSxxQkFBcUIsU0FBUztBQUMxQixZQUFNLGdCQUFnQixLQUFLO0FBQzNCLFlBQU0sY0FBYyxRQUFRLGFBQWEsYUFBYSxLQUFLO0FBQzNELGFBQU8saUJBQWlCLGFBQWEsU0FBUyxhQUFhO0FBQUEsSUFDL0Q7QUFBQSxFQUNKO0FBQ0EsV0FBUyxpQkFBaUIsYUFBYSxTQUFTLGVBQWU7QUFDM0QsV0FBTyxZQUNGLEtBQUssRUFDTCxNQUFNLEtBQUssRUFDWCxPQUFPLENBQUMsWUFBWSxRQUFRLE1BQU0sRUFDbEMsSUFBSSxDQUFDLFNBQVMsV0FBVyxFQUFFLFNBQVMsZUFBZSxTQUFTLE1BQU0sRUFBRTtBQUFBLEVBQzdFO0FBQ0EsV0FBUyxJQUFJLE1BQU0sT0FBTztBQUN0QixVQUFNLFNBQVMsS0FBSyxJQUFJLEtBQUssUUFBUSxNQUFNLE1BQU07QUFDakQsV0FBTyxNQUFNLEtBQUssRUFBRSxPQUFPLEdBQUcsQ0FBQyxHQUFHLFVBQVUsQ0FBQyxLQUFLLEtBQUssR0FBRyxNQUFNLEtBQUssQ0FBQyxDQUFDO0FBQUEsRUFDM0U7QUFDQSxXQUFTLGVBQWUsTUFBTSxPQUFPO0FBQ2pDLFdBQU8sUUFBUSxTQUFTLEtBQUssU0FBUyxNQUFNLFNBQVMsS0FBSyxXQUFXLE1BQU07QUFBQSxFQUMvRTtBQUVBLE1BQU0sb0JBQU4sTUFBd0I7QUFBQSxJQUNwQixZQUFZLFNBQVMsZUFBZSxVQUFVO0FBQzFDLFdBQUssb0JBQW9CLElBQUksa0JBQWtCLFNBQVMsZUFBZSxJQUFJO0FBQzNFLFdBQUssV0FBVztBQUNoQixXQUFLLHNCQUFzQixvQkFBSSxRQUFRO0FBQ3ZDLFdBQUsseUJBQXlCLG9CQUFJLFFBQVE7QUFBQSxJQUM5QztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLGtCQUFrQjtBQUFBLElBQ2xDO0FBQUEsSUFDQSxRQUFRO0FBQ0osV0FBSyxrQkFBa0IsTUFBTTtBQUFBLElBQ2pDO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxrQkFBa0IsS0FBSztBQUFBLElBQ2hDO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxrQkFBa0IsUUFBUTtBQUFBLElBQ25DO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssa0JBQWtCO0FBQUEsSUFDbEM7QUFBQSxJQUNBLElBQUksZ0JBQWdCO0FBQ2hCLGFBQU8sS0FBSyxrQkFBa0I7QUFBQSxJQUNsQztBQUFBLElBQ0EsYUFBYSxPQUFPO0FBQ2hCLFlBQU0sRUFBRSxRQUFRLElBQUk7QUFDcEIsWUFBTSxFQUFFLE1BQU0sSUFBSSxLQUFLLHlCQUF5QixLQUFLO0FBQ3JELFVBQUksT0FBTztBQUNQLGFBQUssNkJBQTZCLE9BQU8sRUFBRSxJQUFJLE9BQU8sS0FBSztBQUMzRCxhQUFLLFNBQVMsb0JBQW9CLFNBQVMsS0FBSztBQUFBLE1BQ3BEO0FBQUEsSUFDSjtBQUFBLElBQ0EsZUFBZSxPQUFPO0FBQ2xCLFlBQU0sRUFBRSxRQUFRLElBQUk7QUFDcEIsWUFBTSxFQUFFLE1BQU0sSUFBSSxLQUFLLHlCQUF5QixLQUFLO0FBQ3JELFVBQUksT0FBTztBQUNQLGFBQUssNkJBQTZCLE9BQU8sRUFBRSxPQUFPLEtBQUs7QUFDdkQsYUFBSyxTQUFTLHNCQUFzQixTQUFTLEtBQUs7QUFBQSxNQUN0RDtBQUFBLElBQ0o7QUFBQSxJQUNBLHlCQUF5QixPQUFPO0FBQzVCLFVBQUksY0FBYyxLQUFLLG9CQUFvQixJQUFJLEtBQUs7QUFDcEQsVUFBSSxDQUFDLGFBQWE7QUFDZCxzQkFBYyxLQUFLLFdBQVcsS0FBSztBQUNuQyxhQUFLLG9CQUFvQixJQUFJLE9BQU8sV0FBVztBQUFBLE1BQ25EO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLDZCQUE2QixTQUFTO0FBQ2xDLFVBQUksZ0JBQWdCLEtBQUssdUJBQXVCLElBQUksT0FBTztBQUMzRCxVQUFJLENBQUMsZUFBZTtBQUNoQix3QkFBZ0Isb0JBQUksSUFBSTtBQUN4QixhQUFLLHVCQUF1QixJQUFJLFNBQVMsYUFBYTtBQUFBLE1BQzFEO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLFdBQVcsT0FBTztBQUNkLFVBQUk7QUFDQSxjQUFNLFFBQVEsS0FBSyxTQUFTLG1CQUFtQixLQUFLO0FBQ3BELGVBQU8sRUFBRSxNQUFNO0FBQUEsTUFDbkIsU0FDT0MsUUFBTztBQUNWLGVBQU8sRUFBRSxPQUFBQSxPQUFNO0FBQUEsTUFDbkI7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLE1BQU0sa0JBQU4sTUFBc0I7QUFBQSxJQUNsQixZQUFZLFNBQVMsVUFBVTtBQUMzQixXQUFLLFVBQVU7QUFDZixXQUFLLFdBQVc7QUFDaEIsV0FBSyxtQkFBbUIsb0JBQUksSUFBSTtBQUFBLElBQ3BDO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssbUJBQW1CO0FBQ3pCLGFBQUssb0JBQW9CLElBQUksa0JBQWtCLEtBQUssU0FBUyxLQUFLLGlCQUFpQixJQUFJO0FBQ3ZGLGFBQUssa0JBQWtCLE1BQU07QUFBQSxNQUNqQztBQUFBLElBQ0o7QUFBQSxJQUNBLE9BQU87QUFDSCxVQUFJLEtBQUssbUJBQW1CO0FBQ3hCLGFBQUssa0JBQWtCLEtBQUs7QUFDNUIsZUFBTyxLQUFLO0FBQ1osYUFBSyxxQkFBcUI7QUFBQSxNQUM5QjtBQUFBLElBQ0o7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksa0JBQWtCO0FBQ2xCLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksU0FBUztBQUNULGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sTUFBTSxLQUFLLEtBQUssaUJBQWlCLE9BQU8sQ0FBQztBQUFBLElBQ3BEO0FBQUEsSUFDQSxjQUFjLFFBQVE7QUFDbEIsWUFBTSxVQUFVLElBQUksUUFBUSxLQUFLLFNBQVMsTUFBTTtBQUNoRCxXQUFLLGlCQUFpQixJQUFJLFFBQVEsT0FBTztBQUN6QyxXQUFLLFNBQVMsaUJBQWlCLE9BQU87QUFBQSxJQUMxQztBQUFBLElBQ0EsaUJBQWlCLFFBQVE7QUFDckIsWUFBTSxVQUFVLEtBQUssaUJBQWlCLElBQUksTUFBTTtBQUNoRCxVQUFJLFNBQVM7QUFDVCxhQUFLLGlCQUFpQixPQUFPLE1BQU07QUFDbkMsYUFBSyxTQUFTLG9CQUFvQixPQUFPO0FBQUEsTUFDN0M7QUFBQSxJQUNKO0FBQUEsSUFDQSx1QkFBdUI7QUFDbkIsV0FBSyxTQUFTLFFBQVEsQ0FBQyxZQUFZLEtBQUssU0FBUyxvQkFBb0IsU0FBUyxJQUFJLENBQUM7QUFDbkYsV0FBSyxpQkFBaUIsTUFBTTtBQUFBLElBQ2hDO0FBQUEsSUFDQSxtQkFBbUIsT0FBTztBQUN0QixZQUFNLFNBQVMsT0FBTyxTQUFTLE9BQU8sS0FBSyxNQUFNO0FBQ2pELFVBQUksT0FBTyxjQUFjLEtBQUssWUFBWTtBQUN0QyxlQUFPO0FBQUEsTUFDWDtBQUFBLElBQ0o7QUFBQSxJQUNBLG9CQUFvQixTQUFTLFFBQVE7QUFDakMsV0FBSyxjQUFjLE1BQU07QUFBQSxJQUM3QjtBQUFBLElBQ0Esc0JBQXNCLFNBQVMsUUFBUTtBQUNuQyxXQUFLLGlCQUFpQixNQUFNO0FBQUEsSUFDaEM7QUFBQSxFQUNKO0FBRUEsTUFBTSxnQkFBTixNQUFvQjtBQUFBLElBQ2hCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssVUFBVTtBQUNmLFdBQUssV0FBVztBQUNoQixXQUFLLG9CQUFvQixJQUFJLGtCQUFrQixLQUFLLFNBQVMsSUFBSTtBQUNqRSxXQUFLLHFCQUFxQixLQUFLLFdBQVc7QUFBQSxJQUM5QztBQUFBLElBQ0EsUUFBUTtBQUNKLFdBQUssa0JBQWtCLE1BQU07QUFDN0IsV0FBSyx1Q0FBdUM7QUFBQSxJQUNoRDtBQUFBLElBQ0EsT0FBTztBQUNILFdBQUssa0JBQWtCLEtBQUs7QUFBQSxJQUNoQztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsNEJBQTRCLGVBQWU7QUFDdkMsVUFBSSxpQkFBaUIsS0FBSyxvQkFBb0I7QUFDMUMsZUFBTyxLQUFLLG1CQUFtQixhQUFhLEVBQUU7QUFBQSxNQUNsRDtBQUFBLElBQ0o7QUFBQSxJQUNBLGtCQUFrQixLQUFLLGVBQWU7QUFDbEMsWUFBTSxhQUFhLEtBQUssbUJBQW1CLGFBQWE7QUFDeEQsVUFBSSxDQUFDLEtBQUssU0FBUyxHQUFHLEdBQUc7QUFDckIsYUFBSyxzQkFBc0IsS0FBSyxXQUFXLE9BQU8sS0FBSyxTQUFTLEdBQUcsQ0FBQyxHQUFHLFdBQVcsT0FBTyxXQUFXLFlBQVksQ0FBQztBQUFBLE1BQ3JIO0FBQUEsSUFDSjtBQUFBLElBQ0Esc0JBQXNCLE9BQU8sTUFBTSxVQUFVO0FBQ3pDLFlBQU0sYUFBYSxLQUFLLHVCQUF1QixJQUFJO0FBQ25ELFVBQUksVUFBVTtBQUNWO0FBQ0osVUFBSSxhQUFhLE1BQU07QUFDbkIsbUJBQVcsV0FBVyxPQUFPLFdBQVcsWUFBWTtBQUFBLE1BQ3hEO0FBQ0EsV0FBSyxzQkFBc0IsTUFBTSxPQUFPLFFBQVE7QUFBQSxJQUNwRDtBQUFBLElBQ0Esb0JBQW9CLEtBQUssZUFBZSxVQUFVO0FBQzlDLFlBQU0sYUFBYSxLQUFLLHVCQUF1QixHQUFHO0FBQ2xELFVBQUksS0FBSyxTQUFTLEdBQUcsR0FBRztBQUNwQixhQUFLLHNCQUFzQixLQUFLLFdBQVcsT0FBTyxLQUFLLFNBQVMsR0FBRyxDQUFDLEdBQUcsUUFBUTtBQUFBLE1BQ25GLE9BQ0s7QUFDRCxhQUFLLHNCQUFzQixLQUFLLFdBQVcsT0FBTyxXQUFXLFlBQVksR0FBRyxRQUFRO0FBQUEsTUFDeEY7QUFBQSxJQUNKO0FBQUEsSUFDQSx5Q0FBeUM7QUFDckMsaUJBQVcsRUFBRSxLQUFLLE1BQU0sY0FBYyxPQUFPLEtBQUssS0FBSyxrQkFBa0I7QUFDckUsWUFBSSxnQkFBZ0IsVUFBYSxDQUFDLEtBQUssV0FBVyxLQUFLLElBQUksR0FBRyxHQUFHO0FBQzdELGVBQUssc0JBQXNCLE1BQU0sT0FBTyxZQUFZLEdBQUcsTUFBUztBQUFBLFFBQ3BFO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLHNCQUFzQixNQUFNLFVBQVUsYUFBYTtBQUMvQyxZQUFNLG9CQUFvQixHQUFHLElBQUk7QUFDakMsWUFBTSxnQkFBZ0IsS0FBSyxTQUFTLGlCQUFpQjtBQUNyRCxVQUFJLE9BQU8saUJBQWlCLFlBQVk7QUFDcEMsY0FBTSxhQUFhLEtBQUssdUJBQXVCLElBQUk7QUFDbkQsWUFBSTtBQUNBLGdCQUFNLFFBQVEsV0FBVyxPQUFPLFFBQVE7QUFDeEMsY0FBSSxXQUFXO0FBQ2YsY0FBSSxhQUFhO0FBQ2IsdUJBQVcsV0FBVyxPQUFPLFdBQVc7QUFBQSxVQUM1QztBQUNBLHdCQUFjLEtBQUssS0FBSyxVQUFVLE9BQU8sUUFBUTtBQUFBLFFBQ3JELFNBQ09BLFFBQU87QUFDVixjQUFJQSxrQkFBaUIsV0FBVztBQUM1QixZQUFBQSxPQUFNLFVBQVUsbUJBQW1CLEtBQUssUUFBUSxVQUFVLElBQUksV0FBVyxJQUFJLE9BQU9BLE9BQU0sT0FBTztBQUFBLFVBQ3JHO0FBQ0EsZ0JBQU1BO0FBQUEsUUFDVjtBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLG1CQUFtQjtBQUNuQixZQUFNLEVBQUUsbUJBQW1CLElBQUk7QUFDL0IsYUFBTyxPQUFPLEtBQUssa0JBQWtCLEVBQUUsSUFBSSxDQUFDLFFBQVEsbUJBQW1CLEdBQUcsQ0FBQztBQUFBLElBQy9FO0FBQUEsSUFDQSxJQUFJLHlCQUF5QjtBQUN6QixZQUFNLGNBQWMsQ0FBQztBQUNyQixhQUFPLEtBQUssS0FBSyxrQkFBa0IsRUFBRSxRQUFRLENBQUMsUUFBUTtBQUNsRCxjQUFNLGFBQWEsS0FBSyxtQkFBbUIsR0FBRztBQUM5QyxvQkFBWSxXQUFXLElBQUksSUFBSTtBQUFBLE1BQ25DLENBQUM7QUFDRCxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsU0FBUyxlQUFlO0FBQ3BCLFlBQU0sYUFBYSxLQUFLLHVCQUF1QixhQUFhO0FBQzVELFlBQU0sZ0JBQWdCLE1BQU0sV0FBVyxXQUFXLElBQUksQ0FBQztBQUN2RCxhQUFPLEtBQUssU0FBUyxhQUFhO0FBQUEsSUFDdEM7QUFBQSxFQUNKO0FBRUEsTUFBTSxpQkFBTixNQUFxQjtBQUFBLElBQ2pCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssVUFBVTtBQUNmLFdBQUssV0FBVztBQUNoQixXQUFLLGdCQUFnQixJQUFJLFNBQVM7QUFBQSxJQUN0QztBQUFBLElBQ0EsUUFBUTtBQUNKLFVBQUksQ0FBQyxLQUFLLG1CQUFtQjtBQUN6QixhQUFLLG9CQUFvQixJQUFJLGtCQUFrQixLQUFLLFNBQVMsS0FBSyxlQUFlLElBQUk7QUFDckYsYUFBSyxrQkFBa0IsTUFBTTtBQUFBLE1BQ2pDO0FBQUEsSUFDSjtBQUFBLElBQ0EsT0FBTztBQUNILFVBQUksS0FBSyxtQkFBbUI7QUFDeEIsYUFBSyxxQkFBcUI7QUFDMUIsYUFBSyxrQkFBa0IsS0FBSztBQUM1QixlQUFPLEtBQUs7QUFBQSxNQUNoQjtBQUFBLElBQ0o7QUFBQSxJQUNBLGFBQWEsRUFBRSxTQUFTLFNBQVMsS0FBSyxHQUFHO0FBQ3JDLFVBQUksS0FBSyxNQUFNLGdCQUFnQixPQUFPLEdBQUc7QUFDckMsYUFBSyxjQUFjLFNBQVMsSUFBSTtBQUFBLE1BQ3BDO0FBQUEsSUFDSjtBQUFBLElBQ0EsZUFBZSxFQUFFLFNBQVMsU0FBUyxLQUFLLEdBQUc7QUFDdkMsV0FBSyxpQkFBaUIsU0FBUyxJQUFJO0FBQUEsSUFDdkM7QUFBQSxJQUNBLGNBQWMsU0FBUyxNQUFNO0FBQ3pCLFVBQUlDO0FBQ0osVUFBSSxDQUFDLEtBQUssY0FBYyxJQUFJLE1BQU0sT0FBTyxHQUFHO0FBQ3hDLGFBQUssY0FBYyxJQUFJLE1BQU0sT0FBTztBQUNwQyxTQUFDQSxNQUFLLEtBQUssdUJBQXVCLFFBQVFBLFFBQU8sU0FBUyxTQUFTQSxJQUFHLE1BQU0sTUFBTSxLQUFLLFNBQVMsZ0JBQWdCLFNBQVMsSUFBSSxDQUFDO0FBQUEsTUFDbEk7QUFBQSxJQUNKO0FBQUEsSUFDQSxpQkFBaUIsU0FBUyxNQUFNO0FBQzVCLFVBQUlBO0FBQ0osVUFBSSxLQUFLLGNBQWMsSUFBSSxNQUFNLE9BQU8sR0FBRztBQUN2QyxhQUFLLGNBQWMsT0FBTyxNQUFNLE9BQU87QUFDdkMsU0FBQ0EsTUFBSyxLQUFLLHVCQUF1QixRQUFRQSxRQUFPLFNBQVMsU0FBU0EsSUFBRyxNQUFNLE1BQU0sS0FBSyxTQUFTLG1CQUFtQixTQUFTLElBQUksQ0FBQztBQUFBLE1BQ3JJO0FBQUEsSUFDSjtBQUFBLElBQ0EsdUJBQXVCO0FBQ25CLGlCQUFXLFFBQVEsS0FBSyxjQUFjLE1BQU07QUFDeEMsbUJBQVcsV0FBVyxLQUFLLGNBQWMsZ0JBQWdCLElBQUksR0FBRztBQUM1RCxlQUFLLGlCQUFpQixTQUFTLElBQUk7QUFBQSxRQUN2QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLGdCQUFnQjtBQUNoQixhQUFPLFFBQVEsS0FBSyxRQUFRLFVBQVU7QUFBQSxJQUMxQztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxRQUFRO0FBQ1IsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLEVBQ0o7QUFFQSxXQUFTLGlDQUFpQyxhQUFhLGNBQWM7QUFDakUsVUFBTSxZQUFZLDJCQUEyQixXQUFXO0FBQ3hELFdBQU8sTUFBTSxLQUFLLFVBQVUsT0FBTyxDQUFDLFFBQVFDLGlCQUFnQjtBQUN4RCw4QkFBd0JBLGNBQWEsWUFBWSxFQUFFLFFBQVEsQ0FBQyxTQUFTLE9BQU8sSUFBSSxJQUFJLENBQUM7QUFDckYsYUFBTztBQUFBLElBQ1gsR0FBRyxvQkFBSSxJQUFJLENBQUMsQ0FBQztBQUFBLEVBQ2pCO0FBQ0EsV0FBUyxpQ0FBaUMsYUFBYSxjQUFjO0FBQ2pFLFVBQU0sWUFBWSwyQkFBMkIsV0FBVztBQUN4RCxXQUFPLFVBQVUsT0FBTyxDQUFDLE9BQU9BLGlCQUFnQjtBQUM1QyxZQUFNLEtBQUssR0FBRyx3QkFBd0JBLGNBQWEsWUFBWSxDQUFDO0FBQ2hFLGFBQU87QUFBQSxJQUNYLEdBQUcsQ0FBQyxDQUFDO0FBQUEsRUFDVDtBQUNBLFdBQVMsMkJBQTJCLGFBQWE7QUFDN0MsVUFBTSxZQUFZLENBQUM7QUFDbkIsV0FBTyxhQUFhO0FBQ2hCLGdCQUFVLEtBQUssV0FBVztBQUMxQixvQkFBYyxPQUFPLGVBQWUsV0FBVztBQUFBLElBQ25EO0FBQ0EsV0FBTyxVQUFVLFFBQVE7QUFBQSxFQUM3QjtBQUNBLFdBQVMsd0JBQXdCLGFBQWEsY0FBYztBQUN4RCxVQUFNLGFBQWEsWUFBWSxZQUFZO0FBQzNDLFdBQU8sTUFBTSxRQUFRLFVBQVUsSUFBSSxhQUFhLENBQUM7QUFBQSxFQUNyRDtBQUNBLFdBQVMsd0JBQXdCLGFBQWEsY0FBYztBQUN4RCxVQUFNLGFBQWEsWUFBWSxZQUFZO0FBQzNDLFdBQU8sYUFBYSxPQUFPLEtBQUssVUFBVSxFQUFFLElBQUksQ0FBQyxRQUFRLENBQUMsS0FBSyxXQUFXLEdBQUcsQ0FBQyxDQUFDLElBQUksQ0FBQztBQUFBLEVBQ3hGO0FBRUEsTUFBTSxpQkFBTixNQUFxQjtBQUFBLElBQ2pCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssVUFBVTtBQUNmLFdBQUssVUFBVTtBQUNmLFdBQUssV0FBVztBQUNoQixXQUFLLGdCQUFnQixJQUFJLFNBQVM7QUFDbEMsV0FBSyx1QkFBdUIsSUFBSSxTQUFTO0FBQ3pDLFdBQUssc0JBQXNCLG9CQUFJLElBQUk7QUFDbkMsV0FBSyx1QkFBdUIsb0JBQUksSUFBSTtBQUFBLElBQ3hDO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssU0FBUztBQUNmLGFBQUssa0JBQWtCLFFBQVEsQ0FBQyxlQUFlO0FBQzNDLGVBQUssK0JBQStCLFVBQVU7QUFDOUMsZUFBSyxnQ0FBZ0MsVUFBVTtBQUFBLFFBQ25ELENBQUM7QUFDRCxhQUFLLFVBQVU7QUFDZixhQUFLLGtCQUFrQixRQUFRLENBQUMsWUFBWSxRQUFRLFFBQVEsQ0FBQztBQUFBLE1BQ2pFO0FBQUEsSUFDSjtBQUFBLElBQ0EsVUFBVTtBQUNOLFdBQUssb0JBQW9CLFFBQVEsQ0FBQyxhQUFhLFNBQVMsUUFBUSxDQUFDO0FBQ2pFLFdBQUsscUJBQXFCLFFBQVEsQ0FBQyxhQUFhLFNBQVMsUUFBUSxDQUFDO0FBQUEsSUFDdEU7QUFBQSxJQUNBLE9BQU87QUFDSCxVQUFJLEtBQUssU0FBUztBQUNkLGFBQUssVUFBVTtBQUNmLGFBQUsscUJBQXFCO0FBQzFCLGFBQUssc0JBQXNCO0FBQzNCLGFBQUssdUJBQXVCO0FBQUEsTUFDaEM7QUFBQSxJQUNKO0FBQUEsSUFDQSx3QkFBd0I7QUFDcEIsVUFBSSxLQUFLLG9CQUFvQixPQUFPLEdBQUc7QUFDbkMsYUFBSyxvQkFBb0IsUUFBUSxDQUFDLGFBQWEsU0FBUyxLQUFLLENBQUM7QUFDOUQsYUFBSyxvQkFBb0IsTUFBTTtBQUFBLE1BQ25DO0FBQUEsSUFDSjtBQUFBLElBQ0EseUJBQXlCO0FBQ3JCLFVBQUksS0FBSyxxQkFBcUIsT0FBTyxHQUFHO0FBQ3BDLGFBQUsscUJBQXFCLFFBQVEsQ0FBQyxhQUFhLFNBQVMsS0FBSyxDQUFDO0FBQy9ELGFBQUsscUJBQXFCLE1BQU07QUFBQSxNQUNwQztBQUFBLElBQ0o7QUFBQSxJQUNBLGdCQUFnQixTQUFTLFdBQVcsRUFBRSxXQUFXLEdBQUc7QUFDaEQsWUFBTSxTQUFTLEtBQUssVUFBVSxTQUFTLFVBQVU7QUFDakQsVUFBSSxRQUFRO0FBQ1IsYUFBSyxjQUFjLFFBQVEsU0FBUyxVQUFVO0FBQUEsTUFDbEQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxrQkFBa0IsU0FBUyxXQUFXLEVBQUUsV0FBVyxHQUFHO0FBQ2xELFlBQU0sU0FBUyxLQUFLLGlCQUFpQixTQUFTLFVBQVU7QUFDeEQsVUFBSSxRQUFRO0FBQ1IsYUFBSyxpQkFBaUIsUUFBUSxTQUFTLFVBQVU7QUFBQSxNQUNyRDtBQUFBLElBQ0o7QUFBQSxJQUNBLHFCQUFxQixTQUFTLEVBQUUsV0FBVyxHQUFHO0FBQzFDLFlBQU0sV0FBVyxLQUFLLFNBQVMsVUFBVTtBQUN6QyxZQUFNLFlBQVksS0FBSyxVQUFVLFNBQVMsVUFBVTtBQUNwRCxZQUFNLHNCQUFzQixRQUFRLFFBQVEsSUFBSSxLQUFLLE9BQU8sbUJBQW1CLEtBQUssVUFBVSxHQUFHO0FBQ2pHLFVBQUksVUFBVTtBQUNWLGVBQU8sYUFBYSx1QkFBdUIsUUFBUSxRQUFRLFFBQVE7QUFBQSxNQUN2RSxPQUNLO0FBQ0QsZUFBTztBQUFBLE1BQ1g7QUFBQSxJQUNKO0FBQUEsSUFDQSx3QkFBd0IsVUFBVSxlQUFlO0FBQzdDLFlBQU0sYUFBYSxLQUFLLHFDQUFxQyxhQUFhO0FBQzFFLFVBQUksWUFBWTtBQUNaLGFBQUssZ0NBQWdDLFVBQVU7QUFBQSxNQUNuRDtBQUFBLElBQ0o7QUFBQSxJQUNBLDZCQUE2QixVQUFVLGVBQWU7QUFDbEQsWUFBTSxhQUFhLEtBQUsscUNBQXFDLGFBQWE7QUFDMUUsVUFBSSxZQUFZO0FBQ1osYUFBSyxnQ0FBZ0MsVUFBVTtBQUFBLE1BQ25EO0FBQUEsSUFDSjtBQUFBLElBQ0EsMEJBQTBCLFVBQVUsZUFBZTtBQUMvQyxZQUFNLGFBQWEsS0FBSyxxQ0FBcUMsYUFBYTtBQUMxRSxVQUFJLFlBQVk7QUFDWixhQUFLLGdDQUFnQyxVQUFVO0FBQUEsTUFDbkQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxjQUFjLFFBQVEsU0FBUyxZQUFZO0FBQ3ZDLFVBQUlEO0FBQ0osVUFBSSxDQUFDLEtBQUsscUJBQXFCLElBQUksWUFBWSxPQUFPLEdBQUc7QUFDckQsYUFBSyxjQUFjLElBQUksWUFBWSxNQUFNO0FBQ3pDLGFBQUsscUJBQXFCLElBQUksWUFBWSxPQUFPO0FBQ2pELFNBQUNBLE1BQUssS0FBSyxvQkFBb0IsSUFBSSxVQUFVLE9BQU8sUUFBUUEsUUFBTyxTQUFTLFNBQVNBLElBQUcsTUFBTSxNQUFNLEtBQUssU0FBUyxnQkFBZ0IsUUFBUSxTQUFTLFVBQVUsQ0FBQztBQUFBLE1BQ2xLO0FBQUEsSUFDSjtBQUFBLElBQ0EsaUJBQWlCLFFBQVEsU0FBUyxZQUFZO0FBQzFDLFVBQUlBO0FBQ0osVUFBSSxLQUFLLHFCQUFxQixJQUFJLFlBQVksT0FBTyxHQUFHO0FBQ3BELGFBQUssY0FBYyxPQUFPLFlBQVksTUFBTTtBQUM1QyxhQUFLLHFCQUFxQixPQUFPLFlBQVksT0FBTztBQUNwRCxTQUFDQSxNQUFLLEtBQUssb0JBQ04sSUFBSSxVQUFVLE9BQU8sUUFBUUEsUUFBTyxTQUFTLFNBQVNBLElBQUcsTUFBTSxNQUFNLEtBQUssU0FBUyxtQkFBbUIsUUFBUSxTQUFTLFVBQVUsQ0FBQztBQUFBLE1BQzNJO0FBQUEsSUFDSjtBQUFBLElBQ0EsdUJBQXVCO0FBQ25CLGlCQUFXLGNBQWMsS0FBSyxxQkFBcUIsTUFBTTtBQUNyRCxtQkFBVyxXQUFXLEtBQUsscUJBQXFCLGdCQUFnQixVQUFVLEdBQUc7QUFDekUscUJBQVcsVUFBVSxLQUFLLGNBQWMsZ0JBQWdCLFVBQVUsR0FBRztBQUNqRSxpQkFBSyxpQkFBaUIsUUFBUSxTQUFTLFVBQVU7QUFBQSxVQUNyRDtBQUFBLFFBQ0o7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsZ0NBQWdDLFlBQVk7QUFDeEMsWUFBTSxXQUFXLEtBQUssb0JBQW9CLElBQUksVUFBVTtBQUN4RCxVQUFJLFVBQVU7QUFDVixpQkFBUyxXQUFXLEtBQUssU0FBUyxVQUFVO0FBQUEsTUFDaEQ7QUFBQSxJQUNKO0FBQUEsSUFDQSwrQkFBK0IsWUFBWTtBQUN2QyxZQUFNLFdBQVcsS0FBSyxTQUFTLFVBQVU7QUFDekMsWUFBTSxtQkFBbUIsSUFBSSxpQkFBaUIsU0FBUyxNQUFNLFVBQVUsTUFBTSxFQUFFLFdBQVcsQ0FBQztBQUMzRixXQUFLLG9CQUFvQixJQUFJLFlBQVksZ0JBQWdCO0FBQ3pELHVCQUFpQixNQUFNO0FBQUEsSUFDM0I7QUFBQSxJQUNBLGdDQUFnQyxZQUFZO0FBQ3hDLFlBQU0sZ0JBQWdCLEtBQUssMkJBQTJCLFVBQVU7QUFDaEUsWUFBTSxvQkFBb0IsSUFBSSxrQkFBa0IsS0FBSyxNQUFNLFNBQVMsZUFBZSxJQUFJO0FBQ3ZGLFdBQUsscUJBQXFCLElBQUksWUFBWSxpQkFBaUI7QUFDM0Qsd0JBQWtCLE1BQU07QUFBQSxJQUM1QjtBQUFBLElBQ0EsU0FBUyxZQUFZO0FBQ2pCLGFBQU8sS0FBSyxNQUFNLFFBQVEseUJBQXlCLFVBQVU7QUFBQSxJQUNqRTtBQUFBLElBQ0EsMkJBQTJCLFlBQVk7QUFDbkMsYUFBTyxLQUFLLE1BQU0sT0FBTyx3QkFBd0IsS0FBSyxZQUFZLFVBQVU7QUFBQSxJQUNoRjtBQUFBLElBQ0EscUNBQXFDLGVBQWU7QUFDaEQsYUFBTyxLQUFLLGtCQUFrQixLQUFLLENBQUMsZUFBZSxLQUFLLDJCQUEyQixVQUFVLE1BQU0sYUFBYTtBQUFBLElBQ3BIO0FBQUEsSUFDQSxJQUFJLHFCQUFxQjtBQUNyQixZQUFNLGVBQWUsSUFBSSxTQUFTO0FBQ2xDLFdBQUssT0FBTyxRQUFRLFFBQVEsQ0FBQyxXQUFXO0FBQ3BDLGNBQU0sY0FBYyxPQUFPLFdBQVc7QUFDdEMsY0FBTSxVQUFVLGlDQUFpQyxhQUFhLFNBQVM7QUFDdkUsZ0JBQVEsUUFBUSxDQUFDLFdBQVcsYUFBYSxJQUFJLFFBQVEsT0FBTyxVQUFVLENBQUM7QUFBQSxNQUMzRSxDQUFDO0FBQ0QsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLElBQUksb0JBQW9CO0FBQ3BCLGFBQU8sS0FBSyxtQkFBbUIsZ0JBQWdCLEtBQUssVUFBVTtBQUFBLElBQ2xFO0FBQUEsSUFDQSxJQUFJLGlDQUFpQztBQUNqQyxhQUFPLEtBQUssbUJBQW1CLGdCQUFnQixLQUFLLFVBQVU7QUFBQSxJQUNsRTtBQUFBLElBQ0EsSUFBSSxvQkFBb0I7QUFDcEIsWUFBTSxjQUFjLEtBQUs7QUFDekIsYUFBTyxLQUFLLE9BQU8sU0FBUyxPQUFPLENBQUMsWUFBWSxZQUFZLFNBQVMsUUFBUSxVQUFVLENBQUM7QUFBQSxJQUM1RjtBQUFBLElBQ0EsVUFBVSxTQUFTLFlBQVk7QUFDM0IsYUFBTyxDQUFDLENBQUMsS0FBSyxVQUFVLFNBQVMsVUFBVSxLQUFLLENBQUMsQ0FBQyxLQUFLLGlCQUFpQixTQUFTLFVBQVU7QUFBQSxJQUMvRjtBQUFBLElBQ0EsVUFBVSxTQUFTLFlBQVk7QUFDM0IsYUFBTyxLQUFLLFlBQVkscUNBQXFDLFNBQVMsVUFBVTtBQUFBLElBQ3BGO0FBQUEsSUFDQSxpQkFBaUIsU0FBUyxZQUFZO0FBQ2xDLGFBQU8sS0FBSyxjQUFjLGdCQUFnQixVQUFVLEVBQUUsS0FBSyxDQUFDLFdBQVcsT0FBTyxZQUFZLE9BQU87QUFBQSxJQUNyRztBQUFBLElBQ0EsSUFBSSxRQUFRO0FBQ1IsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxjQUFjO0FBQ2QsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsYUFBTyxLQUFLLFlBQVk7QUFBQSxJQUM1QjtBQUFBLEVBQ0o7QUFFQSxNQUFNLFVBQU4sTUFBYztBQUFBLElBQ1YsWUFBWSxRQUFRLE9BQU87QUFDdkIsV0FBSyxtQkFBbUIsQ0FBQyxjQUFjLFNBQVMsQ0FBQyxNQUFNO0FBQ25ELGNBQU0sRUFBRSxZQUFZLFlBQVksUUFBUSxJQUFJO0FBQzVDLGlCQUFTLE9BQU8sT0FBTyxFQUFFLFlBQVksWUFBWSxRQUFRLEdBQUcsTUFBTTtBQUNsRSxhQUFLLFlBQVksaUJBQWlCLEtBQUssWUFBWSxjQUFjLE1BQU07QUFBQSxNQUMzRTtBQUNBLFdBQUssU0FBUztBQUNkLFdBQUssUUFBUTtBQUNiLFdBQUssYUFBYSxJQUFJLE9BQU8sc0JBQXNCLElBQUk7QUFDdkQsV0FBSyxrQkFBa0IsSUFBSSxnQkFBZ0IsTUFBTSxLQUFLLFVBQVU7QUFDaEUsV0FBSyxnQkFBZ0IsSUFBSSxjQUFjLE1BQU0sS0FBSyxVQUFVO0FBQzVELFdBQUssaUJBQWlCLElBQUksZUFBZSxNQUFNLElBQUk7QUFDbkQsV0FBSyxpQkFBaUIsSUFBSSxlQUFlLE1BQU0sSUFBSTtBQUNuRCxVQUFJO0FBQ0EsYUFBSyxXQUFXLFdBQVc7QUFDM0IsYUFBSyxpQkFBaUIsWUFBWTtBQUFBLE1BQ3RDLFNBQ09ELFFBQU87QUFDVixhQUFLLFlBQVlBLFFBQU8seUJBQXlCO0FBQUEsTUFDckQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxnQkFBZ0IsTUFBTTtBQUMzQixXQUFLLGNBQWMsTUFBTTtBQUN6QixXQUFLLGVBQWUsTUFBTTtBQUMxQixXQUFLLGVBQWUsTUFBTTtBQUMxQixVQUFJO0FBQ0EsYUFBSyxXQUFXLFFBQVE7QUFDeEIsYUFBSyxpQkFBaUIsU0FBUztBQUFBLE1BQ25DLFNBQ09BLFFBQU87QUFDVixhQUFLLFlBQVlBLFFBQU8sdUJBQXVCO0FBQUEsTUFDbkQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxlQUFlLFFBQVE7QUFBQSxJQUNoQztBQUFBLElBQ0EsYUFBYTtBQUNULFVBQUk7QUFDQSxhQUFLLFdBQVcsV0FBVztBQUMzQixhQUFLLGlCQUFpQixZQUFZO0FBQUEsTUFDdEMsU0FDT0EsUUFBTztBQUNWLGFBQUssWUFBWUEsUUFBTywwQkFBMEI7QUFBQSxNQUN0RDtBQUNBLFdBQUssZUFBZSxLQUFLO0FBQ3pCLFdBQUssZUFBZSxLQUFLO0FBQ3pCLFdBQUssY0FBYyxLQUFLO0FBQ3hCLFdBQUssZ0JBQWdCLEtBQUs7QUFBQSxJQUM5QjtBQUFBLElBQ0EsSUFBSSxjQUFjO0FBQ2QsYUFBTyxLQUFLLE9BQU87QUFBQSxJQUN2QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLE9BQU87QUFBQSxJQUN2QjtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsYUFBTyxLQUFLLFlBQVk7QUFBQSxJQUM1QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLFlBQVk7QUFBQSxJQUM1QjtBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxnQkFBZ0I7QUFDaEIsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsWUFBWUEsUUFBTyxTQUFTLFNBQVMsQ0FBQyxHQUFHO0FBQ3JDLFlBQU0sRUFBRSxZQUFZLFlBQVksUUFBUSxJQUFJO0FBQzVDLGVBQVMsT0FBTyxPQUFPLEVBQUUsWUFBWSxZQUFZLFFBQVEsR0FBRyxNQUFNO0FBQ2xFLFdBQUssWUFBWSxZQUFZQSxRQUFPLFNBQVMsT0FBTyxJQUFJLE1BQU07QUFBQSxJQUNsRTtBQUFBLElBQ0EsZ0JBQWdCLFNBQVMsTUFBTTtBQUMzQixXQUFLLHVCQUF1QixHQUFHLElBQUksbUJBQW1CLE9BQU87QUFBQSxJQUNqRTtBQUFBLElBQ0EsbUJBQW1CLFNBQVMsTUFBTTtBQUM5QixXQUFLLHVCQUF1QixHQUFHLElBQUksc0JBQXNCLE9BQU87QUFBQSxJQUNwRTtBQUFBLElBQ0EsZ0JBQWdCLFFBQVEsU0FBUyxNQUFNO0FBQ25DLFdBQUssdUJBQXVCLEdBQUcsa0JBQWtCLElBQUksQ0FBQyxtQkFBbUIsUUFBUSxPQUFPO0FBQUEsSUFDNUY7QUFBQSxJQUNBLG1CQUFtQixRQUFRLFNBQVMsTUFBTTtBQUN0QyxXQUFLLHVCQUF1QixHQUFHLGtCQUFrQixJQUFJLENBQUMsc0JBQXNCLFFBQVEsT0FBTztBQUFBLElBQy9GO0FBQUEsSUFDQSx1QkFBdUIsZUFBZSxNQUFNO0FBQ3hDLFlBQU0sYUFBYSxLQUFLO0FBQ3hCLFVBQUksT0FBTyxXQUFXLFVBQVUsS0FBSyxZQUFZO0FBQzdDLG1CQUFXLFVBQVUsRUFBRSxHQUFHLElBQUk7QUFBQSxNQUNsQztBQUFBLElBQ0o7QUFBQSxFQUNKO0FBRUEsV0FBUyxNQUFNLGFBQWE7QUFDeEIsV0FBTyxPQUFPLGFBQWEscUJBQXFCLFdBQVcsQ0FBQztBQUFBLEVBQ2hFO0FBQ0EsV0FBUyxPQUFPLGFBQWEsWUFBWTtBQUNyQyxVQUFNLG9CQUFvQixPQUFPLFdBQVc7QUFDNUMsVUFBTSxtQkFBbUIsb0JBQW9CLFlBQVksV0FBVyxVQUFVO0FBQzlFLFdBQU8saUJBQWlCLGtCQUFrQixXQUFXLGdCQUFnQjtBQUNyRSxXQUFPO0FBQUEsRUFDWDtBQUNBLFdBQVMscUJBQXFCLGFBQWE7QUFDdkMsVUFBTSxZQUFZLGlDQUFpQyxhQUFhLFdBQVc7QUFDM0UsV0FBTyxVQUFVLE9BQU8sQ0FBQyxtQkFBbUIsYUFBYTtBQUNyRCxZQUFNLGFBQWEsU0FBUyxXQUFXO0FBQ3ZDLGlCQUFXLE9BQU8sWUFBWTtBQUMxQixjQUFNLGFBQWEsa0JBQWtCLEdBQUcsS0FBSyxDQUFDO0FBQzlDLDBCQUFrQixHQUFHLElBQUksT0FBTyxPQUFPLFlBQVksV0FBVyxHQUFHLENBQUM7QUFBQSxNQUN0RTtBQUNBLGFBQU87QUFBQSxJQUNYLEdBQUcsQ0FBQyxDQUFDO0FBQUEsRUFDVDtBQUNBLFdBQVMsb0JBQW9CLFdBQVcsWUFBWTtBQUNoRCxXQUFPLFdBQVcsVUFBVSxFQUFFLE9BQU8sQ0FBQyxrQkFBa0IsUUFBUTtBQUM1RCxZQUFNLGFBQWEsc0JBQXNCLFdBQVcsWUFBWSxHQUFHO0FBQ25FLFVBQUksWUFBWTtBQUNaLGVBQU8sT0FBTyxrQkFBa0IsRUFBRSxDQUFDLEdBQUcsR0FBRyxXQUFXLENBQUM7QUFBQSxNQUN6RDtBQUNBLGFBQU87QUFBQSxJQUNYLEdBQUcsQ0FBQyxDQUFDO0FBQUEsRUFDVDtBQUNBLFdBQVMsc0JBQXNCLFdBQVcsWUFBWSxLQUFLO0FBQ3ZELFVBQU0sc0JBQXNCLE9BQU8seUJBQXlCLFdBQVcsR0FBRztBQUMxRSxVQUFNLGtCQUFrQix1QkFBdUIsV0FBVztBQUMxRCxRQUFJLENBQUMsaUJBQWlCO0FBQ2xCLFlBQU0sYUFBYSxPQUFPLHlCQUF5QixZQUFZLEdBQUcsRUFBRTtBQUNwRSxVQUFJLHFCQUFxQjtBQUNyQixtQkFBVyxNQUFNLG9CQUFvQixPQUFPLFdBQVc7QUFDdkQsbUJBQVcsTUFBTSxvQkFBb0IsT0FBTyxXQUFXO0FBQUEsTUFDM0Q7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLEVBQ0o7QUFDQSxNQUFNLGNBQWMsTUFBTTtBQUN0QixRQUFJLE9BQU8sT0FBTyx5QkFBeUIsWUFBWTtBQUNuRCxhQUFPLENBQUMsV0FBVyxDQUFDLEdBQUcsT0FBTyxvQkFBb0IsTUFBTSxHQUFHLEdBQUcsT0FBTyxzQkFBc0IsTUFBTSxDQUFDO0FBQUEsSUFDdEcsT0FDSztBQUNELGFBQU8sT0FBTztBQUFBLElBQ2xCO0FBQUEsRUFDSixHQUFHO0FBQ0gsTUFBTSxVQUFVLE1BQU07QUFDbEIsYUFBUyxrQkFBa0IsYUFBYTtBQUNwQyxlQUFTLFdBQVc7QUFDaEIsZUFBTyxRQUFRLFVBQVUsYUFBYSxXQUFXLFVBQVU7QUFBQSxNQUMvRDtBQUNBLGVBQVMsWUFBWSxPQUFPLE9BQU8sWUFBWSxXQUFXO0FBQUEsUUFDdEQsYUFBYSxFQUFFLE9BQU8sU0FBUztBQUFBLE1BQ25DLENBQUM7QUFDRCxjQUFRLGVBQWUsVUFBVSxXQUFXO0FBQzVDLGFBQU87QUFBQSxJQUNYO0FBQ0EsYUFBUyx1QkFBdUI7QUFDNUIsWUFBTSxJQUFJLFdBQVk7QUFDbEIsYUFBSyxFQUFFLEtBQUssSUFBSTtBQUFBLE1BQ3BCO0FBQ0EsWUFBTSxJQUFJLGtCQUFrQixDQUFDO0FBQzdCLFFBQUUsVUFBVSxJQUFJLFdBQVk7QUFBQSxNQUFFO0FBQzlCLGFBQU8sSUFBSSxFQUFFO0FBQUEsSUFDakI7QUFDQSxRQUFJO0FBQ0EsMkJBQXFCO0FBQ3JCLGFBQU87QUFBQSxJQUNYLFNBQ09BLFFBQU87QUFDVixhQUFPLENBQUMsZ0JBQWdCLE1BQU0saUJBQWlCLFlBQVk7QUFBQSxNQUMzRDtBQUFBLElBQ0o7QUFBQSxFQUNKLEdBQUc7QUFFSCxXQUFTLGdCQUFnQixZQUFZO0FBQ2pDLFdBQU87QUFBQSxNQUNILFlBQVksV0FBVztBQUFBLE1BQ3ZCLHVCQUF1QixNQUFNLFdBQVcscUJBQXFCO0FBQUEsSUFDakU7QUFBQSxFQUNKO0FBRUEsTUFBTSxTQUFOLE1BQWE7QUFBQSxJQUNULFlBQVksYUFBYSxZQUFZO0FBQ2pDLFdBQUssY0FBYztBQUNuQixXQUFLLGFBQWEsZ0JBQWdCLFVBQVU7QUFDNUMsV0FBSyxrQkFBa0Isb0JBQUksUUFBUTtBQUNuQyxXQUFLLG9CQUFvQixvQkFBSSxJQUFJO0FBQUEsSUFDckM7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxXQUFXO0FBQUEsSUFDM0I7QUFBQSxJQUNBLElBQUksd0JBQXdCO0FBQ3hCLGFBQU8sS0FBSyxXQUFXO0FBQUEsSUFDM0I7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sTUFBTSxLQUFLLEtBQUssaUJBQWlCO0FBQUEsSUFDNUM7QUFBQSxJQUNBLHVCQUF1QixPQUFPO0FBQzFCLFlBQU0sVUFBVSxLQUFLLHFCQUFxQixLQUFLO0FBQy9DLFdBQUssa0JBQWtCLElBQUksT0FBTztBQUNsQyxjQUFRLFFBQVE7QUFBQSxJQUNwQjtBQUFBLElBQ0EsMEJBQTBCLE9BQU87QUFDN0IsWUFBTSxVQUFVLEtBQUssZ0JBQWdCLElBQUksS0FBSztBQUM5QyxVQUFJLFNBQVM7QUFDVCxhQUFLLGtCQUFrQixPQUFPLE9BQU87QUFDckMsZ0JBQVEsV0FBVztBQUFBLE1BQ3ZCO0FBQUEsSUFDSjtBQUFBLElBQ0EscUJBQXFCLE9BQU87QUFDeEIsVUFBSSxVQUFVLEtBQUssZ0JBQWdCLElBQUksS0FBSztBQUM1QyxVQUFJLENBQUMsU0FBUztBQUNWLGtCQUFVLElBQUksUUFBUSxNQUFNLEtBQUs7QUFDakMsYUFBSyxnQkFBZ0IsSUFBSSxPQUFPLE9BQU87QUFBQSxNQUMzQztBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsRUFDSjtBQUVBLE1BQU0sV0FBTixNQUFlO0FBQUEsSUFDWCxZQUFZLE9BQU87QUFDZixXQUFLLFFBQVE7QUFBQSxJQUNqQjtBQUFBLElBQ0EsSUFBSSxNQUFNO0FBQ04sYUFBTyxLQUFLLEtBQUssSUFBSSxLQUFLLFdBQVcsSUFBSSxDQUFDO0FBQUEsSUFDOUM7QUFBQSxJQUNBLElBQUksTUFBTTtBQUNOLGFBQU8sS0FBSyxPQUFPLElBQUksRUFBRSxDQUFDO0FBQUEsSUFDOUI7QUFBQSxJQUNBLE9BQU8sTUFBTTtBQUNULFlBQU0sY0FBYyxLQUFLLEtBQUssSUFBSSxLQUFLLFdBQVcsSUFBSSxDQUFDLEtBQUs7QUFDNUQsYUFBTyxTQUFTLFdBQVc7QUFBQSxJQUMvQjtBQUFBLElBQ0EsaUJBQWlCLE1BQU07QUFDbkIsYUFBTyxLQUFLLEtBQUssdUJBQXVCLEtBQUssV0FBVyxJQUFJLENBQUM7QUFBQSxJQUNqRTtBQUFBLElBQ0EsV0FBVyxNQUFNO0FBQ2IsYUFBTyxHQUFHLElBQUk7QUFBQSxJQUNsQjtBQUFBLElBQ0EsSUFBSSxPQUFPO0FBQ1AsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLEVBQ0o7QUFFQSxNQUFNLFVBQU4sTUFBYztBQUFBLElBQ1YsWUFBWSxPQUFPO0FBQ2YsV0FBSyxRQUFRO0FBQUEsSUFDakI7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksS0FBSztBQUNMLFlBQU0sT0FBTyxLQUFLLHVCQUF1QixHQUFHO0FBQzVDLGFBQU8sS0FBSyxRQUFRLGFBQWEsSUFBSTtBQUFBLElBQ3pDO0FBQUEsSUFDQSxJQUFJLEtBQUssT0FBTztBQUNaLFlBQU0sT0FBTyxLQUFLLHVCQUF1QixHQUFHO0FBQzVDLFdBQUssUUFBUSxhQUFhLE1BQU0sS0FBSztBQUNyQyxhQUFPLEtBQUssSUFBSSxHQUFHO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksS0FBSztBQUNMLFlBQU0sT0FBTyxLQUFLLHVCQUF1QixHQUFHO0FBQzVDLGFBQU8sS0FBSyxRQUFRLGFBQWEsSUFBSTtBQUFBLElBQ3pDO0FBQUEsSUFDQSxPQUFPLEtBQUs7QUFDUixVQUFJLEtBQUssSUFBSSxHQUFHLEdBQUc7QUFDZixjQUFNLE9BQU8sS0FBSyx1QkFBdUIsR0FBRztBQUM1QyxhQUFLLFFBQVEsZ0JBQWdCLElBQUk7QUFDakMsZUFBTztBQUFBLE1BQ1gsT0FDSztBQUNELGVBQU87QUFBQSxNQUNYO0FBQUEsSUFDSjtBQUFBLElBQ0EsdUJBQXVCLEtBQUs7QUFDeEIsYUFBTyxRQUFRLEtBQUssVUFBVSxJQUFJLFVBQVUsR0FBRyxDQUFDO0FBQUEsSUFDcEQ7QUFBQSxFQUNKO0FBRUEsTUFBTSxRQUFOLE1BQVk7QUFBQSxJQUNSLFlBQVksUUFBUTtBQUNoQixXQUFLLHFCQUFxQixvQkFBSSxRQUFRO0FBQ3RDLFdBQUssU0FBUztBQUFBLElBQ2xCO0FBQUEsSUFDQSxLQUFLLFFBQVEsS0FBSyxTQUFTO0FBQ3ZCLFVBQUksYUFBYSxLQUFLLG1CQUFtQixJQUFJLE1BQU07QUFDbkQsVUFBSSxDQUFDLFlBQVk7QUFDYixxQkFBYSxvQkFBSSxJQUFJO0FBQ3JCLGFBQUssbUJBQW1CLElBQUksUUFBUSxVQUFVO0FBQUEsTUFDbEQ7QUFDQSxVQUFJLENBQUMsV0FBVyxJQUFJLEdBQUcsR0FBRztBQUN0QixtQkFBVyxJQUFJLEdBQUc7QUFDbEIsYUFBSyxPQUFPLEtBQUssU0FBUyxNQUFNO0FBQUEsTUFDcEM7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLFdBQVMsNEJBQTRCLGVBQWUsT0FBTztBQUN2RCxXQUFPLElBQUksYUFBYSxNQUFNLEtBQUs7QUFBQSxFQUN2QztBQUVBLE1BQU0sWUFBTixNQUFnQjtBQUFBLElBQ1osWUFBWSxPQUFPO0FBQ2YsV0FBSyxRQUFRO0FBQUEsSUFDakI7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksU0FBUztBQUNULGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksWUFBWTtBQUNaLGFBQU8sS0FBSyxLQUFLLFVBQVUsS0FBSztBQUFBLElBQ3BDO0FBQUEsSUFDQSxRQUFRLGFBQWE7QUFDakIsYUFBTyxZQUFZLE9BQU8sQ0FBQyxRQUFRLGVBQWUsVUFBVSxLQUFLLFdBQVcsVUFBVSxLQUFLLEtBQUssaUJBQWlCLFVBQVUsR0FBRyxNQUFTO0FBQUEsSUFDM0k7QUFBQSxJQUNBLFdBQVcsYUFBYTtBQUNwQixhQUFPLFlBQVksT0FBTyxDQUFDLFNBQVMsZUFBZTtBQUFBLFFBQy9DLEdBQUc7QUFBQSxRQUNILEdBQUcsS0FBSyxlQUFlLFVBQVU7QUFBQSxRQUNqQyxHQUFHLEtBQUsscUJBQXFCLFVBQVU7QUFBQSxNQUMzQyxHQUFHLENBQUMsQ0FBQztBQUFBLElBQ1Q7QUFBQSxJQUNBLFdBQVcsWUFBWTtBQUNuQixZQUFNLFdBQVcsS0FBSyx5QkFBeUIsVUFBVTtBQUN6RCxhQUFPLEtBQUssTUFBTSxZQUFZLFFBQVE7QUFBQSxJQUMxQztBQUFBLElBQ0EsZUFBZSxZQUFZO0FBQ3ZCLFlBQU0sV0FBVyxLQUFLLHlCQUF5QixVQUFVO0FBQ3pELGFBQU8sS0FBSyxNQUFNLGdCQUFnQixRQUFRO0FBQUEsSUFDOUM7QUFBQSxJQUNBLHlCQUF5QixZQUFZO0FBQ2pDLFlBQU0sZ0JBQWdCLEtBQUssT0FBTyx3QkFBd0IsS0FBSyxVQUFVO0FBQ3pFLGFBQU8sNEJBQTRCLGVBQWUsVUFBVTtBQUFBLElBQ2hFO0FBQUEsSUFDQSxpQkFBaUIsWUFBWTtBQUN6QixZQUFNLFdBQVcsS0FBSywrQkFBK0IsVUFBVTtBQUMvRCxhQUFPLEtBQUssVUFBVSxLQUFLLE1BQU0sWUFBWSxRQUFRLEdBQUcsVUFBVTtBQUFBLElBQ3RFO0FBQUEsSUFDQSxxQkFBcUIsWUFBWTtBQUM3QixZQUFNLFdBQVcsS0FBSywrQkFBK0IsVUFBVTtBQUMvRCxhQUFPLEtBQUssTUFBTSxnQkFBZ0IsUUFBUSxFQUFFLElBQUksQ0FBQyxZQUFZLEtBQUssVUFBVSxTQUFTLFVBQVUsQ0FBQztBQUFBLElBQ3BHO0FBQUEsSUFDQSwrQkFBK0IsWUFBWTtBQUN2QyxZQUFNLG1CQUFtQixHQUFHLEtBQUssVUFBVSxJQUFJLFVBQVU7QUFDekQsYUFBTyw0QkFBNEIsS0FBSyxPQUFPLGlCQUFpQixnQkFBZ0I7QUFBQSxJQUNwRjtBQUFBLElBQ0EsVUFBVSxTQUFTLFlBQVk7QUFDM0IsVUFBSSxTQUFTO0FBQ1QsY0FBTSxFQUFFLFdBQVcsSUFBSTtBQUN2QixjQUFNLGdCQUFnQixLQUFLLE9BQU87QUFDbEMsY0FBTSx1QkFBdUIsS0FBSyxPQUFPLHdCQUF3QixVQUFVO0FBQzNFLGFBQUssTUFBTSxLQUFLLFNBQVMsVUFBVSxVQUFVLElBQUksa0JBQWtCLGFBQWEsS0FBSyxVQUFVLElBQUksVUFBVSxVQUFVLG9CQUFvQixLQUFLLFVBQVUsVUFDL0ksYUFBYSwrRUFBK0U7QUFBQSxNQUMzRztBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxJQUFJLFFBQVE7QUFDUixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsRUFDSjtBQUVBLE1BQU0sWUFBTixNQUFnQjtBQUFBLElBQ1osWUFBWSxPQUFPLG1CQUFtQjtBQUNsQyxXQUFLLFFBQVE7QUFDYixXQUFLLG9CQUFvQjtBQUFBLElBQzdCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFlBQVk7QUFDWixhQUFPLEtBQUssS0FBSyxVQUFVLEtBQUs7QUFBQSxJQUNwQztBQUFBLElBQ0EsUUFBUSxhQUFhO0FBQ2pCLGFBQU8sWUFBWSxPQUFPLENBQUMsUUFBUSxlQUFlLFVBQVUsS0FBSyxXQUFXLFVBQVUsR0FBRyxNQUFTO0FBQUEsSUFDdEc7QUFBQSxJQUNBLFdBQVcsYUFBYTtBQUNwQixhQUFPLFlBQVksT0FBTyxDQUFDLFNBQVMsZUFBZSxDQUFDLEdBQUcsU0FBUyxHQUFHLEtBQUssZUFBZSxVQUFVLENBQUMsR0FBRyxDQUFDLENBQUM7QUFBQSxJQUMzRztBQUFBLElBQ0EseUJBQXlCLFlBQVk7QUFDakMsWUFBTSxnQkFBZ0IsS0FBSyxPQUFPLHdCQUF3QixLQUFLLFlBQVksVUFBVTtBQUNyRixhQUFPLEtBQUssa0JBQWtCLGFBQWEsYUFBYTtBQUFBLElBQzVEO0FBQUEsSUFDQSxXQUFXLFlBQVk7QUFDbkIsWUFBTSxXQUFXLEtBQUsseUJBQXlCLFVBQVU7QUFDekQsVUFBSTtBQUNBLGVBQU8sS0FBSyxZQUFZLFVBQVUsVUFBVTtBQUFBLElBQ3BEO0FBQUEsSUFDQSxlQUFlLFlBQVk7QUFDdkIsWUFBTSxXQUFXLEtBQUsseUJBQXlCLFVBQVU7QUFDekQsYUFBTyxXQUFXLEtBQUssZ0JBQWdCLFVBQVUsVUFBVSxJQUFJLENBQUM7QUFBQSxJQUNwRTtBQUFBLElBQ0EsWUFBWSxVQUFVLFlBQVk7QUFDOUIsWUFBTSxXQUFXLEtBQUssTUFBTSxjQUFjLFFBQVE7QUFDbEQsYUFBTyxTQUFTLE9BQU8sQ0FBQyxZQUFZLEtBQUssZUFBZSxTQUFTLFVBQVUsVUFBVSxDQUFDLEVBQUUsQ0FBQztBQUFBLElBQzdGO0FBQUEsSUFDQSxnQkFBZ0IsVUFBVSxZQUFZO0FBQ2xDLFlBQU0sV0FBVyxLQUFLLE1BQU0sY0FBYyxRQUFRO0FBQ2xELGFBQU8sU0FBUyxPQUFPLENBQUMsWUFBWSxLQUFLLGVBQWUsU0FBUyxVQUFVLFVBQVUsQ0FBQztBQUFBLElBQzFGO0FBQUEsSUFDQSxlQUFlLFNBQVMsVUFBVSxZQUFZO0FBQzFDLFlBQU0sc0JBQXNCLFFBQVEsYUFBYSxLQUFLLE1BQU0sT0FBTyxtQkFBbUIsS0FBSztBQUMzRixhQUFPLFFBQVEsUUFBUSxRQUFRLEtBQUssb0JBQW9CLE1BQU0sR0FBRyxFQUFFLFNBQVMsVUFBVTtBQUFBLElBQzFGO0FBQUEsRUFDSjtBQUVBLE1BQU0sUUFBTixNQUFNLE9BQU07QUFBQSxJQUNSLFlBQVksUUFBUSxTQUFTLFlBQVksUUFBUTtBQUM3QyxXQUFLLFVBQVUsSUFBSSxVQUFVLElBQUk7QUFDakMsV0FBSyxVQUFVLElBQUksU0FBUyxJQUFJO0FBQ2hDLFdBQUssT0FBTyxJQUFJLFFBQVEsSUFBSTtBQUM1QixXQUFLLGtCQUFrQixDQUFDRyxhQUFZO0FBQ2hDLGVBQU9BLFNBQVEsUUFBUSxLQUFLLGtCQUFrQixNQUFNLEtBQUs7QUFBQSxNQUM3RDtBQUNBLFdBQUssU0FBUztBQUNkLFdBQUssVUFBVTtBQUNmLFdBQUssYUFBYTtBQUNsQixXQUFLLFFBQVEsSUFBSSxNQUFNLE1BQU07QUFDN0IsV0FBSyxVQUFVLElBQUksVUFBVSxLQUFLLGVBQWUsT0FBTztBQUFBLElBQzVEO0FBQUEsSUFDQSxZQUFZLFVBQVU7QUFDbEIsYUFBTyxLQUFLLFFBQVEsUUFBUSxRQUFRLElBQUksS0FBSyxVQUFVLEtBQUssY0FBYyxRQUFRLEVBQUUsS0FBSyxLQUFLLGVBQWU7QUFBQSxJQUNqSDtBQUFBLElBQ0EsZ0JBQWdCLFVBQVU7QUFDdEIsYUFBTztBQUFBLFFBQ0gsR0FBSSxLQUFLLFFBQVEsUUFBUSxRQUFRLElBQUksQ0FBQyxLQUFLLE9BQU8sSUFBSSxDQUFDO0FBQUEsUUFDdkQsR0FBRyxLQUFLLGNBQWMsUUFBUSxFQUFFLE9BQU8sS0FBSyxlQUFlO0FBQUEsTUFDL0Q7QUFBQSxJQUNKO0FBQUEsSUFDQSxjQUFjLFVBQVU7QUFDcEIsYUFBTyxNQUFNLEtBQUssS0FBSyxRQUFRLGlCQUFpQixRQUFRLENBQUM7QUFBQSxJQUM3RDtBQUFBLElBQ0EsSUFBSSxxQkFBcUI7QUFDckIsYUFBTyw0QkFBNEIsS0FBSyxPQUFPLHFCQUFxQixLQUFLLFVBQVU7QUFBQSxJQUN2RjtBQUFBLElBQ0EsSUFBSSxrQkFBa0I7QUFDbEIsYUFBTyxLQUFLLFlBQVksU0FBUztBQUFBLElBQ3JDO0FBQUEsSUFDQSxJQUFJLGdCQUFnQjtBQUNoQixhQUFPLEtBQUssa0JBQ04sT0FDQSxJQUFJLE9BQU0sS0FBSyxRQUFRLFNBQVMsaUJBQWlCLEtBQUssWUFBWSxLQUFLLE1BQU0sTUFBTTtBQUFBLElBQzdGO0FBQUEsRUFDSjtBQUVBLE1BQU0sZ0JBQU4sTUFBb0I7QUFBQSxJQUNoQixZQUFZLFNBQVMsUUFBUSxVQUFVO0FBQ25DLFdBQUssVUFBVTtBQUNmLFdBQUssU0FBUztBQUNkLFdBQUssV0FBVztBQUNoQixXQUFLLG9CQUFvQixJQUFJLGtCQUFrQixLQUFLLFNBQVMsS0FBSyxxQkFBcUIsSUFBSTtBQUMzRixXQUFLLDhCQUE4QixvQkFBSSxRQUFRO0FBQy9DLFdBQUssdUJBQXVCLG9CQUFJLFFBQVE7QUFBQSxJQUM1QztBQUFBLElBQ0EsUUFBUTtBQUNKLFdBQUssa0JBQWtCLE1BQU07QUFBQSxJQUNqQztBQUFBLElBQ0EsT0FBTztBQUNILFdBQUssa0JBQWtCLEtBQUs7QUFBQSxJQUNoQztBQUFBLElBQ0EsSUFBSSxzQkFBc0I7QUFDdEIsYUFBTyxLQUFLLE9BQU87QUFBQSxJQUN2QjtBQUFBLElBQ0EsbUJBQW1CLE9BQU87QUFDdEIsWUFBTSxFQUFFLFNBQVMsU0FBUyxXQUFXLElBQUk7QUFDekMsYUFBTyxLQUFLLGtDQUFrQyxTQUFTLFVBQVU7QUFBQSxJQUNyRTtBQUFBLElBQ0Esa0NBQWtDLFNBQVMsWUFBWTtBQUNuRCxZQUFNLHFCQUFxQixLQUFLLGtDQUFrQyxPQUFPO0FBQ3pFLFVBQUksUUFBUSxtQkFBbUIsSUFBSSxVQUFVO0FBQzdDLFVBQUksQ0FBQyxPQUFPO0FBQ1IsZ0JBQVEsS0FBSyxTQUFTLG1DQUFtQyxTQUFTLFVBQVU7QUFDNUUsMkJBQW1CLElBQUksWUFBWSxLQUFLO0FBQUEsTUFDNUM7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0Esb0JBQW9CLFNBQVMsT0FBTztBQUNoQyxZQUFNLGtCQUFrQixLQUFLLHFCQUFxQixJQUFJLEtBQUssS0FBSyxLQUFLO0FBQ3JFLFdBQUsscUJBQXFCLElBQUksT0FBTyxjQUFjO0FBQ25ELFVBQUksa0JBQWtCLEdBQUc7QUFDckIsYUFBSyxTQUFTLGVBQWUsS0FBSztBQUFBLE1BQ3RDO0FBQUEsSUFDSjtBQUFBLElBQ0Esc0JBQXNCLFNBQVMsT0FBTztBQUNsQyxZQUFNLGlCQUFpQixLQUFLLHFCQUFxQixJQUFJLEtBQUs7QUFDMUQsVUFBSSxnQkFBZ0I7QUFDaEIsYUFBSyxxQkFBcUIsSUFBSSxPQUFPLGlCQUFpQixDQUFDO0FBQ3ZELFlBQUksa0JBQWtCLEdBQUc7QUFDckIsZUFBSyxTQUFTLGtCQUFrQixLQUFLO0FBQUEsUUFDekM7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0Esa0NBQWtDLFNBQVM7QUFDdkMsVUFBSSxxQkFBcUIsS0FBSyw0QkFBNEIsSUFBSSxPQUFPO0FBQ3JFLFVBQUksQ0FBQyxvQkFBb0I7QUFDckIsNkJBQXFCLG9CQUFJLElBQUk7QUFDN0IsYUFBSyw0QkFBNEIsSUFBSSxTQUFTLGtCQUFrQjtBQUFBLE1BQ3BFO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxFQUNKO0FBRUEsTUFBTSxTQUFOLE1BQWE7QUFBQSxJQUNULFlBQVksYUFBYTtBQUNyQixXQUFLLGNBQWM7QUFDbkIsV0FBSyxnQkFBZ0IsSUFBSSxjQUFjLEtBQUssU0FBUyxLQUFLLFFBQVEsSUFBSTtBQUN0RSxXQUFLLHFCQUFxQixJQUFJLFNBQVM7QUFDdkMsV0FBSyxzQkFBc0Isb0JBQUksSUFBSTtBQUFBLElBQ3ZDO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssWUFBWTtBQUFBLElBQzVCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxhQUFPLEtBQUssWUFBWTtBQUFBLElBQzVCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxhQUFPLEtBQUssWUFBWTtBQUFBLElBQzVCO0FBQUEsSUFDQSxJQUFJLHNCQUFzQjtBQUN0QixhQUFPLEtBQUssT0FBTztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLE1BQU0sS0FBSyxLQUFLLG9CQUFvQixPQUFPLENBQUM7QUFBQSxJQUN2RDtBQUFBLElBQ0EsSUFBSSxXQUFXO0FBQ1gsYUFBTyxLQUFLLFFBQVEsT0FBTyxDQUFDLFVBQVUsV0FBVyxTQUFTLE9BQU8sT0FBTyxRQUFRLEdBQUcsQ0FBQyxDQUFDO0FBQUEsSUFDekY7QUFBQSxJQUNBLFFBQVE7QUFDSixXQUFLLGNBQWMsTUFBTTtBQUFBLElBQzdCO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxjQUFjLEtBQUs7QUFBQSxJQUM1QjtBQUFBLElBQ0EsZUFBZSxZQUFZO0FBQ3ZCLFdBQUssaUJBQWlCLFdBQVcsVUFBVTtBQUMzQyxZQUFNLFNBQVMsSUFBSSxPQUFPLEtBQUssYUFBYSxVQUFVO0FBQ3RELFdBQUssY0FBYyxNQUFNO0FBQ3pCLFlBQU0sWUFBWSxXQUFXLHNCQUFzQjtBQUNuRCxVQUFJLFdBQVc7QUFDWCxrQkFBVSxLQUFLLFdBQVcsdUJBQXVCLFdBQVcsWUFBWSxLQUFLLFdBQVc7QUFBQSxNQUM1RjtBQUFBLElBQ0o7QUFBQSxJQUNBLGlCQUFpQixZQUFZO0FBQ3pCLFlBQU0sU0FBUyxLQUFLLG9CQUFvQixJQUFJLFVBQVU7QUFDdEQsVUFBSSxRQUFRO0FBQ1IsYUFBSyxpQkFBaUIsTUFBTTtBQUFBLE1BQ2hDO0FBQUEsSUFDSjtBQUFBLElBQ0Esa0NBQWtDLFNBQVMsWUFBWTtBQUNuRCxZQUFNLFNBQVMsS0FBSyxvQkFBb0IsSUFBSSxVQUFVO0FBQ3RELFVBQUksUUFBUTtBQUNSLGVBQU8sT0FBTyxTQUFTLEtBQUssQ0FBQyxZQUFZLFFBQVEsV0FBVyxPQUFPO0FBQUEsTUFDdkU7QUFBQSxJQUNKO0FBQUEsSUFDQSw2Q0FBNkMsU0FBUyxZQUFZO0FBQzlELFlBQU0sUUFBUSxLQUFLLGNBQWMsa0NBQWtDLFNBQVMsVUFBVTtBQUN0RixVQUFJLE9BQU87QUFDUCxhQUFLLGNBQWMsb0JBQW9CLE1BQU0sU0FBUyxLQUFLO0FBQUEsTUFDL0QsT0FDSztBQUNELGdCQUFRLE1BQU0sa0RBQWtELFVBQVUsa0JBQWtCLE9BQU87QUFBQSxNQUN2RztBQUFBLElBQ0o7QUFBQSxJQUNBLFlBQVlILFFBQU8sU0FBUyxRQUFRO0FBQ2hDLFdBQUssWUFBWSxZQUFZQSxRQUFPLFNBQVMsTUFBTTtBQUFBLElBQ3ZEO0FBQUEsSUFDQSxtQ0FBbUMsU0FBUyxZQUFZO0FBQ3BELGFBQU8sSUFBSSxNQUFNLEtBQUssUUFBUSxTQUFTLFlBQVksS0FBSyxNQUFNO0FBQUEsSUFDbEU7QUFBQSxJQUNBLGVBQWUsT0FBTztBQUNsQixXQUFLLG1CQUFtQixJQUFJLE1BQU0sWUFBWSxLQUFLO0FBQ25ELFlBQU0sU0FBUyxLQUFLLG9CQUFvQixJQUFJLE1BQU0sVUFBVTtBQUM1RCxVQUFJLFFBQVE7QUFDUixlQUFPLHVCQUF1QixLQUFLO0FBQUEsTUFDdkM7QUFBQSxJQUNKO0FBQUEsSUFDQSxrQkFBa0IsT0FBTztBQUNyQixXQUFLLG1CQUFtQixPQUFPLE1BQU0sWUFBWSxLQUFLO0FBQ3RELFlBQU0sU0FBUyxLQUFLLG9CQUFvQixJQUFJLE1BQU0sVUFBVTtBQUM1RCxVQUFJLFFBQVE7QUFDUixlQUFPLDBCQUEwQixLQUFLO0FBQUEsTUFDMUM7QUFBQSxJQUNKO0FBQUEsSUFDQSxjQUFjLFFBQVE7QUFDbEIsV0FBSyxvQkFBb0IsSUFBSSxPQUFPLFlBQVksTUFBTTtBQUN0RCxZQUFNLFNBQVMsS0FBSyxtQkFBbUIsZ0JBQWdCLE9BQU8sVUFBVTtBQUN4RSxhQUFPLFFBQVEsQ0FBQyxVQUFVLE9BQU8sdUJBQXVCLEtBQUssQ0FBQztBQUFBLElBQ2xFO0FBQUEsSUFDQSxpQkFBaUIsUUFBUTtBQUNyQixXQUFLLG9CQUFvQixPQUFPLE9BQU8sVUFBVTtBQUNqRCxZQUFNLFNBQVMsS0FBSyxtQkFBbUIsZ0JBQWdCLE9BQU8sVUFBVTtBQUN4RSxhQUFPLFFBQVEsQ0FBQyxVQUFVLE9BQU8sMEJBQTBCLEtBQUssQ0FBQztBQUFBLElBQ3JFO0FBQUEsRUFDSjtBQUVBLE1BQU0sZ0JBQWdCO0FBQUEsSUFDbEIscUJBQXFCO0FBQUEsSUFDckIsaUJBQWlCO0FBQUEsSUFDakIsaUJBQWlCO0FBQUEsSUFDakIseUJBQXlCLENBQUMsZUFBZSxRQUFRLFVBQVU7QUFBQSxJQUMzRCx5QkFBeUIsQ0FBQyxZQUFZLFdBQVcsUUFBUSxVQUFVLElBQUksTUFBTTtBQUFBLElBQzdFLGFBQWEsT0FBTyxPQUFPLE9BQU8sT0FBTyxFQUFFLE9BQU8sU0FBUyxLQUFLLE9BQU8sS0FBSyxVQUFVLE9BQU8sS0FBSyxJQUFJLFdBQVcsTUFBTSxhQUFhLE1BQU0sYUFBYSxPQUFPLGNBQWMsTUFBTSxRQUFRLEtBQUssT0FBTyxTQUFTLFVBQVUsV0FBVyxXQUFXLEdBQUcsa0JBQWtCLDZCQUE2QixNQUFNLEVBQUUsRUFBRSxJQUFJLENBQUMsTUFBTSxDQUFDLEdBQUcsQ0FBQyxDQUFDLENBQUMsQ0FBQyxHQUFHLGtCQUFrQixhQUFhLE1BQU0sRUFBRSxFQUFFLElBQUksQ0FBQyxNQUFNLENBQUMsR0FBRyxDQUFDLENBQUMsQ0FBQyxDQUFDO0FBQUEsRUFDalk7QUFDQSxXQUFTLGtCQUFrQixPQUFPO0FBQzlCLFdBQU8sTUFBTSxPQUFPLENBQUMsTUFBTSxDQUFDLEdBQUcsQ0FBQyxNQUFPLE9BQU8sT0FBTyxPQUFPLE9BQU8sQ0FBQyxHQUFHLElBQUksR0FBRyxFQUFFLENBQUMsQ0FBQyxHQUFHLEVBQUUsQ0FBQyxHQUFJLENBQUMsQ0FBQztBQUFBLEVBQ2xHO0FBRUEsTUFBTSxjQUFOLE1BQWtCO0FBQUEsSUFDZCxZQUFZLFVBQVUsU0FBUyxpQkFBaUIsU0FBUyxlQUFlO0FBQ3BFLFdBQUssU0FBUztBQUNkLFdBQUssUUFBUTtBQUNiLFdBQUssbUJBQW1CLENBQUMsWUFBWSxjQUFjLFNBQVMsQ0FBQyxNQUFNO0FBQy9ELFlBQUksS0FBSyxPQUFPO0FBQ1osZUFBSyxvQkFBb0IsWUFBWSxjQUFjLE1BQU07QUFBQSxRQUM3RDtBQUFBLE1BQ0o7QUFDQSxXQUFLLFVBQVU7QUFDZixXQUFLLFNBQVM7QUFDZCxXQUFLLGFBQWEsSUFBSSxXQUFXLElBQUk7QUFDckMsV0FBSyxTQUFTLElBQUksT0FBTyxJQUFJO0FBQzdCLFdBQUssMEJBQTBCLE9BQU8sT0FBTyxDQUFDLEdBQUcsOEJBQThCO0FBQUEsSUFDbkY7QUFBQSxJQUNBLE9BQU8sTUFBTSxTQUFTLFFBQVE7QUFDMUIsWUFBTSxjQUFjLElBQUksS0FBSyxTQUFTLE1BQU07QUFDNUMsa0JBQVksTUFBTTtBQUNsQixhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsTUFBTSxRQUFRO0FBQ1YsWUFBTSxTQUFTO0FBQ2YsV0FBSyxpQkFBaUIsZUFBZSxVQUFVO0FBQy9DLFdBQUssV0FBVyxNQUFNO0FBQ3RCLFdBQUssT0FBTyxNQUFNO0FBQ2xCLFdBQUssaUJBQWlCLGVBQWUsT0FBTztBQUFBLElBQ2hEO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxpQkFBaUIsZUFBZSxVQUFVO0FBQy9DLFdBQUssV0FBVyxLQUFLO0FBQ3JCLFdBQUssT0FBTyxLQUFLO0FBQ2pCLFdBQUssaUJBQWlCLGVBQWUsTUFBTTtBQUFBLElBQy9DO0FBQUEsSUFDQSxTQUFTLFlBQVksdUJBQXVCO0FBQ3hDLFdBQUssS0FBSyxFQUFFLFlBQVksc0JBQXNCLENBQUM7QUFBQSxJQUNuRDtBQUFBLElBQ0EscUJBQXFCLE1BQU0sUUFBUTtBQUMvQixXQUFLLHdCQUF3QixJQUFJLElBQUk7QUFBQSxJQUN6QztBQUFBLElBQ0EsS0FBSyxTQUFTLE1BQU07QUFDaEIsWUFBTSxjQUFjLE1BQU0sUUFBUSxJQUFJLElBQUksT0FBTyxDQUFDLE1BQU0sR0FBRyxJQUFJO0FBQy9ELGtCQUFZLFFBQVEsQ0FBQyxlQUFlO0FBQ2hDLFlBQUksV0FBVyxzQkFBc0IsWUFBWTtBQUM3QyxlQUFLLE9BQU8sZUFBZSxVQUFVO0FBQUEsUUFDekM7QUFBQSxNQUNKLENBQUM7QUFBQSxJQUNMO0FBQUEsSUFDQSxPQUFPLFNBQVMsTUFBTTtBQUNsQixZQUFNLGNBQWMsTUFBTSxRQUFRLElBQUksSUFBSSxPQUFPLENBQUMsTUFBTSxHQUFHLElBQUk7QUFDL0Qsa0JBQVksUUFBUSxDQUFDLGVBQWUsS0FBSyxPQUFPLGlCQUFpQixVQUFVLENBQUM7QUFBQSxJQUNoRjtBQUFBLElBQ0EsSUFBSSxjQUFjO0FBQ2QsYUFBTyxLQUFLLE9BQU8sU0FBUyxJQUFJLENBQUMsWUFBWSxRQUFRLFVBQVU7QUFBQSxJQUNuRTtBQUFBLElBQ0EscUNBQXFDLFNBQVMsWUFBWTtBQUN0RCxZQUFNLFVBQVUsS0FBSyxPQUFPLGtDQUFrQyxTQUFTLFVBQVU7QUFDakYsYUFBTyxVQUFVLFFBQVEsYUFBYTtBQUFBLElBQzFDO0FBQUEsSUFDQSxZQUFZQSxRQUFPLFNBQVMsUUFBUTtBQUNoQyxVQUFJQztBQUNKLFdBQUssT0FBTyxNQUFNO0FBQUE7QUFBQTtBQUFBO0FBQUEsS0FBa0IsU0FBU0QsUUFBTyxNQUFNO0FBQzFELE9BQUNDLE1BQUssT0FBTyxhQUFhLFFBQVFBLFFBQU8sU0FBUyxTQUFTQSxJQUFHLEtBQUssUUFBUSxTQUFTLElBQUksR0FBRyxHQUFHRCxNQUFLO0FBQUEsSUFDdkc7QUFBQSxJQUNBLG9CQUFvQixZQUFZLGNBQWMsU0FBUyxDQUFDLEdBQUc7QUFDdkQsZUFBUyxPQUFPLE9BQU8sRUFBRSxhQUFhLEtBQUssR0FBRyxNQUFNO0FBQ3BELFdBQUssT0FBTyxlQUFlLEdBQUcsVUFBVSxLQUFLLFlBQVksRUFBRTtBQUMzRCxXQUFLLE9BQU8sSUFBSSxZQUFZLE9BQU8sT0FBTyxDQUFDLEdBQUcsTUFBTSxDQUFDO0FBQ3JELFdBQUssT0FBTyxTQUFTO0FBQUEsSUFDekI7QUFBQSxFQUNKO0FBQ0EsV0FBUyxXQUFXO0FBQ2hCLFdBQU8sSUFBSSxRQUFRLENBQUMsWUFBWTtBQUM1QixVQUFJLFNBQVMsY0FBYyxXQUFXO0FBQ2xDLGlCQUFTLGlCQUFpQixvQkFBb0IsTUFBTSxRQUFRLENBQUM7QUFBQSxNQUNqRSxPQUNLO0FBQ0QsZ0JBQVE7QUFBQSxNQUNaO0FBQUEsSUFDSixDQUFDO0FBQUEsRUFDTDtBQUVBLFdBQVMsd0JBQXdCLGFBQWE7QUFDMUMsVUFBTSxVQUFVLGlDQUFpQyxhQUFhLFNBQVM7QUFDdkUsV0FBTyxRQUFRLE9BQU8sQ0FBQyxZQUFZLG9CQUFvQjtBQUNuRCxhQUFPLE9BQU8sT0FBTyxZQUFZLDZCQUE2QixlQUFlLENBQUM7QUFBQSxJQUNsRixHQUFHLENBQUMsQ0FBQztBQUFBLEVBQ1Q7QUFDQSxXQUFTLDZCQUE2QixLQUFLO0FBQ3ZDLFdBQU87QUFBQSxNQUNILENBQUMsR0FBRyxHQUFHLE9BQU8sR0FBRztBQUFBLFFBQ2IsTUFBTTtBQUNGLGdCQUFNLEVBQUUsUUFBUSxJQUFJO0FBQ3BCLGNBQUksUUFBUSxJQUFJLEdBQUcsR0FBRztBQUNsQixtQkFBTyxRQUFRLElBQUksR0FBRztBQUFBLFVBQzFCLE9BQ0s7QUFDRCxrQkFBTSxZQUFZLFFBQVEsaUJBQWlCLEdBQUc7QUFDOUMsa0JBQU0sSUFBSSxNQUFNLHNCQUFzQixTQUFTLEdBQUc7QUFBQSxVQUN0RDtBQUFBLFFBQ0o7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLEdBQUcsR0FBRyxTQUFTLEdBQUc7QUFBQSxRQUNmLE1BQU07QUFDRixpQkFBTyxLQUFLLFFBQVEsT0FBTyxHQUFHO0FBQUEsUUFDbEM7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLE1BQU0sV0FBVyxHQUFHLENBQUMsT0FBTyxHQUFHO0FBQUEsUUFDNUIsTUFBTTtBQUNGLGlCQUFPLEtBQUssUUFBUSxJQUFJLEdBQUc7QUFBQSxRQUMvQjtBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLFdBQVMseUJBQXlCLGFBQWE7QUFDM0MsVUFBTSxVQUFVLGlDQUFpQyxhQUFhLFNBQVM7QUFDdkUsV0FBTyxRQUFRLE9BQU8sQ0FBQyxZQUFZLHFCQUFxQjtBQUNwRCxhQUFPLE9BQU8sT0FBTyxZQUFZLDhCQUE4QixnQkFBZ0IsQ0FBQztBQUFBLElBQ3BGLEdBQUcsQ0FBQyxDQUFDO0FBQUEsRUFDVDtBQUNBLFdBQVMsb0JBQW9CLFlBQVksU0FBUyxZQUFZO0FBQzFELFdBQU8sV0FBVyxZQUFZLHFDQUFxQyxTQUFTLFVBQVU7QUFBQSxFQUMxRjtBQUNBLFdBQVMscUNBQXFDLFlBQVksU0FBUyxZQUFZO0FBQzNFLFFBQUksbUJBQW1CLG9CQUFvQixZQUFZLFNBQVMsVUFBVTtBQUMxRSxRQUFJO0FBQ0EsYUFBTztBQUNYLGVBQVcsWUFBWSxPQUFPLDZDQUE2QyxTQUFTLFVBQVU7QUFDOUYsdUJBQW1CLG9CQUFvQixZQUFZLFNBQVMsVUFBVTtBQUN0RSxRQUFJO0FBQ0EsYUFBTztBQUFBLEVBQ2Y7QUFDQSxXQUFTLDhCQUE4QixNQUFNO0FBQ3pDLFVBQU0sZ0JBQWdCLGtCQUFrQixJQUFJO0FBQzVDLFdBQU87QUFBQSxNQUNILENBQUMsR0FBRyxhQUFhLFFBQVEsR0FBRztBQUFBLFFBQ3hCLE1BQU07QUFDRixnQkFBTSxnQkFBZ0IsS0FBSyxRQUFRLEtBQUssSUFBSTtBQUM1QyxnQkFBTSxXQUFXLEtBQUssUUFBUSx5QkFBeUIsSUFBSTtBQUMzRCxjQUFJLGVBQWU7QUFDZixrQkFBTSxtQkFBbUIscUNBQXFDLE1BQU0sZUFBZSxJQUFJO0FBQ3ZGLGdCQUFJO0FBQ0EscUJBQU87QUFDWCxrQkFBTSxJQUFJLE1BQU0sZ0VBQWdFLElBQUksbUNBQW1DLEtBQUssVUFBVSxHQUFHO0FBQUEsVUFDN0k7QUFDQSxnQkFBTSxJQUFJLE1BQU0sMkJBQTJCLElBQUksMEJBQTBCLEtBQUssVUFBVSx1RUFBdUUsUUFBUSxJQUFJO0FBQUEsUUFDL0s7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLEdBQUcsYUFBYSxTQUFTLEdBQUc7QUFBQSxRQUN6QixNQUFNO0FBQ0YsZ0JBQU0sVUFBVSxLQUFLLFFBQVEsUUFBUSxJQUFJO0FBQ3pDLGNBQUksUUFBUSxTQUFTLEdBQUc7QUFDcEIsbUJBQU8sUUFDRixJQUFJLENBQUMsa0JBQWtCO0FBQ3hCLG9CQUFNLG1CQUFtQixxQ0FBcUMsTUFBTSxlQUFlLElBQUk7QUFDdkYsa0JBQUk7QUFDQSx1QkFBTztBQUNYLHNCQUFRLEtBQUssZ0VBQWdFLElBQUksbUNBQW1DLEtBQUssVUFBVSxLQUFLLGFBQWE7QUFBQSxZQUN6SixDQUFDLEVBQ0ksT0FBTyxDQUFDLGVBQWUsVUFBVTtBQUFBLFVBQzFDO0FBQ0EsaUJBQU8sQ0FBQztBQUFBLFFBQ1o7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLEdBQUcsYUFBYSxlQUFlLEdBQUc7QUFBQSxRQUMvQixNQUFNO0FBQ0YsZ0JBQU0sZ0JBQWdCLEtBQUssUUFBUSxLQUFLLElBQUk7QUFDNUMsZ0JBQU0sV0FBVyxLQUFLLFFBQVEseUJBQXlCLElBQUk7QUFDM0QsY0FBSSxlQUFlO0FBQ2YsbUJBQU87QUFBQSxVQUNYLE9BQ0s7QUFDRCxrQkFBTSxJQUFJLE1BQU0sMkJBQTJCLElBQUksMEJBQTBCLEtBQUssVUFBVSx1RUFBdUUsUUFBUSxJQUFJO0FBQUEsVUFDL0s7QUFBQSxRQUNKO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxHQUFHLGFBQWEsZ0JBQWdCLEdBQUc7QUFBQSxRQUNoQyxNQUFNO0FBQ0YsaUJBQU8sS0FBSyxRQUFRLFFBQVEsSUFBSTtBQUFBLFFBQ3BDO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxNQUFNLFdBQVcsYUFBYSxDQUFDLFFBQVEsR0FBRztBQUFBLFFBQ3ZDLE1BQU07QUFDRixpQkFBTyxLQUFLLFFBQVEsSUFBSSxJQUFJO0FBQUEsUUFDaEM7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLEVBQ0o7QUFFQSxXQUFTLHlCQUF5QixhQUFhO0FBQzNDLFVBQU0sVUFBVSxpQ0FBaUMsYUFBYSxTQUFTO0FBQ3ZFLFdBQU8sUUFBUSxPQUFPLENBQUMsWUFBWSxxQkFBcUI7QUFDcEQsYUFBTyxPQUFPLE9BQU8sWUFBWSw4QkFBOEIsZ0JBQWdCLENBQUM7QUFBQSxJQUNwRixHQUFHLENBQUMsQ0FBQztBQUFBLEVBQ1Q7QUFDQSxXQUFTLDhCQUE4QixNQUFNO0FBQ3pDLFdBQU87QUFBQSxNQUNILENBQUMsR0FBRyxJQUFJLFFBQVEsR0FBRztBQUFBLFFBQ2YsTUFBTTtBQUNGLGdCQUFNLFNBQVMsS0FBSyxRQUFRLEtBQUssSUFBSTtBQUNyQyxjQUFJLFFBQVE7QUFDUixtQkFBTztBQUFBLFVBQ1gsT0FDSztBQUNELGtCQUFNLElBQUksTUFBTSwyQkFBMkIsSUFBSSxVQUFVLEtBQUssVUFBVSxjQUFjO0FBQUEsVUFDMUY7QUFBQSxRQUNKO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxHQUFHLElBQUksU0FBUyxHQUFHO0FBQUEsUUFDaEIsTUFBTTtBQUNGLGlCQUFPLEtBQUssUUFBUSxRQUFRLElBQUk7QUFBQSxRQUNwQztBQUFBLE1BQ0o7QUFBQSxNQUNBLENBQUMsTUFBTSxXQUFXLElBQUksQ0FBQyxRQUFRLEdBQUc7QUFBQSxRQUM5QixNQUFNO0FBQ0YsaUJBQU8sS0FBSyxRQUFRLElBQUksSUFBSTtBQUFBLFFBQ2hDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxFQUNKO0FBRUEsV0FBUyx3QkFBd0IsYUFBYTtBQUMxQyxVQUFNLHVCQUF1QixpQ0FBaUMsYUFBYSxRQUFRO0FBQ25GLFVBQU0sd0JBQXdCO0FBQUEsTUFDMUIsb0JBQW9CO0FBQUEsUUFDaEIsTUFBTTtBQUNGLGlCQUFPLHFCQUFxQixPQUFPLENBQUMsUUFBUSx3QkFBd0I7QUFDaEUsa0JBQU0sa0JBQWtCLHlCQUF5QixxQkFBcUIsS0FBSyxVQUFVO0FBQ3JGLGtCQUFNLGdCQUFnQixLQUFLLEtBQUssdUJBQXVCLGdCQUFnQixHQUFHO0FBQzFFLG1CQUFPLE9BQU8sT0FBTyxRQUFRLEVBQUUsQ0FBQyxhQUFhLEdBQUcsZ0JBQWdCLENBQUM7QUFBQSxVQUNyRSxHQUFHLENBQUMsQ0FBQztBQUFBLFFBQ1Q7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUNBLFdBQU8scUJBQXFCLE9BQU8sQ0FBQyxZQUFZLHdCQUF3QjtBQUNwRSxhQUFPLE9BQU8sT0FBTyxZQUFZLGlDQUFpQyxtQkFBbUIsQ0FBQztBQUFBLElBQzFGLEdBQUcscUJBQXFCO0FBQUEsRUFDNUI7QUFDQSxXQUFTLGlDQUFpQyxxQkFBcUIsWUFBWTtBQUN2RSxVQUFNLGFBQWEseUJBQXlCLHFCQUFxQixVQUFVO0FBQzNFLFVBQU0sRUFBRSxLQUFLLE1BQU0sUUFBUSxNQUFNLFFBQVEsTUFBTSxJQUFJO0FBQ25ELFdBQU87QUFBQSxNQUNILENBQUMsSUFBSSxHQUFHO0FBQUEsUUFDSixNQUFNO0FBQ0YsZ0JBQU0sUUFBUSxLQUFLLEtBQUssSUFBSSxHQUFHO0FBQy9CLGNBQUksVUFBVSxNQUFNO0FBQ2hCLG1CQUFPLEtBQUssS0FBSztBQUFBLFVBQ3JCLE9BQ0s7QUFDRCxtQkFBTyxXQUFXO0FBQUEsVUFDdEI7QUFBQSxRQUNKO0FBQUEsUUFDQSxJQUFJLE9BQU87QUFDUCxjQUFJLFVBQVUsUUFBVztBQUNyQixpQkFBSyxLQUFLLE9BQU8sR0FBRztBQUFBLFVBQ3hCLE9BQ0s7QUFDRCxpQkFBSyxLQUFLLElBQUksS0FBSyxNQUFNLEtBQUssQ0FBQztBQUFBLFVBQ25DO0FBQUEsUUFDSjtBQUFBLE1BQ0o7QUFBQSxNQUNBLENBQUMsTUFBTSxXQUFXLElBQUksQ0FBQyxFQUFFLEdBQUc7QUFBQSxRQUN4QixNQUFNO0FBQ0YsaUJBQU8sS0FBSyxLQUFLLElBQUksR0FBRyxLQUFLLFdBQVc7QUFBQSxRQUM1QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUNBLFdBQVMseUJBQXlCLENBQUMsT0FBTyxjQUFjLEdBQUcsWUFBWTtBQUNuRSxXQUFPLHlDQUF5QztBQUFBLE1BQzVDO0FBQUEsTUFDQTtBQUFBLE1BQ0E7QUFBQSxJQUNKLENBQUM7QUFBQSxFQUNMO0FBQ0EsV0FBUyx1QkFBdUIsVUFBVTtBQUN0QyxZQUFRLFVBQVU7QUFBQSxNQUNkLEtBQUs7QUFDRCxlQUFPO0FBQUEsTUFDWCxLQUFLO0FBQ0QsZUFBTztBQUFBLE1BQ1gsS0FBSztBQUNELGVBQU87QUFBQSxNQUNYLEtBQUs7QUFDRCxlQUFPO0FBQUEsTUFDWCxLQUFLO0FBQ0QsZUFBTztBQUFBLElBQ2Y7QUFBQSxFQUNKO0FBQ0EsV0FBUyxzQkFBc0IsY0FBYztBQUN6QyxZQUFRLE9BQU8sY0FBYztBQUFBLE1BQ3pCLEtBQUs7QUFDRCxlQUFPO0FBQUEsTUFDWCxLQUFLO0FBQ0QsZUFBTztBQUFBLE1BQ1gsS0FBSztBQUNELGVBQU87QUFBQSxJQUNmO0FBQ0EsUUFBSSxNQUFNLFFBQVEsWUFBWTtBQUMxQixhQUFPO0FBQ1gsUUFBSSxPQUFPLFVBQVUsU0FBUyxLQUFLLFlBQVksTUFBTTtBQUNqRCxhQUFPO0FBQUEsRUFDZjtBQUNBLFdBQVMscUJBQXFCLFNBQVM7QUFDbkMsVUFBTSxFQUFFLFlBQVksT0FBTyxXQUFXLElBQUk7QUFDMUMsVUFBTSxVQUFVLFlBQVksV0FBVyxJQUFJO0FBQzNDLFVBQU0sYUFBYSxZQUFZLFdBQVcsT0FBTztBQUNqRCxVQUFNLGFBQWEsV0FBVztBQUM5QixVQUFNLFdBQVcsV0FBVyxDQUFDO0FBQzdCLFVBQU0sY0FBYyxDQUFDLFdBQVc7QUFDaEMsVUFBTSxpQkFBaUIsdUJBQXVCLFdBQVcsSUFBSTtBQUM3RCxVQUFNLHVCQUF1QixzQkFBc0IsUUFBUSxXQUFXLE9BQU87QUFDN0UsUUFBSTtBQUNBLGFBQU87QUFDWCxRQUFJO0FBQ0EsYUFBTztBQUNYLFFBQUksbUJBQW1CLHNCQUFzQjtBQUN6QyxZQUFNLGVBQWUsYUFBYSxHQUFHLFVBQVUsSUFBSSxLQUFLLEtBQUs7QUFDN0QsWUFBTSxJQUFJLE1BQU0sdURBQXVELFlBQVksa0NBQWtDLGNBQWMscUNBQXFDLFdBQVcsT0FBTyxpQkFBaUIsb0JBQW9CLElBQUk7QUFBQSxJQUN2TztBQUNBLFFBQUk7QUFDQSxhQUFPO0FBQUEsRUFDZjtBQUNBLFdBQVMseUJBQXlCLFNBQVM7QUFDdkMsVUFBTSxFQUFFLFlBQVksT0FBTyxlQUFlLElBQUk7QUFDOUMsVUFBTSxhQUFhLEVBQUUsWUFBWSxPQUFPLFlBQVksZUFBZTtBQUNuRSxVQUFNLGlCQUFpQixxQkFBcUIsVUFBVTtBQUN0RCxVQUFNLHVCQUF1QixzQkFBc0IsY0FBYztBQUNqRSxVQUFNLG1CQUFtQix1QkFBdUIsY0FBYztBQUM5RCxVQUFNLE9BQU8sa0JBQWtCLHdCQUF3QjtBQUN2RCxRQUFJO0FBQ0EsYUFBTztBQUNYLFVBQU0sZUFBZSxhQUFhLEdBQUcsVUFBVSxJQUFJLGNBQWMsS0FBSztBQUN0RSxVQUFNLElBQUksTUFBTSx1QkFBdUIsWUFBWSxVQUFVLEtBQUssU0FBUztBQUFBLEVBQy9FO0FBQ0EsV0FBUywwQkFBMEIsZ0JBQWdCO0FBQy9DLFVBQU0sV0FBVyx1QkFBdUIsY0FBYztBQUN0RCxRQUFJO0FBQ0EsYUFBTyxvQkFBb0IsUUFBUTtBQUN2QyxVQUFNLGFBQWEsWUFBWSxnQkFBZ0IsU0FBUztBQUN4RCxVQUFNLFVBQVUsWUFBWSxnQkFBZ0IsTUFBTTtBQUNsRCxVQUFNLGFBQWE7QUFDbkIsUUFBSTtBQUNBLGFBQU8sV0FBVztBQUN0QixRQUFJLFNBQVM7QUFDVCxZQUFNLEVBQUUsS0FBSyxJQUFJO0FBQ2pCLFlBQU0sbUJBQW1CLHVCQUF1QixJQUFJO0FBQ3BELFVBQUk7QUFDQSxlQUFPLG9CQUFvQixnQkFBZ0I7QUFBQSxJQUNuRDtBQUNBLFdBQU87QUFBQSxFQUNYO0FBQ0EsV0FBUyx5Q0FBeUMsU0FBUztBQUN2RCxVQUFNLEVBQUUsT0FBTyxlQUFlLElBQUk7QUFDbEMsVUFBTSxNQUFNLEdBQUcsVUFBVSxLQUFLLENBQUM7QUFDL0IsVUFBTSxPQUFPLHlCQUF5QixPQUFPO0FBQzdDLFdBQU87QUFBQSxNQUNIO0FBQUEsTUFDQTtBQUFBLE1BQ0EsTUFBTSxTQUFTLEdBQUc7QUFBQSxNQUNsQixJQUFJLGVBQWU7QUFDZixlQUFPLDBCQUEwQixjQUFjO0FBQUEsTUFDbkQ7QUFBQSxNQUNBLElBQUksd0JBQXdCO0FBQ3hCLGVBQU8sc0JBQXNCLGNBQWMsTUFBTTtBQUFBLE1BQ3JEO0FBQUEsTUFDQSxRQUFRLFFBQVEsSUFBSTtBQUFBLE1BQ3BCLFFBQVEsUUFBUSxJQUFJLEtBQUssUUFBUTtBQUFBLElBQ3JDO0FBQUEsRUFDSjtBQUNBLE1BQU0sc0JBQXNCO0FBQUEsSUFDeEIsSUFBSSxRQUFRO0FBQ1IsYUFBTyxDQUFDO0FBQUEsSUFDWjtBQUFBLElBQ0EsU0FBUztBQUFBLElBQ1QsUUFBUTtBQUFBLElBQ1IsSUFBSSxTQUFTO0FBQ1QsYUFBTyxDQUFDO0FBQUEsSUFDWjtBQUFBLElBQ0EsUUFBUTtBQUFBLEVBQ1o7QUFDQSxNQUFNLFVBQVU7QUFBQSxJQUNaLE1BQU0sT0FBTztBQUNULFlBQU0sUUFBUSxLQUFLLE1BQU0sS0FBSztBQUM5QixVQUFJLENBQUMsTUFBTSxRQUFRLEtBQUssR0FBRztBQUN2QixjQUFNLElBQUksVUFBVSx5REFBeUQsS0FBSyxjQUFjLHNCQUFzQixLQUFLLENBQUMsR0FBRztBQUFBLE1BQ25JO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLFFBQVEsT0FBTztBQUNYLGFBQU8sRUFBRSxTQUFTLE9BQU8sT0FBTyxLQUFLLEVBQUUsWUFBWSxLQUFLO0FBQUEsSUFDNUQ7QUFBQSxJQUNBLE9BQU8sT0FBTztBQUNWLGFBQU8sT0FBTyxNQUFNLFFBQVEsTUFBTSxFQUFFLENBQUM7QUFBQSxJQUN6QztBQUFBLElBQ0EsT0FBTyxPQUFPO0FBQ1YsWUFBTSxTQUFTLEtBQUssTUFBTSxLQUFLO0FBQy9CLFVBQUksV0FBVyxRQUFRLE9BQU8sVUFBVSxZQUFZLE1BQU0sUUFBUSxNQUFNLEdBQUc7QUFDdkUsY0FBTSxJQUFJLFVBQVUsMERBQTBELEtBQUssY0FBYyxzQkFBc0IsTUFBTSxDQUFDLEdBQUc7QUFBQSxNQUNySTtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxPQUFPLE9BQU87QUFDVixhQUFPO0FBQUEsSUFDWDtBQUFBLEVBQ0o7QUFDQSxNQUFNLFVBQVU7QUFBQSxJQUNaLFNBQVM7QUFBQSxJQUNULE9BQU87QUFBQSxJQUNQLFFBQVE7QUFBQSxFQUNaO0FBQ0EsV0FBUyxVQUFVLE9BQU87QUFDdEIsV0FBTyxLQUFLLFVBQVUsS0FBSztBQUFBLEVBQy9CO0FBQ0EsV0FBUyxZQUFZLE9BQU87QUFDeEIsV0FBTyxHQUFHLEtBQUs7QUFBQSxFQUNuQjtBQUVBLE1BQU0sYUFBTixNQUFpQjtBQUFBLElBQ2IsWUFBWSxTQUFTO0FBQ2pCLFdBQUssVUFBVTtBQUFBLElBQ25CO0FBQUEsSUFDQSxXQUFXLGFBQWE7QUFDcEIsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLE9BQU8sVUFBVSxhQUFhLGNBQWM7QUFDeEM7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLGNBQWM7QUFDZCxhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxJQUFJLFFBQVE7QUFDUixhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLE9BQU87QUFDUCxhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxhQUFhO0FBQUEsSUFDYjtBQUFBLElBQ0EsVUFBVTtBQUFBLElBQ1Y7QUFBQSxJQUNBLGFBQWE7QUFBQSxJQUNiO0FBQUEsSUFDQSxTQUFTLFdBQVcsRUFBRSxTQUFTLEtBQUssU0FBUyxTQUFTLENBQUMsR0FBRyxTQUFTLEtBQUssWUFBWSxVQUFVLE1BQU0sYUFBYSxLQUFNLElBQUksQ0FBQyxHQUFHO0FBQzNILFlBQU0sT0FBTyxTQUFTLEdBQUcsTUFBTSxJQUFJLFNBQVMsS0FBSztBQUNqRCxZQUFNLFFBQVEsSUFBSSxZQUFZLE1BQU0sRUFBRSxRQUFRLFNBQVMsV0FBVyxDQUFDO0FBQ25FLGFBQU8sY0FBYyxLQUFLO0FBQzFCLGFBQU87QUFBQSxJQUNYO0FBQUEsRUFDSjtBQUNBLGFBQVcsWUFBWTtBQUFBLElBQ25CO0FBQUEsSUFDQTtBQUFBLElBQ0E7QUFBQSxJQUNBO0FBQUEsRUFDSjtBQUNBLGFBQVcsVUFBVSxDQUFDO0FBQ3RCLGFBQVcsVUFBVSxDQUFDO0FBQ3RCLGFBQVcsU0FBUyxDQUFDOzs7QUMvK0VyQixNQUFPLGtDQUFQLGNBQTZCLFdBQVc7QUFBQSxJQVF0QyxVQUFVO0FBekJaLFVBQUFJLEtBQUE7QUEwQkksY0FBUSxJQUFJLHFDQUFxQztBQUFBLFFBQy9DLG1CQUFtQixDQUFDLENBQUMsS0FBSztBQUFBLFFBQzFCLGdCQUFnQixLQUFLLHNCQUFzQixLQUFLLG9CQUFvQixVQUFVLEdBQUcsRUFBRSxJQUFJLFFBQVE7QUFBQSxNQUNqRyxDQUFDO0FBR0QsWUFBTSxZQUFZLEtBQUssUUFBUSxhQUFhLGlCQUFpQjtBQUM3RCxVQUFJLFdBQVc7QUFDYixnQkFBUSxJQUFJLGVBQWUsU0FBUztBQUFBLE1BQ3RDO0FBR0EsVUFBSSxDQUFDLEtBQUsscUJBQXFCO0FBQzdCLGdCQUFRLE1BQU0sdUNBQXVDO0FBQ3JELGFBQUssWUFBVSxNQUFBQSxNQUFBLE9BQU8sWUFBUCxnQkFBQUEsSUFBZ0IsU0FBaEIsbUJBQXNCLGlCQUFnQixxREFBcUQ7QUFDMUc7QUFBQSxNQUNGO0FBR0EsV0FBSyxpQkFBaUI7QUFBQSxJQUN4QjtBQUFBLElBRUEsYUFBYTtBQUVYLFVBQUksS0FBSyxnQkFBZ0I7QUFDdkIsYUFBSyxlQUFlLFFBQVE7QUFBQSxNQUM5QjtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLE1BQU0sbUJBQW1CO0FBMUQzQixVQUFBQSxLQUFBO0FBNERJLFVBQUksT0FBTyxXQUFXLGFBQWE7QUFDakMsZ0JBQVEsSUFBSSxrQ0FBa0M7QUFDOUMsY0FBTSxLQUFLLGNBQWM7QUFBQSxNQUMzQjtBQUVBLFVBQUk7QUFFRixhQUFLLFNBQVMsT0FBTyxLQUFLLG1CQUFtQjtBQUc3QyxjQUFNLGFBQWE7QUFBQSxVQUNqQixPQUFPO0FBQUEsVUFDUCxXQUFXO0FBQUEsWUFDVCxjQUFjO0FBQUEsWUFDZCxpQkFBaUI7QUFBQSxZQUNqQixXQUFXO0FBQUEsWUFDWCxZQUFZO0FBQUEsWUFDWixjQUFjO0FBQUEsVUFDaEI7QUFBQSxRQUNGO0FBRUEsYUFBSyxXQUFXLEtBQUssT0FBTyxTQUFTO0FBQUEsVUFDbkM7QUFBQSxRQUNGLENBQUM7QUFFRCxhQUFLLE9BQU8sS0FBSyxTQUFTLE9BQU8sTUFBTTtBQUN2QyxhQUFLLEtBQUssTUFBTSxlQUFlO0FBRS9CLGdCQUFRLElBQUksaURBQWlEO0FBQUEsTUFFL0QsU0FBU0MsUUFBTztBQUNkLGdCQUFRLE1BQU0sZ0NBQWdDQSxNQUFLO0FBQ25ELGFBQUssWUFBVSxNQUFBRCxNQUFBLE9BQU8sWUFBUCxnQkFBQUEsSUFBZ0IsU0FBaEIsbUJBQXNCLGdCQUFlLDZEQUE2RDtBQUFBLE1BQ25IO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxnQkFBZ0I7QUFDZCxhQUFPLElBQUksUUFBUSxDQUFDLFlBQVk7QUFDOUIsY0FBTSxjQUFjLE1BQU07QUFDeEIsY0FBSSxPQUFPLFdBQVcsYUFBYTtBQUNqQyxvQkFBUTtBQUFBLFVBQ1YsT0FBTztBQUNMLHVCQUFXLGFBQWEsR0FBRztBQUFBLFVBQzdCO0FBQUEsUUFDRjtBQUNBLG9CQUFZO0FBQUEsTUFDZCxDQUFDO0FBQUEsSUFDSDtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsY0FBYztBQUNaLFVBQUksS0FBSyxrQkFBa0I7QUFDekIsYUFBSyxjQUFjLE1BQU0sVUFBVTtBQUFBLE1BQ3JDO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxVQUFVLFNBQVM7QUFDakIsWUFBTSxXQUFXLFNBQVMsZUFBZSxnQkFBZ0I7QUFDekQsVUFBSSxZQUFZLEtBQUssdUJBQXVCO0FBQzFDLGlCQUFTLE1BQU0sVUFBVTtBQUN6QixhQUFLLG1CQUFtQixjQUFjO0FBQUEsTUFDeEM7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxZQUFZO0FBQ1YsWUFBTSxXQUFXLFNBQVMsZUFBZSxnQkFBZ0I7QUFDekQsVUFBSSxVQUFVO0FBQ1osaUJBQVMsTUFBTSxVQUFVO0FBQ3pCLFlBQUksS0FBSyx1QkFBdUI7QUFDOUIsZUFBSyxtQkFBbUIsY0FBYztBQUFBLFFBQ3hDO0FBQUEsTUFDRjtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLGNBQWM7QUFDWixVQUFJLEtBQUssa0JBQWtCO0FBQ3pCLGFBQUssY0FBYyxNQUFNLFVBQVU7QUFBQSxNQUNyQztBQUFBLElBQ0Y7QUFBQSxFQUVGO0FBMUlFLGdCQURLLGlDQUNFLFVBQVM7QUFBQSxJQUNkLGdCQUFnQjtBQUFBLElBQ2hCLGNBQWM7QUFBQSxFQUNoQjtBQUVBLGdCQU5LLGlDQU1FLFdBQVUsQ0FBQyxnQkFBZ0IsU0FBUzs7O0FDSjdDLE1BQU8sa0NBQVAsY0FBNkIsV0FBVztBQUFBO0FBQUE7QUFBQTtBQUFBLElBV3RDLFVBQVU7QUFDUixjQUFRLElBQUksbUNBQW1DO0FBQy9DLGNBQVEsSUFBSSxtQkFBbUIsS0FBSyxPQUFPO0FBQUEsSUFDN0M7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLGFBQWE7QUFDWCxjQUFRLElBQUksc0NBQXNDO0FBQUEsSUFDcEQ7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsMkJBQTJCO0FBQ3pCLFlBQU0sY0FBYyxTQUFTLGVBQWUsY0FBYztBQUMxRCxVQUFJLENBQUMsYUFBYTtBQUNoQixnQkFBUSxNQUFNLHdCQUF3QjtBQUN0QyxlQUFPO0FBQUEsTUFDVDtBQUVBLFlBQU0sYUFBYSxLQUFLLFlBQVk7QUFBQSxRQUNsQztBQUFBLFFBQ0E7QUFBQSxNQUNGO0FBRUEsVUFBSSxDQUFDLFlBQVk7QUFDZixnQkFBUSxNQUFNLG1EQUFtRDtBQUNqRSxlQUFPO0FBQUEsTUFDVDtBQUVBLGNBQVEsSUFBSSxrQ0FBa0MsVUFBVTtBQUN4RCxhQUFPO0FBQUEsSUFDVDtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLE1BQU0sYUFBYSxPQUFPO0FBeEU1QixVQUFBRSxLQUFBO0FBeUVJLFlBQU0sZUFBZTtBQUVyQixjQUFRLElBQUksK0JBQStCO0FBQUEsUUFDekMsVUFBVSxLQUFLLFFBQVE7QUFBQSxRQUN2QixhQUFhLEtBQUs7QUFBQSxRQUNsQixZQUFXLG9CQUFJLEtBQUssR0FBRSxZQUFZO0FBQUEsTUFDcEMsQ0FBQztBQUVELFdBQUssWUFBWTtBQUVqQixVQUFJO0FBRUYsWUFBSSxLQUFLLHFCQUFxQixVQUFVO0FBQ3RDLGdCQUFNLEtBQUsscUJBQXFCO0FBQUEsUUFDbEMsT0FBTztBQUNMLGdCQUFNLEtBQUssb0JBQW9CO0FBQUEsUUFDakM7QUFBQSxNQUNGLFNBQVNDLFFBQU87QUFDZCxnQkFBUSxNQUFNLDJCQUEyQkEsTUFBSztBQUM5QyxhQUFLLFVBQVVBLE9BQU0sYUFBVyxNQUFBRCxNQUFBLE9BQU8sWUFBUCxnQkFBQUEsSUFBZ0IsU0FBaEIsbUJBQXNCLG1CQUFrQiwyQkFBMkI7QUFBQSxNQUNyRyxVQUFFO0FBQ0EsYUFBSyxZQUFZO0FBQUEsTUFDbkI7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU1BLE1BQU0sdUJBQXVCO0FBdEcvQixVQUFBQSxLQUFBO0FBdUdJLFVBQUksQ0FBQyxPQUFPLFFBQVE7QUFDbEIsY0FBTSxJQUFJLFFBQU0sTUFBQUEsTUFBQSxPQUFPLFlBQVAsZ0JBQUFBLElBQWdCLFNBQWhCLG1CQUFzQixrQkFBaUIsc0JBQXNCO0FBQUEsTUFDL0U7QUFHQSxVQUFJLENBQUMsS0FBSywwQkFBMEIsQ0FBQyxLQUFLLHFCQUFxQjtBQUM3RCxjQUFNLElBQUksUUFBTSxrQkFBTyxZQUFQLG1CQUFnQixTQUFoQixtQkFBc0IsdUJBQXNCLHVDQUF1QztBQUFBLE1BQ3JHO0FBRUEsWUFBTSxTQUFTLE9BQU8sS0FBSyxtQkFBbUI7QUFFOUMsV0FBSyxZQUFVLGtCQUFPLFlBQVAsbUJBQWdCLFNBQWhCLG1CQUFzQixxQkFBb0IsOEJBQThCO0FBR3ZGLFlBQU0sV0FBVyxNQUFNLE1BQU0sS0FBSyxVQUFVO0FBQUEsUUFDMUMsUUFBUTtBQUFBLFFBQ1IsU0FBUztBQUFBLFVBQ1AsZ0JBQWdCO0FBQUEsUUFDbEI7QUFBQSxRQUNBLE1BQU0sS0FBSyxVQUFVO0FBQUEsVUFDbkIsU0FBUztBQUFBO0FBQUEsUUFDWCxDQUFDO0FBQUEsUUFDRCxhQUFhO0FBQUEsTUFDZixDQUFDO0FBRUQsVUFBSSxDQUFDLFNBQVMsSUFBSTtBQUNoQixjQUFNLFlBQVksTUFBTSxTQUFTLEtBQUssRUFBRSxNQUFNLE9BQU8sQ0FBQyxFQUFFO0FBQ3hELGNBQU0sSUFBSSxNQUFNLFVBQVUsV0FBUyxrQkFBTyxZQUFQLG1CQUFnQixTQUFoQixtQkFBc0IsbUJBQWtCLG1DQUFtQztBQUFBLE1BQ2hIO0FBRUEsWUFBTSxPQUFPLE1BQU0sU0FBUyxLQUFLO0FBRWpDLFVBQUksQ0FBQyxLQUFLLElBQUk7QUFDWixjQUFNLElBQUksUUFBTSxrQkFBTyxZQUFQLG1CQUFnQixTQUFoQixtQkFBc0Isb0JBQW1CLG1DQUFtQztBQUFBLE1BQzlGO0FBRUEsY0FBUSxJQUFJLDZCQUE2QixLQUFLLElBQUksUUFBUSxLQUFLLEdBQUc7QUFDbEUsY0FBUSxJQUFJLGVBQWUsS0FBSyxNQUFNO0FBR3RDLFVBQUksS0FBSyxLQUFLO0FBQ1osZUFBTyxTQUFTLE9BQU8sS0FBSztBQUM1QjtBQUFBLE1BQ0Y7QUFHQSxZQUFNLEVBQUUsT0FBQUMsT0FBTSxJQUFJLE1BQU0sT0FBTyxtQkFBbUI7QUFBQSxRQUNoRCxXQUFXLEtBQUs7QUFBQSxNQUNsQixDQUFDO0FBRUQsVUFBSUEsUUFBTztBQUNULGNBQU1BO0FBQUEsTUFDUjtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsTUFBTSxzQkFBc0I7QUFsSzlCLFVBQUFELEtBQUE7QUFvS0ksWUFBTSx3QkFBd0IsS0FBSyx5QkFBeUI7QUFFNUQsVUFBSSxDQUFDLHVCQUF1QjtBQUMxQixjQUFNLElBQUksUUFBTSxNQUFBQSxNQUFBLE9BQU8sWUFBUCxnQkFBQUEsSUFBZ0IsU0FBaEIsbUJBQXNCLHlCQUF3QiwrREFBK0Q7QUFBQSxNQUMvSDtBQUdBLFVBQUksQ0FBQyxzQkFBc0IsUUFBUSxDQUFDLHNCQUFzQixRQUFRO0FBQ2hFLGdCQUFRLE1BQU0sMkJBQTJCO0FBQUEsVUFDdkMsU0FBUyxDQUFDLENBQUMsc0JBQXNCO0FBQUEsVUFDakMsV0FBVyxDQUFDLENBQUMsc0JBQXNCO0FBQUEsUUFDckMsQ0FBQztBQUNELGNBQU0sSUFBSSxRQUFNLGtCQUFPLFlBQVAsbUJBQWdCLFNBQWhCLG1CQUFzQixtQkFBa0Isd0RBQXdEO0FBQUEsTUFDbEg7QUFFQSxjQUFRLElBQUksNEJBQTRCO0FBQUEsUUFDdEMsU0FBUyxDQUFDLENBQUMsc0JBQXNCO0FBQUEsUUFDakMsV0FBVyxDQUFDLENBQUMsc0JBQXNCO0FBQUEsTUFDckMsQ0FBQztBQUVELFlBQU0sd0JBQXdCLE1BQU0sS0FBSyxjQUFjO0FBQ3ZELFlBQU0sZUFBZSxzQkFBc0I7QUFFM0MsWUFBTSx5QkFBeUIsTUFBTSxzQkFBc0IsT0FBTyxtQkFBbUIsY0FBYztBQUFBLFFBQ2pHLGdCQUFnQjtBQUFBLFVBQ2QsTUFBTSxzQkFBc0I7QUFBQSxRQUM5QjtBQUFBLE1BQ0YsQ0FBQztBQUVELFVBQUksdUJBQXVCLE9BQU87QUFDaEMsY0FBTSxJQUFJLE1BQU0sdUJBQXVCLE1BQU0sT0FBTztBQUFBLE1BQ3RELFdBQVcsdUJBQXVCLGlCQUFpQix1QkFBdUIsY0FBYyxXQUFXLGFBQWE7QUFDOUcsZ0JBQVEsSUFBSSxxQkFBcUIsdUJBQXVCLGFBQWE7QUFBQSxNQUV2RSxPQUFPO0FBQ0wsY0FBTSxJQUFJLFFBQU0sa0JBQU8sWUFBUCxtQkFBZ0IsU0FBaEIsbUJBQXNCLDBCQUF5Qix1QkFBdUI7QUFBQSxNQUN4RjtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFPQSxNQUFNLGdCQUFnQjtBQWhOeEIsVUFBQUEsS0FBQTtBQWlOSSxVQUFJLENBQUMsS0FBSyxhQUFhO0FBQ3JCLGNBQU0sSUFBSSxRQUFNLE1BQUFBLE1BQUEsT0FBTyxZQUFQLGdCQUFBQSxJQUFnQixTQUFoQixtQkFBc0IsdUJBQXNCLCtCQUErQjtBQUFBLE1BQzdGO0FBRUEsY0FBUSxJQUFJLG9DQUFvQyxLQUFLLFFBQVE7QUFFN0QsWUFBTSxXQUFXLE1BQU0sTUFBTSxLQUFLLFVBQVU7QUFBQSxRQUMxQyxRQUFRO0FBQUEsUUFDUixTQUFTO0FBQUEsVUFDUCxnQkFBZ0I7QUFBQSxRQUNsQjtBQUFBLFFBQ0EsYUFBYTtBQUFBLE1BQ2YsQ0FBQztBQUVELFVBQUksQ0FBQyxTQUFTLElBQUk7QUFDaEIsY0FBTSxJQUFJLE1BQU0sdUJBQXVCLFNBQVMsTUFBTSxFQUFFO0FBQUEsTUFDMUQ7QUFFQSxZQUFNLGVBQWUsTUFBTSxTQUFTLEtBQUs7QUFFekMsVUFBSSxhQUFhLE9BQU87QUFDdEIsY0FBTSxJQUFJLE1BQU0sYUFBYSxLQUFLO0FBQUEsTUFDcEM7QUFFQSxVQUFJLENBQUMsYUFBYSxXQUFXLENBQUMsYUFBYSxjQUFjO0FBQ3ZELGNBQU0sSUFBSSxRQUFNLGtCQUFPLFlBQVAsbUJBQWdCLFNBQWhCLG1CQUFzQixtQkFBa0IsaUNBQWlDO0FBQUEsTUFDM0Y7QUFFQSxhQUFPO0FBQUEsSUFDVDtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsY0FBYztBQW5QaEIsVUFBQUEsS0FBQTtBQW9QSSxXQUFLLFFBQVEsV0FBVztBQUN4QixXQUFLLGVBQWUsS0FBSyxRQUFRO0FBQ2pDLFdBQUssUUFBUSxnQkFBYyxNQUFBQSxNQUFBLE9BQU8sWUFBUCxnQkFBQUEsSUFBZ0IsU0FBaEIsbUJBQXNCLGVBQWM7QUFBQSxJQUNqRTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsY0FBYztBQUNaLFdBQUssUUFBUSxXQUFXO0FBQ3hCLFVBQUksS0FBSyxjQUFjO0FBQ3JCLGFBQUssUUFBUSxjQUFjLEtBQUs7QUFBQSxNQUNsQztBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsVUFBVSxTQUFTO0FBQ2pCLFVBQUksS0FBSyxpQkFBaUI7QUFDeEIsYUFBSyxhQUFhLGNBQWM7QUFDaEMsYUFBSyxhQUFhLFlBQVk7QUFBQSxNQUNoQztBQUNBLGNBQVEsSUFBSSxXQUFXLE9BQU87QUFBQSxJQUNoQztBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxVQUFVLFNBQVM7QUFDakIsVUFBSSxLQUFLLGlCQUFpQjtBQUN4QixhQUFLLGFBQWEsY0FBYztBQUNoQyxhQUFLLGFBQWEsWUFBWTtBQUFBLE1BQ2hDLE9BQU87QUFDTCxjQUFNLFlBQVksT0FBTztBQUFBLE1BQzNCO0FBQUEsSUFDRjtBQUFBLEVBQ0Y7QUF2UUUsZ0JBREssaUNBQ0UsV0FBVSxDQUFDLFFBQVE7QUFDMUIsZ0JBRkssaUNBRUUsVUFBUztBQUFBLElBQ2QsS0FBSztBQUFBLElBQ0wsYUFBYTtBQUFBLElBQ2IsZ0JBQWdCO0FBQUEsRUFDbEI7OztBQ1hGLE1BQU8sb0NBQVAsY0FBNkIsV0FBVztBQUFBO0FBQUE7QUFBQTtBQUFBLElBU3RDLFVBQVU7QUFDUixjQUFRLElBQUksdUNBQXVDO0FBQUEsUUFDakQsU0FBUyxLQUFLO0FBQUEsUUFDZCxhQUFhLEtBQUs7QUFBQSxRQUNsQixrQkFBa0IsS0FBSztBQUFBLE1BQ3pCLENBQUM7QUFHRCxVQUFJLEtBQUssY0FBYztBQUNyQixhQUFLLG1CQUFtQjtBQUFBLE1BQzFCO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0Esa0JBQWtCO0FBQ2hCLFVBQUksS0FBSyxjQUFjO0FBQ3JCLGFBQUssbUJBQW1CO0FBQUEsTUFDMUI7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxxQkFBcUI7QUFDbkIsVUFBSSxDQUFDLEtBQUsscUJBQXFCLENBQUMsS0FBSyx1QkFBdUI7QUFDMUQ7QUFBQSxNQUNGO0FBRUEsWUFBTSxZQUFZLEtBQUssZUFBZTtBQUd0QyxXQUFLLG9CQUFvQixRQUFRLFlBQVU7QUF4RC9DLFlBQUFFLEtBQUE7QUF5RE0sZUFBTyxXQUFXLENBQUM7QUFHbkIsWUFBSSxXQUFXO0FBQ2IsaUJBQU8sVUFBVSxPQUFPLFVBQVU7QUFDbEMsaUJBQU8sZ0JBQWdCLE9BQU87QUFBQSxRQUNoQyxPQUFPO0FBQ0wsaUJBQU8sVUFBVSxJQUFJLFVBQVU7QUFDL0IsaUJBQU8sYUFBYSxXQUFTLE1BQUFBLE1BQUEsT0FBTyxZQUFQLGdCQUFBQSxJQUFnQixTQUFoQixtQkFBc0IsaUJBQWdCLHdDQUF3QztBQUFBLFFBQzdHO0FBQUEsTUFDRixDQUFDO0FBQUEsSUFDSDtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxhQUFhLE9BQU87QUFDbEIsVUFBSSxDQUFDLEtBQUssY0FBYztBQUN0QixlQUFPO0FBQUEsTUFDVDtBQUVBLFVBQUksQ0FBQyxLQUFLLHFCQUFxQixDQUFDLEtBQUssZUFBZSxTQUFTO0FBQzNELGNBQU0sZUFBZTtBQUNyQixjQUFNLGdCQUFnQjtBQUd0QixZQUFJLEtBQUssbUJBQW1CO0FBQzFCLGdCQUFNLGtCQUFrQixLQUFLLGVBQWUsUUFBUSxhQUFhO0FBQ2pFLGNBQUksaUJBQWlCO0FBQ25CLDRCQUFnQixVQUFVLElBQUksVUFBVSxpQkFBaUIsT0FBTyxTQUFTO0FBR3pFLHVCQUFXLE1BQU07QUFDZiw4QkFBZ0IsVUFBVSxPQUFPLFVBQVUsaUJBQWlCLE9BQU8sU0FBUztBQUFBLFlBQzlFLEdBQUcsR0FBSTtBQUFBLFVBQ1Q7QUFBQSxRQUNGO0FBRUEsZUFBTztBQUFBLE1BQ1Q7QUFFQSxhQUFPO0FBQUEsSUFDVDtBQUFBLEVBQ0Y7QUF0RkUsZ0JBREssbUNBQ0UsV0FBVSxDQUFDLFlBQVksY0FBYztBQUM1QyxnQkFGSyxtQ0FFRSxVQUFTO0FBQUEsSUFDZCxTQUFTO0FBQUEsRUFDWDs7O0FDbUJGLE1BQU0sV0FBTixNQUFNLFVBQVM7QUFBQSxJQUNiLGNBQWM7QUFFWixVQUFJLFVBQVMsVUFBVTtBQUNyQixlQUFPLFVBQVM7QUFBQSxNQUNsQjtBQUVBLFdBQUssWUFBWSxvQkFBSSxJQUFJO0FBQ3pCLFdBQUssUUFBUTtBQUNiLFdBQUssZUFBZSxDQUFDO0FBQ3JCLFdBQUssaUJBQWlCO0FBRXRCLGdCQUFTLFdBQVc7QUFBQSxJQUN0QjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsU0FBUyxTQUFTO0FBQ2hCLFdBQUssUUFBUTtBQUFBLElBQ2Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQVFBLEdBQUcsV0FBVyxTQUFTO0FBQ3JCLFVBQUksQ0FBQyxLQUFLLFVBQVUsSUFBSSxTQUFTLEdBQUc7QUFDbEMsYUFBSyxVQUFVLElBQUksV0FBVyxvQkFBSSxJQUFJLENBQUM7QUFBQSxNQUN6QztBQUVBLFdBQUssVUFBVSxJQUFJLFNBQVMsRUFBRSxJQUFJLE9BQU87QUFFekMsVUFBSSxLQUFLLE9BQU87QUFDZCxnQkFBUSxJQUFJLHVDQUF1QyxTQUFTLEtBQUs7QUFBQSxVQUMvRCxnQkFBZ0IsS0FBSyxVQUFVLElBQUksU0FBUyxFQUFFO0FBQUEsUUFDaEQsQ0FBQztBQUFBLE1BQ0g7QUFHQSxhQUFPLE1BQU0sS0FBSyxJQUFJLFdBQVcsT0FBTztBQUFBLElBQzFDO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFRQSxLQUFLLFdBQVcsU0FBUztBQUN2QixZQUFNLGNBQWMsQ0FBQyxTQUFTO0FBQzVCLGdCQUFRLElBQUk7QUFDWixhQUFLLElBQUksV0FBVyxXQUFXO0FBQUEsTUFDakM7QUFFQSxhQUFPLEtBQUssR0FBRyxXQUFXLFdBQVc7QUFBQSxJQUN2QztBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLElBQUksV0FBVyxTQUFTO0FBQ3RCLFVBQUksQ0FBQyxLQUFLLFVBQVUsSUFBSSxTQUFTLEdBQUc7QUFDbEM7QUFBQSxNQUNGO0FBRUEsWUFBTSxXQUFXLEtBQUssVUFBVSxJQUFJLFNBQVM7QUFDN0MsZUFBUyxPQUFPLE9BQU87QUFHdkIsVUFBSSxTQUFTLFNBQVMsR0FBRztBQUN2QixhQUFLLFVBQVUsT0FBTyxTQUFTO0FBQUEsTUFDakM7QUFFQSxVQUFJLEtBQUssT0FBTztBQUNkLGdCQUFRLElBQUksb0NBQW9DLFNBQVMsS0FBSztBQUFBLFVBQzVELGdCQUFnQixTQUFTO0FBQUEsUUFDM0IsQ0FBQztBQUFBLE1BQ0g7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU1BLE9BQU8sV0FBVztBQUNoQixVQUFJLEtBQUssVUFBVSxJQUFJLFNBQVMsR0FBRztBQUNqQyxhQUFLLFVBQVUsT0FBTyxTQUFTO0FBRS9CLFlBQUksS0FBSyxPQUFPO0FBQ2Qsa0JBQVEsSUFBSSx5Q0FBeUMsU0FBUyxHQUFHO0FBQUEsUUFDbkU7QUFBQSxNQUNGO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLEtBQUssV0FBVyxPQUFPLENBQUMsR0FBRztBQUN6QixZQUFNLFlBQVksS0FBSyxJQUFJO0FBRzNCLFdBQUssYUFBYSxLQUFLO0FBQUEsUUFDckI7QUFBQSxRQUNBO0FBQUEsUUFDQTtBQUFBLFFBQ0EsZ0JBQWdCLEtBQUssVUFBVSxJQUFJLFNBQVMsSUFBSSxLQUFLLFVBQVUsSUFBSSxTQUFTLEVBQUUsT0FBTztBQUFBLE1BQ3ZGLENBQUM7QUFHRCxVQUFJLEtBQUssYUFBYSxTQUFTLEtBQUssZ0JBQWdCO0FBQ2xELGFBQUssYUFBYSxNQUFNO0FBQUEsTUFDMUI7QUFFQSxVQUFJLEtBQUssT0FBTztBQUNkLGdCQUFRLElBQUksOEJBQThCLFNBQVMsS0FBSztBQUFBLFVBQ3REO0FBQUEsVUFDQSxnQkFBZ0IsS0FBSyxVQUFVLElBQUksU0FBUyxJQUFJLEtBQUssVUFBVSxJQUFJLFNBQVMsRUFBRSxPQUFPO0FBQUEsVUFDckYsV0FBVyxJQUFJLEtBQUssU0FBUyxFQUFFLFlBQVk7QUFBQSxRQUM3QyxDQUFDO0FBQUEsTUFDSDtBQUdBLFVBQUksS0FBSyxVQUFVLElBQUksU0FBUyxHQUFHO0FBQ2pDLGNBQU0sV0FBVyxNQUFNLEtBQUssS0FBSyxVQUFVLElBQUksU0FBUyxDQUFDO0FBRXpELGlCQUFTLFFBQVEsYUFBVztBQUMxQixjQUFJO0FBQ0Ysb0JBQVEsSUFBSTtBQUFBLFVBQ2QsU0FBU0MsUUFBTztBQUNkLG9CQUFRLE1BQU0sb0NBQW9DLFNBQVMsTUFBTUEsTUFBSztBQUFBLFVBRXhFO0FBQUEsUUFDRixDQUFDO0FBQUEsTUFDSCxXQUFXLEtBQUssT0FBTztBQUNyQixnQkFBUSxLQUFLLGdDQUFnQyxTQUFTLEdBQUc7QUFBQSxNQUMzRDtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBU0EsTUFBTSxVQUFVLFdBQVcsT0FBTyxDQUFDLEdBQUc7QUFDcEMsYUFBTyxJQUFJLFFBQVEsQ0FBQyxZQUFZO0FBQzlCLG1CQUFXLE1BQU07QUFDZixlQUFLLEtBQUssV0FBVyxJQUFJO0FBQ3pCLGtCQUFRO0FBQUEsUUFDVixHQUFHLENBQUM7QUFBQSxNQUNOLENBQUM7QUFBQSxJQUNIO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQVNBLFlBQVksV0FBVyxPQUFPLENBQUMsR0FBRyxRQUFRLEdBQUc7QUFDM0MsYUFBTyxXQUFXLE1BQU07QUFDdEIsYUFBSyxLQUFLLFdBQVcsSUFBSTtBQUFBLE1BQzNCLEdBQUcsS0FBSztBQUFBLElBQ1Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBU0EsUUFBUSxXQUFXLFVBQVUsS0FBTTtBQUNqQyxhQUFPLElBQUksUUFBUSxDQUFDLFNBQVMsV0FBVztBQUN0QyxjQUFNLFFBQVEsVUFBVSxJQUFJLFdBQVcsTUFBTTtBQUMzQyxlQUFLLElBQUksV0FBVyxPQUFPO0FBQzNCLGlCQUFPLElBQUksTUFBTSx5Q0FBeUMsU0FBUyxHQUFHLENBQUM7QUFBQSxRQUN6RSxHQUFHLE9BQU8sSUFBSTtBQUVkLGNBQU0sVUFBVSxDQUFDLFNBQVM7QUFDeEIsY0FBSTtBQUFPLHlCQUFhLEtBQUs7QUFDN0Isa0JBQVEsSUFBSTtBQUFBLFFBQ2Q7QUFFQSxhQUFLLEtBQUssV0FBVyxPQUFPO0FBQUEsTUFDOUIsQ0FBQztBQUFBLElBQ0g7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFPQSxhQUFhLFdBQVc7QUFDdEIsYUFBTyxLQUFLLFVBQVUsSUFBSSxTQUFTLEtBQUssS0FBSyxVQUFVLElBQUksU0FBUyxFQUFFLE9BQU87QUFBQSxJQUMvRTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLGtCQUFrQixXQUFXO0FBQzNCLGFBQU8sS0FBSyxVQUFVLElBQUksU0FBUyxJQUFJLEtBQUssVUFBVSxJQUFJLFNBQVMsRUFBRSxPQUFPO0FBQUEsSUFDOUU7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsc0JBQXNCO0FBQ3BCLGFBQU8sTUFBTSxLQUFLLEtBQUssVUFBVSxLQUFLLENBQUM7QUFBQSxJQUN6QztBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLGdCQUFnQixRQUFRLElBQUk7QUFDMUIsYUFBTyxLQUFLLGFBQWEsTUFBTSxDQUFDLEtBQUs7QUFBQSxJQUN2QztBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsZUFBZTtBQUNiLFdBQUssZUFBZSxDQUFDO0FBQUEsSUFDdkI7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLFdBQVc7QUFDVCxXQUFLLFVBQVUsTUFBTTtBQUVyQixVQUFJLEtBQUssT0FBTztBQUNkLGdCQUFRLElBQUksa0NBQWtDO0FBQUEsTUFDaEQ7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxhQUFhO0FBQ1gsY0FBUSxNQUFNLHVCQUF1QjtBQUNyQyxjQUFRLElBQUksc0JBQXNCLEtBQUssb0JBQW9CLENBQUM7QUFDNUQsY0FBUSxJQUFJLG9CQUFvQixNQUFNLEtBQUssS0FBSyxVQUFVLE9BQU8sQ0FBQyxFQUFFLE9BQU8sQ0FBQyxLQUFLLFFBQVEsTUFBTSxJQUFJLE1BQU0sQ0FBQyxDQUFDO0FBQzNHLGNBQVEsSUFBSSx1QkFBdUIsS0FBSyxhQUFhLE1BQU07QUFFM0QsY0FBUSxNQUFNLHNCQUFzQjtBQUNwQyxXQUFLLFVBQVUsUUFBUSxDQUFDLFVBQVUsY0FBYztBQUM5QyxnQkFBUSxJQUFJLEtBQUssU0FBUyxLQUFLLFNBQVMsSUFBSSxFQUFFO0FBQUEsTUFDaEQsQ0FBQztBQUNELGNBQVEsU0FBUztBQUVqQixjQUFRLE1BQU0sZ0JBQWdCO0FBQzlCLFdBQUssZ0JBQWdCLEVBQUUsRUFBRSxRQUFRLFdBQVM7QUFDeEMsZ0JBQVEsSUFBSSxLQUFLLE1BQU0sU0FBUyxLQUFLLE1BQU0sY0FBYyxpQkFBaUIsSUFBSSxLQUFLLE1BQU0sU0FBUyxFQUFFLG1CQUFtQixDQUFDLEVBQUU7QUFBQSxNQUM1SCxDQUFDO0FBQ0QsY0FBUSxTQUFTO0FBRWpCLGNBQVEsU0FBUztBQUFBLElBQ25CO0FBQUEsRUFDRjtBQUdPLE1BQU0sV0FBVyxJQUFJLFNBQVM7QUF6VHJDO0FBNFRBLE1BQUksT0FBTyxXQUFXLGlCQUFlLFlBQU8sYUFBUCxtQkFBaUIsY0FBYSxhQUFhO0FBQzlFLGFBQVMsU0FBUyxJQUFJO0FBQUEsRUFDeEI7QUFHQSxNQUFJLE9BQU8sV0FBVyxhQUFhO0FBQ2pDLFdBQU8sV0FBVztBQUFBLEVBQ3BCOzs7QUN6Uk8sV0FBUyxhQUFhLGdCQUFnQjtBQUMzQyxXQUFPLGNBQWMsZUFBZTtBQUFBLE1BQ2xDLGVBQWUsTUFBTTtBQUNuQixjQUFNLEdBQUcsSUFBSTtBQUdiLGFBQUsscUJBQXFCLENBQUM7QUFBQSxNQUM3QjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsTUFZQSxPQUFPLFdBQVcsU0FBUyxVQUFVLENBQUMsR0FBRztBQUN2QyxjQUFNLEVBQUUsT0FBTyxNQUFNLElBQUk7QUFHekIsY0FBTSxlQUFlLFFBQVEsS0FBSyxJQUFJO0FBR3RDLGNBQU0saUJBQWlCLEtBQUssY0FBYyxLQUFLLFlBQVk7QUFDM0QsY0FBTSxlQUFlLENBQUMsU0FBUztBQUM3QixjQUFJLFNBQVMsT0FBTztBQUNsQixvQkFBUSxJQUFJLElBQUksY0FBYyxxQkFBcUIsU0FBUyxLQUFLLElBQUk7QUFBQSxVQUN2RTtBQUNBLHVCQUFhLElBQUk7QUFBQSxRQUNuQjtBQUdBLGNBQU0saUJBQWlCLE9BQ25CLFNBQVMsS0FBSyxXQUFXLFlBQVksSUFDckMsU0FBUyxHQUFHLFdBQVcsWUFBWTtBQUd2QyxhQUFLLG1CQUFtQixLQUFLLEVBQUUsV0FBVyxTQUFTLGNBQWMsZUFBZSxDQUFDO0FBR2pGLGVBQU87QUFBQSxNQUNUO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLE1BVUEsV0FBVyxXQUFXLFNBQVM7QUFDN0IsZUFBTyxLQUFLLE9BQU8sV0FBVyxTQUFTLEVBQUUsTUFBTSxLQUFLLENBQUM7QUFBQSxNQUN2RDtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLE1BUUEsVUFBVSxXQUFXLE9BQU8sQ0FBQyxHQUFHO0FBQzlCLGNBQU0saUJBQWlCLEtBQUssY0FBYyxLQUFLLFlBQVk7QUFFM0QsWUFBSSxTQUFTLE9BQU87QUFDbEIsa0JBQVEsSUFBSSxJQUFJLGNBQWMseUJBQXlCLFNBQVMsS0FBSyxJQUFJO0FBQUEsUUFDM0U7QUFFQSxpQkFBUyxLQUFLLFdBQVcsSUFBSTtBQUFBLE1BQy9CO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxNQVNBLE1BQU0sZUFBZSxXQUFXLE9BQU8sQ0FBQyxHQUFHO0FBQ3pDLGVBQU8sU0FBUyxVQUFVLFdBQVcsSUFBSTtBQUFBLE1BQzNDO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLE1BVUEsTUFBTSxhQUFhLFdBQVcsVUFBVSxLQUFNO0FBQzVDLGVBQU8sU0FBUyxRQUFRLFdBQVcsT0FBTztBQUFBLE1BQzVDO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsTUFRQSxjQUFjLFdBQVcsU0FBUztBQUNoQyxpQkFBUyxJQUFJLFdBQVcsT0FBTztBQUcvQixhQUFLLHFCQUFxQixLQUFLLG1CQUFtQjtBQUFBLFVBQ2hELGNBQVksRUFBRSxTQUFTLGNBQWMsYUFBYSxTQUFTLFlBQVk7QUFBQSxRQUN6RTtBQUFBLE1BQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLE1BTUEsbUJBQW1CO0FBQ2pCLGFBQUssbUJBQW1CLFFBQVEsQ0FBQyxFQUFFLGVBQWUsTUFBTTtBQUN0RCx5QkFBZTtBQUFBLFFBQ2pCLENBQUM7QUFFRCxhQUFLLHFCQUFxQixDQUFDO0FBRTNCLFlBQUksU0FBUyxPQUFPO0FBQ2xCLGdCQUFNLGlCQUFpQixLQUFLLGNBQWMsS0FBSyxZQUFZO0FBQzNELGtCQUFRLElBQUksSUFBSSxjQUFjLGtDQUFrQztBQUFBLFFBQ2xFO0FBQUEsTUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBLE1BS0EsYUFBYTtBQUNYLGFBQUssaUJBQWlCO0FBR3RCLFlBQUksTUFBTSxZQUFZO0FBQ3BCLGdCQUFNLFdBQVc7QUFBQSxRQUNuQjtBQUFBLE1BQ0Y7QUFBQSxJQUNGO0FBQUEsRUFDRjs7O0FDbEtBLE1BQU8sb0NBQVAsY0FBNkIsYUFBYSxVQUFVLEVBQUU7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQVlwRCxVQUFVO0FBQ1IsY0FBUSxJQUFJLHFDQUFxQztBQUdqRCxXQUFLLE9BQU8sOEJBQThCLEtBQUsscUJBQXFCLEtBQUssSUFBSSxDQUFDO0FBQzlFLFdBQUssT0FBTyxnQ0FBZ0MsS0FBSyxxQkFBcUIsS0FBSyxJQUFJLENBQUM7QUFHaEYsV0FBSyxTQUFTO0FBQ2QsV0FBSyxXQUFXO0FBQ2hCLFdBQUssaUJBQWlCO0FBQ3RCLFdBQUssb0JBQW9CO0FBQ3pCLFdBQUssaUJBQWlCO0FBQUEsSUFDeEI7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLGFBQWE7QUFDWCxjQUFRLElBQUksd0NBQXdDO0FBS3BELFVBQUksS0FBSyxnQkFBZ0I7QUFDdkIsYUFBSyxlQUFlLFFBQVE7QUFDNUIsYUFBSyxpQkFBaUI7QUFBQSxNQUN4QjtBQUNBLFdBQUssV0FBVztBQUNoQixXQUFLLFNBQVM7QUFBQSxJQUNoQjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQWdCQSxNQUFNLHFCQUFxQixPQUFPO0FBQ2hDLFlBQU0sRUFBRSxnQkFBZ0IsSUFBSSxNQUFNO0FBRWxDLGNBQVEsSUFBSSxzREFBc0QsZUFBZTtBQUVqRixVQUFJLENBQUMsS0FBSyxlQUFlLGVBQWUsR0FBRztBQUN6QyxhQUFLLGFBQWE7QUFDbEI7QUFBQSxNQUNGO0FBR0EsV0FBSyxhQUFhO0FBR2xCLFVBQUksQ0FBQyxLQUFLLFFBQVE7QUFDaEIsY0FBTSxLQUFLLGNBQWM7QUFBQSxNQUMzQjtBQUdBLFlBQU0sS0FBSyx5QkFBeUI7QUFBQSxJQUN0QztBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFrQkEsTUFBTSxxQkFBcUIsT0FBTztBQUNoQyxZQUFNLEVBQUUsaUJBQWlCLGNBQWMsWUFBWSxRQUFRLElBQUksTUFBTTtBQUVyRSxjQUFRLElBQUksOENBQThDO0FBQUEsUUFDeEQ7QUFBQSxRQUNBLGNBQWMsZUFBZSxRQUFRO0FBQUEsUUFDckM7QUFBQSxRQUNBO0FBQUEsTUFDRixDQUFDO0FBRUQsVUFBSSxDQUFDLEtBQUssZUFBZSxlQUFlLEdBQUc7QUFDekM7QUFBQSxNQUNGO0FBR0EsV0FBSyxvQkFBb0I7QUFDekIsV0FBSyxpQkFBaUI7QUFHdEIsV0FBSyxXQUFXO0FBQ2hCLFdBQUssVUFBVTtBQUVmLFVBQUk7QUFFRixjQUFNLFNBQVMsTUFBTSxLQUFLLGVBQWUsWUFBWTtBQUVyRCxnQkFBUSxJQUFJLGdEQUFnRCxNQUFNO0FBR2xFLGFBQUssMEJBQTBCLE1BQU07QUFBQSxNQUN2QyxTQUFTQyxRQUFPO0FBQ2QsZ0JBQVEsTUFBTSw2Q0FBNkNBLE1BQUs7QUFHaEUsYUFBSyxVQUFVQSxPQUFNLE9BQU87QUFHNUIsYUFBSyx1QkFBdUJBLE1BQUs7QUFBQSxNQUNuQyxVQUFFO0FBQ0EsYUFBSyxXQUFXO0FBQUEsTUFDbEI7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxlQUFlLGlCQUFpQjtBQUM5QixVQUFJLENBQUMsaUJBQWlCO0FBQ3BCLGVBQU87QUFBQSxNQUNUO0FBRUEsWUFBTSx1QkFBdUI7QUFBQSxRQUMzQjtBQUFBLFFBQ0E7QUFBQSxRQUNBO0FBQUEsTUFDRjtBQUVBLGFBQU8scUJBQXFCO0FBQUEsUUFBSyxZQUMvQixnQkFBZ0IsWUFBWSxFQUFFLFNBQVMsT0FBTyxZQUFZLENBQUM7QUFBQSxNQUM3RDtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLE1BQU0sZ0JBQWdCO0FBQ3BCLFVBQUksT0FBTyxRQUFRO0FBQ2pCLGFBQUssU0FBUyxPQUFPLE9BQU8sS0FBSyxtQkFBbUI7QUFDcEQ7QUFBQSxNQUNGO0FBRUEsY0FBUSxJQUFJLG9EQUFvRDtBQUdoRSxZQUFNLElBQUksUUFBUSxDQUFDLFNBQVMsV0FBVztBQUNyQyxjQUFNLFNBQVMsU0FBUyxjQUFjLFFBQVE7QUFDOUMsZUFBTyxNQUFNO0FBQ2IsZUFBTyxRQUFRO0FBQ2YsZUFBTyxTQUFTO0FBQ2hCLGVBQU8sVUFBVTtBQUNqQixpQkFBUyxLQUFLLFlBQVksTUFBTTtBQUFBLE1BQ2xDLENBQUM7QUFFRCxXQUFLLFNBQVMsT0FBTyxPQUFPLEtBQUssbUJBQW1CO0FBQ3BELGNBQVEsSUFBSSxnREFBZ0Q7QUFBQSxJQUM5RDtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsTUFBTSwyQkFBMkI7QUFDL0IsVUFBSSxDQUFDLEtBQUssUUFBUTtBQUNoQixnQkFBUSxNQUFNLGlEQUFpRDtBQUMvRDtBQUFBLE1BQ0Y7QUFFQSxVQUFJLEtBQUssZ0JBQWdCO0FBRXZCO0FBQUEsTUFDRjtBQUVBLGNBQVEsSUFBSSwyREFBMkQ7QUFHdkUsV0FBSyxXQUFXLEtBQUssT0FBTyxTQUFTO0FBQUEsUUFDbkMsTUFBTTtBQUFBLFFBQ04sUUFBUTtBQUFBO0FBQUEsUUFDUixVQUFVO0FBQUEsUUFDVixZQUFZO0FBQUEsVUFDVixPQUFPO0FBQUEsUUFDVDtBQUFBLE1BQ0YsQ0FBQztBQUdELFdBQUssaUJBQWlCLEtBQUssU0FBUyxPQUFPLFNBQVM7QUFDcEQsV0FBSyxlQUFlLE1BQU0sS0FBSyxhQUFhO0FBRTVDLGNBQVEsSUFBSSx1REFBdUQ7QUFBQSxJQUNyRTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBUUEsTUFBTSxlQUFlLGNBQWM7QUFuUHJDLFVBQUFDLEtBQUE7QUFvUEksVUFBSSxDQUFDLEtBQUssVUFBVSxDQUFDLEtBQUssVUFBVTtBQUNsQyxjQUFNLElBQUksTUFBTSw0QkFBNEI7QUFBQSxNQUM5QztBQUVBLGNBQVEsSUFBSSw2REFBNkQ7QUFHekUsV0FBSyxTQUFTLE9BQU87QUFBQSxRQUNuQjtBQUFBLE1BQ0YsQ0FBQztBQUdELFlBQU0sU0FBUyxNQUFNLEtBQUssT0FBTyxlQUFlO0FBQUEsUUFDOUMsVUFBVSxLQUFLO0FBQUEsUUFDZixlQUFlO0FBQUEsVUFDYixZQUFZLEtBQUssa0JBQWtCLE9BQU8sU0FBUyxTQUFTO0FBQUEsUUFDOUQ7QUFBQSxRQUNBLFVBQVU7QUFBQTtBQUFBLE1BQ1osQ0FBQztBQUdELFVBQUksT0FBTyxPQUFPO0FBQ2hCLGNBQU0sSUFBSSxNQUFNLE9BQU8sTUFBTSxXQUFXLDZCQUE2QjtBQUFBLE1BQ3ZFO0FBRUEsWUFBSUEsTUFBQSxPQUFPLGtCQUFQLGdCQUFBQSxJQUFzQixZQUFXLGFBQWE7QUFDaEQsZUFBTztBQUFBLFVBQ0wsaUJBQWlCLE9BQU8sY0FBYztBQUFBLFVBQ3RDLFFBQVEsT0FBTyxjQUFjO0FBQUEsVUFDN0IsUUFBUSxPQUFPLGNBQWM7QUFBQSxVQUM3QixVQUFVLE9BQU8sY0FBYztBQUFBLFFBQ2pDO0FBQUEsTUFDRjtBQUdBLFlBQU0sSUFBSSxNQUFNLG9DQUFrQyxZQUFPLGtCQUFQLG1CQUFzQixXQUFVLFNBQVMsRUFBRTtBQUFBLElBQy9GO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSwwQkFBMEIsZUFBZTtBQUN2QyxXQUFLLFVBQVUsd0JBQXdCO0FBQUEsUUFDckMsVUFBVTtBQUFBLFFBQ1YsWUFBWSxLQUFLO0FBQUEsUUFDakIsU0FBUyxLQUFLO0FBQUEsUUFDZCxlQUFlLGNBQWM7QUFBQSxRQUM3QixVQUFVO0FBQUEsTUFDWixDQUFDO0FBRUQsY0FBUSxJQUFJLDhEQUE4RDtBQUFBLElBQzVFO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSx1QkFBdUJELFFBQU87QUFDNUIsV0FBSyxVQUFVLHFCQUFxQjtBQUFBLFFBQ2xDLFVBQVU7QUFBQSxRQUNWLFlBQVksS0FBSztBQUFBLFFBQ2pCLFNBQVMsS0FBSztBQUFBLFFBQ2QsT0FBT0EsT0FBTSxXQUFXO0FBQUEsUUFDeEIsV0FBV0EsT0FBTSxRQUFRO0FBQUEsTUFDM0IsQ0FBQztBQUVELGNBQVEsSUFBSSwyREFBMkQ7QUFBQSxJQUN6RTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxlQUFlO0FBRWIsV0FBSyxRQUFRLE1BQU0sVUFBVTtBQUc3QixVQUFJLEtBQUssa0JBQWtCO0FBQ3pCLGFBQUssY0FBYyxNQUFNLFVBQVU7QUFBQSxNQUNyQztBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsZUFBZTtBQUViLFdBQUssUUFBUSxNQUFNLFVBQVU7QUFBQSxJQUMvQjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsYUFBYTtBQUNYLFVBQUksS0FBSyxpQkFBaUI7QUFDeEIsYUFBSyxhQUFhLE1BQU0sVUFBVTtBQUFBLE1BQ3BDO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsYUFBYTtBQUNYLFVBQUksS0FBSyxpQkFBaUI7QUFDeEIsYUFBSyxhQUFhLE1BQU0sVUFBVTtBQUFBLE1BQ3BDO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsVUFBVSxTQUFTO0FBQ2pCLFVBQUksS0FBSyxnQkFBZ0I7QUFDdkIsYUFBSyxZQUFZLGNBQWM7QUFDL0IsYUFBSyxZQUFZLE1BQU0sVUFBVTtBQUFBLE1BQ25DO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsWUFBWTtBQUNWLFVBQUksS0FBSyxnQkFBZ0I7QUFDdkIsYUFBSyxZQUFZLE1BQU0sVUFBVTtBQUNqQyxhQUFLLFlBQVksY0FBYztBQUFBLE1BQ2pDO0FBQUEsSUFDRjtBQUFBLEVBQ0Y7QUE5VkUsZ0JBREssbUNBQ0UsVUFBUztBQUFBLElBQ2QsZ0JBQWdCO0FBQUEsSUFDaEIsTUFBTTtBQUFBLElBQ04sV0FBVztBQUFBLEVBQ2I7QUFFQSxnQkFQSyxtQ0FPRSxXQUFVLENBQUMsV0FBVyxVQUFVLE9BQU87OztBQ2JoRCxTQUFPLFdBQVcsWUFBWSxNQUFNO0FBR3BDLFdBQVMsU0FBUyxnQkFBZ0IsK0JBQXFCO0FBQ3ZELFdBQVMsU0FBUyxnQkFBZ0IsK0JBQXFCO0FBQ3ZELFdBQVMsU0FBUyxrQkFBa0IsaUNBQXVCO0FBQzNELFdBQVMsU0FBUyxrQkFBa0IsaUNBQXVCO0FBRzNELE1BQUksTUFBd0M7QUFDMUMsYUFBUyxRQUFRO0FBQ2pCLFlBQVEsSUFBSSx5REFBeUQsU0FBUyxPQUFPLG1CQUFtQjtBQUFBLEVBQzFHO0FBRUEsVUFBUSxJQUFJLDRDQUE0QzsiLAogICJuYW1lcyI6IFsiZXJyb3IiLCAiZmV0Y2giLCAibWF0Y2giLCAib2xkVmFsdWUiLCAiZXJyb3IiLCAiX2EiLCAiY29uc3RydWN0b3IiLCAiZWxlbWVudCIsICJfYSIsICJlcnJvciIsICJfYSIsICJlcnJvciIsICJfYSIsICJlcnJvciIsICJlcnJvciIsICJfYSJdCn0K
