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

  // resources/build/js/utils/event_bus.js
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
  var eventBus;
  var _a;
  if (typeof window !== "undefined" && window.eventBus) {
    console.log("[Stripe EventBus] Using existing global EventBus from window.eventBus");
    eventBus = window.eventBus;
  } else {
    console.log("[Stripe EventBus] Creating new EventBus instance");
    eventBus = new EventBus();
    if (typeof window !== "undefined" && ((_a = window.location) == null ? void 0 : _a.hostname) === "localhost") {
      eventBus.setDebug(true);
    }
    if (typeof window !== "undefined") {
      window.eventBus = eventBus;
    }
  }

  // resources/build/js/mixins/event_bus_mixin.js
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
      this.listen("oe:footer:submit-clicked", this.handleFooterSubmit.bind(this));
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
     * Handle oe:footer:submit-clicked event
     *
     * Event Detail:
     * {
     *   paymentMethod: string,
     *   basketId: string,
     *   totalPrice: number,
     *   currency: string,
     *   confirmed: boolean
     * }
     *
     * Responsibility:
     * - Trigger payment confirmation request
     * - Broadcast oe:payment:confirm-requested for checkout lifecycle
     */
    async handleFooterSubmit(event) {
      const { paymentMethod, basketId, totalPrice, currency } = event.detail;
      console.log("[OnePageStripeController] Footer submit clicked:", {
        paymentMethod,
        basketId,
        totalPrice,
        currency
      });
      if (!this.isStripeMethod(paymentMethod)) {
        return;
      }
      this.broadcast("oe:payment:confirm-requested", {
        paymentMethodId: paymentMethod,
        basketId,
        totalPrice,
        currency
      });
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

  // resources/build/js/controllers/stripe_checkout_footer_controller.js
  var stripe_checkout_footer_controller_default = class extends withEventBus(Controller) {
    /**
     * Controller initialization
     */
    connect() {
      console.log("[StripeCheckoutFooter] Connected", {
        basketId: this.basketIdValue,
        paymentMethod: this.paymentMethodValue,
        totalPrice: this.totalPriceValue,
        currency: this.currencyValue
      });
      this.setupEventListeners();
      this.updateButtonState();
    }
    /**
     * Setup EventBus event listeners
     *
     * Uses EventBus mixin's listen() method for automatic cleanup
     */
    setupEventListeners() {
      this.listen("oe:basket:updated", (data) => {
        console.log("[StripeCheckoutFooter] Basket updated", data);
        this.handleBasketUpdate(data);
      });
      this.listen("oe:payment:processing", (data) => {
        console.log("[StripeCheckoutFooter] Payment processing", data);
        this.showLoader();
      });
      this.listen("oe:payment:complete", (data) => {
        console.log("[StripeCheckoutFooter] Payment complete", data);
        this.hideLoader();
        this.showSuccess();
      });
      this.listen("oe:payment:error", (data) => {
        console.log("[StripeCheckoutFooter] Payment error", data);
        this.hideLoader();
        this.showError(data.message || "Payment processing failed");
      });
      this.listen("oe:payment:method-selected", (data) => {
        console.log("[StripeCheckoutFooter] Payment method selected", data);
        this.handlePaymentMethodChange(data);
      });
    }
    /**
     * NOTE: Terms validation removed - handled by checkout-footer-manager
     *
     * Terms checkbox is now in Part 1 (standard consents) of footer architecture.
     * checkout-footer-manager controller handles all terms validation.
     */
    /**
     * Handle submit button click
     *
     * IMPORTANT: Footer widget does NOT process payment directly!
     * It only broadcasts oe:footer:submit-clicked event.
     * Payment processing is handled by:
     * 1. checkout-lifecycle-controller → broadcasts oe:payment:confirm-requested
     * 2. onepage-stripe-controller → confirms payment with Stripe
     * 3. checkout-lifecycle-controller → places order via API
     *
     * This separation allows payment providers to handle their own payment logic
     * while footer widget remains generic and reusable.
     */
    async processPayment(event) {
      event.preventDefault();
      console.log("[StripeCheckoutFooter] Submit button clicked - broadcasting event");
      this.broadcast("oe:footer:submit-clicked", {
        paymentMethod: this.paymentMethodValue,
        basketId: this.basketIdValue,
        totalPrice: this.totalPriceValue,
        currency: this.currencyValue,
        confirmed: true
        // Terms already confirmed by checkout-footer-manager
      });
      console.log("[StripeCheckoutFooter] Event broadcasted - waiting for checkout lifecycle");
    }
    /**
     * Handle basket update event
     *
     * Updates total price display and validates state
     */
    handleBasketUpdate(data) {
      if (data.totalPrice !== void 0) {
        this.totalPriceValue = data.totalPrice;
        this.updateTotalDisplay(data.totalPrice, data.currency || this.currencyValue);
      }
      if (data.basketId) {
        this.basketIdValue = data.basketId;
      }
      this.updateButtonState();
    }
    /**
     * Handle payment method change event
     *
     * Show/hide footer based on payment method selection
     */
    handlePaymentMethodChange(data) {
      const isStripe = data.paymentMethodId === this.paymentMethodValue;
      if (isStripe) {
        this.element.style.display = "block";
      } else {
        this.element.style.display = "none";
      }
    }
    /**
     * Update total price display in submit button
     */
    updateTotalDisplay(totalPrice, currency) {
      const amountElement = this.submitButtonTarget.querySelector(".button-amount");
      if (amountElement) {
        const formattedPrice = this.formatPrice(totalPrice);
        amountElement.textContent = `${formattedPrice} ${currency}`;
      }
    }
    /**
     * Format price with proper decimal places
     */
    formatPrice(price) {
      return parseFloat(price).toFixed(2).replace(".", ",");
    }
    /**
     * Update submit button state
     *
     * Button is enabled by default. checkout-footer-manager handles terms validation.
     */
    updateButtonState() {
    }
    /**
     * Show loading overlay
     */
    showLoader() {
      console.log("[StripeCheckoutFooter] Showing loader");
      const buttonContent = this.submitButtonTarget.querySelector(".button-content");
      const buttonSpinner = this.submitButtonTarget.querySelector(".button-spinner");
      if (buttonContent)
        buttonContent.classList.add("d-none");
      if (buttonSpinner)
        buttonSpinner.classList.remove("d-none");
      this.loaderTarget.style.display = "flex";
      this.submitButtonTarget.disabled = true;
      this.hideError();
    }
    /**
     * Hide loading overlay
     */
    hideLoader() {
      console.log("[StripeCheckoutFooter] Hiding loader");
      const buttonContent = this.submitButtonTarget.querySelector(".button-content");
      const buttonSpinner = this.submitButtonTarget.querySelector(".button-spinner");
      if (buttonContent)
        buttonContent.classList.remove("d-none");
      if (buttonSpinner)
        buttonSpinner.classList.add("d-none");
      this.loaderTarget.style.display = "none";
      this.updateButtonState();
    }
    /**
     * Show error message
     */
    showError(message) {
      console.error("[StripeCheckoutFooter] Error:", message);
      if (this.hasErrorMessageTarget) {
        this.errorMessageTarget.textContent = message;
      }
      this.errorTarget.style.display = "block";
      this.errorTarget.scrollIntoView({
        behavior: "smooth",
        block: "center"
      });
    }
    /**
     * Hide error message
     */
    hideError() {
      this.errorTarget.style.display = "none";
    }
    /**
     * Show success state (briefly before redirect)
     */
    showSuccess() {
      console.log("[StripeCheckoutFooter] Payment successful");
      const buttonText = this.submitButtonTarget.querySelector(".button-text");
      if (buttonText) {
        buttonText.innerHTML = '<i class="fas fa-check me-2"></i>Payment Successful';
      }
      this.submitButtonTarget.classList.remove("btn-primary");
      this.submitButtonTarget.classList.add("btn-success");
    }
    /**
     * Controller cleanup
     *
     * EventBus listeners are automatically cleaned up by withEventBus mixin
     */
    disconnect() {
      console.log("[StripeCheckoutFooter] Disconnected");
    }
  };
  __publicField(stripe_checkout_footer_controller_default, "targets", [
    "submitButton",
    // Main submit button
    "loader",
    // Loading overlay
    "error",
    // Error message container
    "errorMessage"
    // Error message text element
  ]);
  __publicField(stripe_checkout_footer_controller_default, "values", {
    basketId: String,
    // Current basket ID
    paymentMethod: String,
    // Payment method ID (e.g., 'oxidstripe')
    totalPrice: Number,
    // Total order amount
    currency: String,
    // Currency code (e.g., 'EUR')
    csrfToken: String
    // CSRF token for API calls
  });

  // resources/build/js/app.js
  window.Stimulus = Application.start();
  Stimulus.register("stripe-order", stripe_order_controller_default);
  Stimulus.register("order-submit", order_submit_controller_default);
  Stimulus.register("agb-validation", agb_validation_controller_default);
  Stimulus.register("onepage-stripe", onepage_stripe_controller_default);
  Stimulus.register("stripe-checkout-footer", stripe_checkout_footer_controller_default);
  if (true) {
    Stimulus.debug = true;
    console.log("Stripe Module: Stimulus initialized with controllers:", Stimulus.router.modulesByIdentifier);
  }
  console.log("Stripe Module: JavaScript loaded and ready");
})();
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsiLi4vLi4vbm9kZV9tb2R1bGVzL0Bob3R3aXJlZC9zdGltdWx1cy9kaXN0L3N0aW11bHVzLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9jb250cm9sbGVycy9zdHJpcGVfb3JkZXJfY29udHJvbGxlci5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvY29udHJvbGxlcnMvb3JkZXJfc3VibWl0X2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL2NvbnRyb2xsZXJzL2FnYl92YWxpZGF0aW9uX2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL3V0aWxzL2V2ZW50X2J1cy5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvbWl4aW5zL2V2ZW50X2J1c19taXhpbi5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvY29udHJvbGxlcnMvb25lcGFnZV9zdHJpcGVfY29udHJvbGxlci5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvY29udHJvbGxlcnMvc3RyaXBlX2NoZWNrb3V0X2Zvb3Rlcl9jb250cm9sbGVyLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9hcHAuanMiXSwKICAic291cmNlc0NvbnRlbnQiOiBbIi8qXG5TdGltdWx1cyAzLjIuMVxuQ29weXJpZ2h0IFx1MDBBOSAyMDIzIEJhc2VjYW1wLCBMTENcbiAqL1xuY2xhc3MgRXZlbnRMaXN0ZW5lciB7XG4gICAgY29uc3RydWN0b3IoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKSB7XG4gICAgICAgIHRoaXMuZXZlbnRUYXJnZXQgPSBldmVudFRhcmdldDtcbiAgICAgICAgdGhpcy5ldmVudE5hbWUgPSBldmVudE5hbWU7XG4gICAgICAgIHRoaXMuZXZlbnRPcHRpb25zID0gZXZlbnRPcHRpb25zO1xuICAgICAgICB0aGlzLnVub3JkZXJlZEJpbmRpbmdzID0gbmV3IFNldCgpO1xuICAgIH1cbiAgICBjb25uZWN0KCkge1xuICAgICAgICB0aGlzLmV2ZW50VGFyZ2V0LmFkZEV2ZW50TGlzdGVuZXIodGhpcy5ldmVudE5hbWUsIHRoaXMsIHRoaXMuZXZlbnRPcHRpb25zKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldC5yZW1vdmVFdmVudExpc3RlbmVyKHRoaXMuZXZlbnROYW1lLCB0aGlzLCB0aGlzLmV2ZW50T3B0aW9ucyk7XG4gICAgfVxuICAgIGJpbmRpbmdDb25uZWN0ZWQoYmluZGluZykge1xuICAgICAgICB0aGlzLnVub3JkZXJlZEJpbmRpbmdzLmFkZChiaW5kaW5nKTtcbiAgICB9XG4gICAgYmluZGluZ0Rpc2Nvbm5lY3RlZChiaW5kaW5nKSB7XG4gICAgICAgIHRoaXMudW5vcmRlcmVkQmluZGluZ3MuZGVsZXRlKGJpbmRpbmcpO1xuICAgIH1cbiAgICBoYW5kbGVFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBleHRlbmRlZEV2ZW50ID0gZXh0ZW5kRXZlbnQoZXZlbnQpO1xuICAgICAgICBmb3IgKGNvbnN0IGJpbmRpbmcgb2YgdGhpcy5iaW5kaW5ncykge1xuICAgICAgICAgICAgaWYgKGV4dGVuZGVkRXZlbnQuaW1tZWRpYXRlUHJvcGFnYXRpb25TdG9wcGVkKSB7XG4gICAgICAgICAgICAgICAgYnJlYWs7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICBiaW5kaW5nLmhhbmRsZUV2ZW50KGV4dGVuZGVkRXZlbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGhhc0JpbmRpbmdzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy51bm9yZGVyZWRCaW5kaW5ncy5zaXplID4gMDtcbiAgICB9XG4gICAgZ2V0IGJpbmRpbmdzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnVub3JkZXJlZEJpbmRpbmdzKS5zb3J0KChsZWZ0LCByaWdodCkgPT4ge1xuICAgICAgICAgICAgY29uc3QgbGVmdEluZGV4ID0gbGVmdC5pbmRleCwgcmlnaHRJbmRleCA9IHJpZ2h0LmluZGV4O1xuICAgICAgICAgICAgcmV0dXJuIGxlZnRJbmRleCA8IHJpZ2h0SW5kZXggPyAtMSA6IGxlZnRJbmRleCA+IHJpZ2h0SW5kZXggPyAxIDogMDtcbiAgICAgICAgfSk7XG4gICAgfVxufVxuZnVuY3Rpb24gZXh0ZW5kRXZlbnQoZXZlbnQpIHtcbiAgICBpZiAoXCJpbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWRcIiBpbiBldmVudCkge1xuICAgICAgICByZXR1cm4gZXZlbnQ7XG4gICAgfVxuICAgIGVsc2Uge1xuICAgICAgICBjb25zdCB7IHN0b3BJbW1lZGlhdGVQcm9wYWdhdGlvbiB9ID0gZXZlbnQ7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKGV2ZW50LCB7XG4gICAgICAgICAgICBpbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWQ6IGZhbHNlLFxuICAgICAgICAgICAgc3RvcEltbWVkaWF0ZVByb3BhZ2F0aW9uKCkge1xuICAgICAgICAgICAgICAgIHRoaXMuaW1tZWRpYXRlUHJvcGFnYXRpb25TdG9wcGVkID0gdHJ1ZTtcbiAgICAgICAgICAgICAgICBzdG9wSW1tZWRpYXRlUHJvcGFnYXRpb24uY2FsbCh0aGlzKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0pO1xuICAgIH1cbn1cblxuY2xhc3MgRGlzcGF0Y2hlciB7XG4gICAgY29uc3RydWN0b3IoYXBwbGljYXRpb24pIHtcbiAgICAgICAgdGhpcy5hcHBsaWNhdGlvbiA9IGFwcGxpY2F0aW9uO1xuICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJNYXBzID0gbmV3IE1hcCgpO1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVycy5mb3JFYWNoKChldmVudExpc3RlbmVyKSA9PiBldmVudExpc3RlbmVyLmNvbm5lY3QoKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJzLmZvckVhY2goKGV2ZW50TGlzdGVuZXIpID0+IGV2ZW50TGlzdGVuZXIuZGlzY29ubmVjdCgpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgZXZlbnRMaXN0ZW5lcnMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMudmFsdWVzKCkpLnJlZHVjZSgobGlzdGVuZXJzLCBtYXApID0+IGxpc3RlbmVycy5jb25jYXQoQXJyYXkuZnJvbShtYXAudmFsdWVzKCkpKSwgW10pO1xuICAgIH1cbiAgICBiaW5kaW5nQ29ubmVjdGVkKGJpbmRpbmcpIHtcbiAgICAgICAgdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJGb3JCaW5kaW5nKGJpbmRpbmcpLmJpbmRpbmdDb25uZWN0ZWQoYmluZGluZyk7XG4gICAgfVxuICAgIGJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZywgY2xlYXJFdmVudExpc3RlbmVycyA9IGZhbHNlKSB7XG4gICAgICAgIHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKS5iaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcpO1xuICAgICAgICBpZiAoY2xlYXJFdmVudExpc3RlbmVycylcbiAgICAgICAgICAgIHRoaXMuY2xlYXJFdmVudExpc3RlbmVyc0ZvckJpbmRpbmcoYmluZGluZyk7XG4gICAgfVxuICAgIGhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwgPSB7fSkge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBgRXJyb3IgJHttZXNzYWdlfWAsIGRldGFpbCk7XG4gICAgfVxuICAgIGNsZWFyRXZlbnRMaXN0ZW5lcnNGb3JCaW5kaW5nKGJpbmRpbmcpIHtcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lciA9IHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKTtcbiAgICAgICAgaWYgKCFldmVudExpc3RlbmVyLmhhc0JpbmRpbmdzKCkpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5yZW1vdmVNYXBwZWRFdmVudExpc3RlbmVyRm9yKGJpbmRpbmcpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlbW92ZU1hcHBlZEV2ZW50TGlzdGVuZXJGb3IoYmluZGluZykge1xuICAgICAgICBjb25zdCB7IGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyB9ID0gYmluZGluZztcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lck1hcCA9IHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyTWFwRm9yRXZlbnRUYXJnZXQoZXZlbnRUYXJnZXQpO1xuICAgICAgICBjb25zdCBjYWNoZUtleSA9IHRoaXMuY2FjaGVLZXkoZXZlbnROYW1lLCBldmVudE9wdGlvbnMpO1xuICAgICAgICBldmVudExpc3RlbmVyTWFwLmRlbGV0ZShjYWNoZUtleSk7XG4gICAgICAgIGlmIChldmVudExpc3RlbmVyTWFwLnNpemUgPT0gMClcbiAgICAgICAgICAgIHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMuZGVsZXRlKGV2ZW50VGFyZ2V0KTtcbiAgICB9XG4gICAgZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKSB7XG4gICAgICAgIGNvbnN0IHsgZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zIH0gPSBiaW5kaW5nO1xuICAgICAgICByZXR1cm4gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICB9XG4gICAgZmV0Y2hFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICBjb25zdCBldmVudExpc3RlbmVyTWFwID0gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJNYXBGb3JFdmVudFRhcmdldChldmVudFRhcmdldCk7XG4gICAgICAgIGNvbnN0IGNhY2hlS2V5ID0gdGhpcy5jYWNoZUtleShldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgIGxldCBldmVudExpc3RlbmVyID0gZXZlbnRMaXN0ZW5lck1hcC5nZXQoY2FjaGVLZXkpO1xuICAgICAgICBpZiAoIWV2ZW50TGlzdGVuZXIpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIgPSB0aGlzLmNyZWF0ZUV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXJNYXAuc2V0KGNhY2hlS2V5LCBldmVudExpc3RlbmVyKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZXZlbnRMaXN0ZW5lcjtcbiAgICB9XG4gICAgY3JlYXRlRXZlbnRMaXN0ZW5lcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpIHtcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lciA9IG5ldyBFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIuY29ubmVjdCgpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudExpc3RlbmVyO1xuICAgIH1cbiAgICBmZXRjaEV2ZW50TGlzdGVuZXJNYXBGb3JFdmVudFRhcmdldChldmVudFRhcmdldCkge1xuICAgICAgICBsZXQgZXZlbnRMaXN0ZW5lck1hcCA9IHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMuZ2V0KGV2ZW50VGFyZ2V0KTtcbiAgICAgICAgaWYgKCFldmVudExpc3RlbmVyTWFwKSB7XG4gICAgICAgICAgICBldmVudExpc3RlbmVyTWFwID0gbmV3IE1hcCgpO1xuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVyTWFwcy5zZXQoZXZlbnRUYXJnZXQsIGV2ZW50TGlzdGVuZXJNYXApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudExpc3RlbmVyTWFwO1xuICAgIH1cbiAgICBjYWNoZUtleShldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICBjb25zdCBwYXJ0cyA9IFtldmVudE5hbWVdO1xuICAgICAgICBPYmplY3Qua2V5cyhldmVudE9wdGlvbnMpXG4gICAgICAgICAgICAuc29ydCgpXG4gICAgICAgICAgICAuZm9yRWFjaCgoa2V5KSA9PiB7XG4gICAgICAgICAgICBwYXJ0cy5wdXNoKGAke2V2ZW50T3B0aW9uc1trZXldID8gXCJcIiA6IFwiIVwifSR7a2V5fWApO1xuICAgICAgICB9KTtcbiAgICAgICAgcmV0dXJuIHBhcnRzLmpvaW4oXCI6XCIpO1xuICAgIH1cbn1cblxuY29uc3QgZGVmYXVsdEFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzID0ge1xuICAgIHN0b3AoeyBldmVudCwgdmFsdWUgfSkge1xuICAgICAgICBpZiAodmFsdWUpXG4gICAgICAgICAgICBldmVudC5zdG9wUHJvcGFnYXRpb24oKTtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfSxcbiAgICBwcmV2ZW50KHsgZXZlbnQsIHZhbHVlIH0pIHtcbiAgICAgICAgaWYgKHZhbHVlKVxuICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfSxcbiAgICBzZWxmKHsgZXZlbnQsIHZhbHVlLCBlbGVtZW50IH0pIHtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICByZXR1cm4gZWxlbWVudCA9PT0gZXZlbnQudGFyZ2V0O1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICB9LFxufTtcbmNvbnN0IGRlc2NyaXB0b3JQYXR0ZXJuID0gL14oPzooPzooW14uXSs/KVxcKyk/KC4rPykoPzpcXC4oLis/KSk/KD86QCh3aW5kb3d8ZG9jdW1lbnQpKT8tPik/KC4rPykoPzojKFteOl0rPykpKD86OiguKykpPyQvO1xuZnVuY3Rpb24gcGFyc2VBY3Rpb25EZXNjcmlwdG9yU3RyaW5nKGRlc2NyaXB0b3JTdHJpbmcpIHtcbiAgICBjb25zdCBzb3VyY2UgPSBkZXNjcmlwdG9yU3RyaW5nLnRyaW0oKTtcbiAgICBjb25zdCBtYXRjaGVzID0gc291cmNlLm1hdGNoKGRlc2NyaXB0b3JQYXR0ZXJuKSB8fCBbXTtcbiAgICBsZXQgZXZlbnROYW1lID0gbWF0Y2hlc1syXTtcbiAgICBsZXQga2V5RmlsdGVyID0gbWF0Y2hlc1szXTtcbiAgICBpZiAoa2V5RmlsdGVyICYmICFbXCJrZXlkb3duXCIsIFwia2V5dXBcIiwgXCJrZXlwcmVzc1wiXS5pbmNsdWRlcyhldmVudE5hbWUpKSB7XG4gICAgICAgIGV2ZW50TmFtZSArPSBgLiR7a2V5RmlsdGVyfWA7XG4gICAgICAgIGtleUZpbHRlciA9IFwiXCI7XG4gICAgfVxuICAgIHJldHVybiB7XG4gICAgICAgIGV2ZW50VGFyZ2V0OiBwYXJzZUV2ZW50VGFyZ2V0KG1hdGNoZXNbNF0pLFxuICAgICAgICBldmVudE5hbWUsXG4gICAgICAgIGV2ZW50T3B0aW9uczogbWF0Y2hlc1s3XSA/IHBhcnNlRXZlbnRPcHRpb25zKG1hdGNoZXNbN10pIDoge30sXG4gICAgICAgIGlkZW50aWZpZXI6IG1hdGNoZXNbNV0sXG4gICAgICAgIG1ldGhvZE5hbWU6IG1hdGNoZXNbNl0sXG4gICAgICAgIGtleUZpbHRlcjogbWF0Y2hlc1sxXSB8fCBrZXlGaWx0ZXIsXG4gICAgfTtcbn1cbmZ1bmN0aW9uIHBhcnNlRXZlbnRUYXJnZXQoZXZlbnRUYXJnZXROYW1lKSB7XG4gICAgaWYgKGV2ZW50VGFyZ2V0TmFtZSA9PSBcIndpbmRvd1wiKSB7XG4gICAgICAgIHJldHVybiB3aW5kb3c7XG4gICAgfVxuICAgIGVsc2UgaWYgKGV2ZW50VGFyZ2V0TmFtZSA9PSBcImRvY3VtZW50XCIpIHtcbiAgICAgICAgcmV0dXJuIGRvY3VtZW50O1xuICAgIH1cbn1cbmZ1bmN0aW9uIHBhcnNlRXZlbnRPcHRpb25zKGV2ZW50T3B0aW9ucykge1xuICAgIHJldHVybiBldmVudE9wdGlvbnNcbiAgICAgICAgLnNwbGl0KFwiOlwiKVxuICAgICAgICAucmVkdWNlKChvcHRpb25zLCB0b2tlbikgPT4gT2JqZWN0LmFzc2lnbihvcHRpb25zLCB7IFt0b2tlbi5yZXBsYWNlKC9eIS8sIFwiXCIpXTogIS9eIS8udGVzdCh0b2tlbikgfSksIHt9KTtcbn1cbmZ1bmN0aW9uIHN0cmluZ2lmeUV2ZW50VGFyZ2V0KGV2ZW50VGFyZ2V0KSB7XG4gICAgaWYgKGV2ZW50VGFyZ2V0ID09IHdpbmRvdykge1xuICAgICAgICByZXR1cm4gXCJ3aW5kb3dcIjtcbiAgICB9XG4gICAgZWxzZSBpZiAoZXZlbnRUYXJnZXQgPT0gZG9jdW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIFwiZG9jdW1lbnRcIjtcbiAgICB9XG59XG5cbmZ1bmN0aW9uIGNhbWVsaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLnJlcGxhY2UoLyg/OltfLV0pKFthLXowLTldKS9nLCAoXywgY2hhcikgPT4gY2hhci50b1VwcGVyQ2FzZSgpKTtcbn1cbmZ1bmN0aW9uIG5hbWVzcGFjZUNhbWVsaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIGNhbWVsaXplKHZhbHVlLnJlcGxhY2UoLy0tL2csIFwiLVwiKS5yZXBsYWNlKC9fXy9nLCBcIl9cIikpO1xufVxuZnVuY3Rpb24gY2FwaXRhbGl6ZSh2YWx1ZSkge1xuICAgIHJldHVybiB2YWx1ZS5jaGFyQXQoMCkudG9VcHBlckNhc2UoKSArIHZhbHVlLnNsaWNlKDEpO1xufVxuZnVuY3Rpb24gZGFzaGVyaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLnJlcGxhY2UoLyhbQS1aXSkvZywgKF8sIGNoYXIpID0+IGAtJHtjaGFyLnRvTG93ZXJDYXNlKCl9YCk7XG59XG5mdW5jdGlvbiB0b2tlbml6ZSh2YWx1ZSkge1xuICAgIHJldHVybiB2YWx1ZS5tYXRjaCgvW15cXHNdKy9nKSB8fCBbXTtcbn1cblxuZnVuY3Rpb24gaXNTb21ldGhpbmcob2JqZWN0KSB7XG4gICAgcmV0dXJuIG9iamVjdCAhPT0gbnVsbCAmJiBvYmplY3QgIT09IHVuZGVmaW5lZDtcbn1cbmZ1bmN0aW9uIGhhc1Byb3BlcnR5KG9iamVjdCwgcHJvcGVydHkpIHtcbiAgICByZXR1cm4gT2JqZWN0LnByb3RvdHlwZS5oYXNPd25Qcm9wZXJ0eS5jYWxsKG9iamVjdCwgcHJvcGVydHkpO1xufVxuXG5jb25zdCBhbGxNb2RpZmllcnMgPSBbXCJtZXRhXCIsIFwiY3RybFwiLCBcImFsdFwiLCBcInNoaWZ0XCJdO1xuY2xhc3MgQWN0aW9uIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBpbmRleCwgZGVzY3JpcHRvciwgc2NoZW1hKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuaW5kZXggPSBpbmRleDtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldCA9IGRlc2NyaXB0b3IuZXZlbnRUYXJnZXQgfHwgZWxlbWVudDtcbiAgICAgICAgdGhpcy5ldmVudE5hbWUgPSBkZXNjcmlwdG9yLmV2ZW50TmFtZSB8fCBnZXREZWZhdWx0RXZlbnROYW1lRm9yRWxlbWVudChlbGVtZW50KSB8fCBlcnJvcihcIm1pc3NpbmcgZXZlbnQgbmFtZVwiKTtcbiAgICAgICAgdGhpcy5ldmVudE9wdGlvbnMgPSBkZXNjcmlwdG9yLmV2ZW50T3B0aW9ucyB8fCB7fTtcbiAgICAgICAgdGhpcy5pZGVudGlmaWVyID0gZGVzY3JpcHRvci5pZGVudGlmaWVyIHx8IGVycm9yKFwibWlzc2luZyBpZGVudGlmaWVyXCIpO1xuICAgICAgICB0aGlzLm1ldGhvZE5hbWUgPSBkZXNjcmlwdG9yLm1ldGhvZE5hbWUgfHwgZXJyb3IoXCJtaXNzaW5nIG1ldGhvZCBuYW1lXCIpO1xuICAgICAgICB0aGlzLmtleUZpbHRlciA9IGRlc2NyaXB0b3Iua2V5RmlsdGVyIHx8IFwiXCI7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgIH1cbiAgICBzdGF0aWMgZm9yVG9rZW4odG9rZW4sIHNjaGVtYSkge1xuICAgICAgICByZXR1cm4gbmV3IHRoaXModG9rZW4uZWxlbWVudCwgdG9rZW4uaW5kZXgsIHBhcnNlQWN0aW9uRGVzY3JpcHRvclN0cmluZyh0b2tlbi5jb250ZW50KSwgc2NoZW1hKTtcbiAgICB9XG4gICAgdG9TdHJpbmcoKSB7XG4gICAgICAgIGNvbnN0IGV2ZW50RmlsdGVyID0gdGhpcy5rZXlGaWx0ZXIgPyBgLiR7dGhpcy5rZXlGaWx0ZXJ9YCA6IFwiXCI7XG4gICAgICAgIGNvbnN0IGV2ZW50VGFyZ2V0ID0gdGhpcy5ldmVudFRhcmdldE5hbWUgPyBgQCR7dGhpcy5ldmVudFRhcmdldE5hbWV9YCA6IFwiXCI7XG4gICAgICAgIHJldHVybiBgJHt0aGlzLmV2ZW50TmFtZX0ke2V2ZW50RmlsdGVyfSR7ZXZlbnRUYXJnZXR9LT4ke3RoaXMuaWRlbnRpZmllcn0jJHt0aGlzLm1ldGhvZE5hbWV9YDtcbiAgICB9XG4gICAgc2hvdWxkSWdub3JlS2V5Ym9hcmRFdmVudChldmVudCkge1xuICAgICAgICBpZiAoIXRoaXMua2V5RmlsdGVyKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgY29uc3QgZmlsdGVycyA9IHRoaXMua2V5RmlsdGVyLnNwbGl0KFwiK1wiKTtcbiAgICAgICAgaWYgKHRoaXMua2V5RmlsdGVyRGlzc2F0aXNmaWVkKGV2ZW50LCBmaWx0ZXJzKSkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgY29uc3Qgc3RhbmRhcmRGaWx0ZXIgPSBmaWx0ZXJzLmZpbHRlcigoa2V5KSA9PiAhYWxsTW9kaWZpZXJzLmluY2x1ZGVzKGtleSkpWzBdO1xuICAgICAgICBpZiAoIXN0YW5kYXJkRmlsdGVyKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKCFoYXNQcm9wZXJ0eSh0aGlzLmtleU1hcHBpbmdzLCBzdGFuZGFyZEZpbHRlcikpIHtcbiAgICAgICAgICAgIGVycm9yKGBjb250YWlucyB1bmtub3duIGtleSBmaWx0ZXI6ICR7dGhpcy5rZXlGaWx0ZXJ9YCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHRoaXMua2V5TWFwcGluZ3Nbc3RhbmRhcmRGaWx0ZXJdLnRvTG93ZXJDYXNlKCkgIT09IGV2ZW50LmtleS50b0xvd2VyQ2FzZSgpO1xuICAgIH1cbiAgICBzaG91bGRJZ25vcmVNb3VzZUV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGlmICghdGhpcy5rZXlGaWx0ZXIpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBjb25zdCBmaWx0ZXJzID0gW3RoaXMua2V5RmlsdGVyXTtcbiAgICAgICAgaWYgKHRoaXMua2V5RmlsdGVyRGlzc2F0aXNmaWVkKGV2ZW50LCBmaWx0ZXJzKSkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgIH1cbiAgICBnZXQgcGFyYW1zKCkge1xuICAgICAgICBjb25zdCBwYXJhbXMgPSB7fTtcbiAgICAgICAgY29uc3QgcGF0dGVybiA9IG5ldyBSZWdFeHAoYF5kYXRhLSR7dGhpcy5pZGVudGlmaWVyfS0oLispLXBhcmFtJGAsIFwiaVwiKTtcbiAgICAgICAgZm9yIChjb25zdCB7IG5hbWUsIHZhbHVlIH0gb2YgQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnQuYXR0cmlidXRlcykpIHtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoID0gbmFtZS5tYXRjaChwYXR0ZXJuKTtcbiAgICAgICAgICAgIGNvbnN0IGtleSA9IG1hdGNoICYmIG1hdGNoWzFdO1xuICAgICAgICAgICAgaWYgKGtleSkge1xuICAgICAgICAgICAgICAgIHBhcmFtc1tjYW1lbGl6ZShrZXkpXSA9IHR5cGVjYXN0KHZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFyYW1zO1xuICAgIH1cbiAgICBnZXQgZXZlbnRUYXJnZXROYW1lKCkge1xuICAgICAgICByZXR1cm4gc3RyaW5naWZ5RXZlbnRUYXJnZXQodGhpcy5ldmVudFRhcmdldCk7XG4gICAgfVxuICAgIGdldCBrZXlNYXBwaW5ncygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmtleU1hcHBpbmdzO1xuICAgIH1cbiAgICBrZXlGaWx0ZXJEaXNzYXRpc2ZpZWQoZXZlbnQsIGZpbHRlcnMpIHtcbiAgICAgICAgY29uc3QgW21ldGEsIGN0cmwsIGFsdCwgc2hpZnRdID0gYWxsTW9kaWZpZXJzLm1hcCgobW9kaWZpZXIpID0+IGZpbHRlcnMuaW5jbHVkZXMobW9kaWZpZXIpKTtcbiAgICAgICAgcmV0dXJuIGV2ZW50Lm1ldGFLZXkgIT09IG1ldGEgfHwgZXZlbnQuY3RybEtleSAhPT0gY3RybCB8fCBldmVudC5hbHRLZXkgIT09IGFsdCB8fCBldmVudC5zaGlmdEtleSAhPT0gc2hpZnQ7XG4gICAgfVxufVxuY29uc3QgZGVmYXVsdEV2ZW50TmFtZXMgPSB7XG4gICAgYTogKCkgPT4gXCJjbGlja1wiLFxuICAgIGJ1dHRvbjogKCkgPT4gXCJjbGlja1wiLFxuICAgIGZvcm06ICgpID0+IFwic3VibWl0XCIsXG4gICAgZGV0YWlsczogKCkgPT4gXCJ0b2dnbGVcIixcbiAgICBpbnB1dDogKGUpID0+IChlLmdldEF0dHJpYnV0ZShcInR5cGVcIikgPT0gXCJzdWJtaXRcIiA/IFwiY2xpY2tcIiA6IFwiaW5wdXRcIiksXG4gICAgc2VsZWN0OiAoKSA9PiBcImNoYW5nZVwiLFxuICAgIHRleHRhcmVhOiAoKSA9PiBcImlucHV0XCIsXG59O1xuZnVuY3Rpb24gZ2V0RGVmYXVsdEV2ZW50TmFtZUZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgIGNvbnN0IHRhZ05hbWUgPSBlbGVtZW50LnRhZ05hbWUudG9Mb3dlckNhc2UoKTtcbiAgICBpZiAodGFnTmFtZSBpbiBkZWZhdWx0RXZlbnROYW1lcykge1xuICAgICAgICByZXR1cm4gZGVmYXVsdEV2ZW50TmFtZXNbdGFnTmFtZV0oZWxlbWVudCk7XG4gICAgfVxufVxuZnVuY3Rpb24gZXJyb3IobWVzc2FnZSkge1xuICAgIHRocm93IG5ldyBFcnJvcihtZXNzYWdlKTtcbn1cbmZ1bmN0aW9uIHR5cGVjYXN0KHZhbHVlKSB7XG4gICAgdHJ5IHtcbiAgICAgICAgcmV0dXJuIEpTT04ucGFyc2UodmFsdWUpO1xuICAgIH1cbiAgICBjYXRjaCAob19PKSB7XG4gICAgICAgIHJldHVybiB2YWx1ZTtcbiAgICB9XG59XG5cbmNsYXNzIEJpbmRpbmcge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmFjdGlvbiA9IGFjdGlvbjtcbiAgICB9XG4gICAgZ2V0IGluZGV4KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uaW5kZXg7XG4gICAgfVxuICAgIGdldCBldmVudFRhcmdldCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYWN0aW9uLmV2ZW50VGFyZ2V0O1xuICAgIH1cbiAgICBnZXQgZXZlbnRPcHRpb25zKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uZXZlbnRPcHRpb25zO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBoYW5kbGVFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBhY3Rpb25FdmVudCA9IHRoaXMucHJlcGFyZUFjdGlvbkV2ZW50KGV2ZW50KTtcbiAgICAgICAgaWYgKHRoaXMud2lsbEJlSW52b2tlZEJ5RXZlbnQoZXZlbnQpICYmIHRoaXMuYXBwbHlFdmVudE1vZGlmaWVycyhhY3Rpb25FdmVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuaW52b2tlV2l0aEV2ZW50KGFjdGlvbkV2ZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgZXZlbnROYW1lKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uZXZlbnROYW1lO1xuICAgIH1cbiAgICBnZXQgbWV0aG9kKCkge1xuICAgICAgICBjb25zdCBtZXRob2QgPSB0aGlzLmNvbnRyb2xsZXJbdGhpcy5tZXRob2ROYW1lXTtcbiAgICAgICAgaWYgKHR5cGVvZiBtZXRob2QgPT0gXCJmdW5jdGlvblwiKSB7XG4gICAgICAgICAgICByZXR1cm4gbWV0aG9kO1xuICAgICAgICB9XG4gICAgICAgIHRocm93IG5ldyBFcnJvcihgQWN0aW9uIFwiJHt0aGlzLmFjdGlvbn1cIiByZWZlcmVuY2VzIHVuZGVmaW5lZCBtZXRob2QgXCIke3RoaXMubWV0aG9kTmFtZX1cImApO1xuICAgIH1cbiAgICBhcHBseUV2ZW50TW9kaWZpZXJzKGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdGhpcy5hY3Rpb247XG4gICAgICAgIGNvbnN0IHsgYWN0aW9uRGVzY3JpcHRvckZpbHRlcnMgfSA9IHRoaXMuY29udGV4dC5hcHBsaWNhdGlvbjtcbiAgICAgICAgY29uc3QgeyBjb250cm9sbGVyIH0gPSB0aGlzLmNvbnRleHQ7XG4gICAgICAgIGxldCBwYXNzZXMgPSB0cnVlO1xuICAgICAgICBmb3IgKGNvbnN0IFtuYW1lLCB2YWx1ZV0gb2YgT2JqZWN0LmVudHJpZXModGhpcy5ldmVudE9wdGlvbnMpKSB7XG4gICAgICAgICAgICBpZiAobmFtZSBpbiBhY3Rpb25EZXNjcmlwdG9yRmlsdGVycykge1xuICAgICAgICAgICAgICAgIGNvbnN0IGZpbHRlciA9IGFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzW25hbWVdO1xuICAgICAgICAgICAgICAgIHBhc3NlcyA9IHBhc3NlcyAmJiBmaWx0ZXIoeyBuYW1lLCB2YWx1ZSwgZXZlbnQsIGVsZW1lbnQsIGNvbnRyb2xsZXIgfSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICBjb250aW51ZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFzc2VzO1xuICAgIH1cbiAgICBwcmVwYXJlQWN0aW9uRXZlbnQoZXZlbnQpIHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24oZXZlbnQsIHsgcGFyYW1zOiB0aGlzLmFjdGlvbi5wYXJhbXMgfSk7XG4gICAgfVxuICAgIGludm9rZVdpdGhFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCB7IHRhcmdldCwgY3VycmVudFRhcmdldCB9ID0gZXZlbnQ7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLm1ldGhvZC5jYWxsKHRoaXMuY29udHJvbGxlciwgZXZlbnQpO1xuICAgICAgICAgICAgdGhpcy5jb250ZXh0LmxvZ0RlYnVnQWN0aXZpdHkodGhpcy5tZXRob2ROYW1lLCB7IGV2ZW50LCB0YXJnZXQsIGN1cnJlbnRUYXJnZXQsIGFjdGlvbjogdGhpcy5tZXRob2ROYW1lIH0pO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50LCBpbmRleCB9ID0gdGhpcztcbiAgICAgICAgICAgIGNvbnN0IGRldGFpbCA9IHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCwgaW5kZXgsIGV2ZW50IH07XG4gICAgICAgICAgICB0aGlzLmNvbnRleHQuaGFuZGxlRXJyb3IoZXJyb3IsIGBpbnZva2luZyBhY3Rpb24gXCIke3RoaXMuYWN0aW9ufVwiYCwgZGV0YWlsKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICB3aWxsQmVJbnZva2VkQnlFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBldmVudFRhcmdldCA9IGV2ZW50LnRhcmdldDtcbiAgICAgICAgaWYgKGV2ZW50IGluc3RhbmNlb2YgS2V5Ym9hcmRFdmVudCAmJiB0aGlzLmFjdGlvbi5zaG91bGRJZ25vcmVLZXlib2FyZEV2ZW50KGV2ZW50KSkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmIChldmVudCBpbnN0YW5jZW9mIE1vdXNlRXZlbnQgJiYgdGhpcy5hY3Rpb24uc2hvdWxkSWdub3JlTW91c2VFdmVudChldmVudCkpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBpZiAodGhpcy5lbGVtZW50ID09PSBldmVudFRhcmdldCkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAoZXZlbnRUYXJnZXQgaW5zdGFuY2VvZiBFbGVtZW50ICYmIHRoaXMuZWxlbWVudC5jb250YWlucyhldmVudFRhcmdldCkpIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmNvbnRhaW5zRWxlbWVudChldmVudFRhcmdldCk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5jb250YWluc0VsZW1lbnQodGhpcy5hY3Rpb24uZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuY29udHJvbGxlcjtcbiAgICB9XG4gICAgZ2V0IG1ldGhvZE5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFjdGlvbi5tZXRob2ROYW1lO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbn1cblxuY2xhc3MgRWxlbWVudE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXJJbml0ID0geyBhdHRyaWJ1dGVzOiB0cnVlLCBjaGlsZExpc3Q6IHRydWUsIHN1YnRyZWU6IHRydWUgfTtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5lbGVtZW50cyA9IG5ldyBTZXQoKTtcbiAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyID0gbmV3IE11dGF0aW9uT2JzZXJ2ZXIoKG11dGF0aW9ucykgPT4gdGhpcy5wcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IHRydWU7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIub2JzZXJ2ZSh0aGlzLmVsZW1lbnQsIHRoaXMubXV0YXRpb25PYnNlcnZlckluaXQpO1xuICAgICAgICAgICAgdGhpcy5yZWZyZXNoKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcGF1c2UoY2FsbGJhY2spIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGNhbGxiYWNrKCk7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIub2JzZXJ2ZSh0aGlzLmVsZW1lbnQsIHRoaXMubXV0YXRpb25PYnNlcnZlckluaXQpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIudGFrZVJlY29yZHMoKTtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gbmV3IFNldCh0aGlzLm1hdGNoRWxlbWVudHNJblRyZWUoKSk7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnRzKSkge1xuICAgICAgICAgICAgICAgIGlmICghbWF0Y2hlcy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICAgICAgdGhpcy5yZW1vdmVFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiBBcnJheS5mcm9tKG1hdGNoZXMpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5hZGRFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbnMobXV0YXRpb25zKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGZvciAoY29uc3QgbXV0YXRpb24gb2YgbXV0YXRpb25zKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzTXV0YXRpb24obXV0YXRpb24pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbihtdXRhdGlvbikge1xuICAgICAgICBpZiAobXV0YXRpb24udHlwZSA9PSBcImF0dHJpYnV0ZXNcIikge1xuICAgICAgICAgICAgdGhpcy5wcm9jZXNzQXR0cmlidXRlQ2hhbmdlKG11dGF0aW9uLnRhcmdldCwgbXV0YXRpb24uYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAobXV0YXRpb24udHlwZSA9PSBcImNoaWxkTGlzdFwiKSB7XG4gICAgICAgICAgICB0aGlzLnByb2Nlc3NSZW1vdmVkTm9kZXMobXV0YXRpb24ucmVtb3ZlZE5vZGVzKTtcbiAgICAgICAgICAgIHRoaXMucHJvY2Vzc0FkZGVkTm9kZXMobXV0YXRpb24uYWRkZWROb2Rlcyk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc0F0dHJpYnV0ZUNoYW5nZShlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmICh0aGlzLmVsZW1lbnRzLmhhcyhlbGVtZW50KSkge1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZUNoYW5nZWQgJiYgdGhpcy5tYXRjaEVsZW1lbnQoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgdGhpcy5yZW1vdmVFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgICAgIGVsc2UgaWYgKHRoaXMubWF0Y2hFbGVtZW50KGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLmFkZEVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc1JlbW92ZWROb2Rlcyhub2Rlcykge1xuICAgICAgICBmb3IgKGNvbnN0IG5vZGUgb2YgQXJyYXkuZnJvbShub2RlcykpIHtcbiAgICAgICAgICAgIGNvbnN0IGVsZW1lbnQgPSB0aGlzLmVsZW1lbnRGcm9tTm9kZShub2RlKTtcbiAgICAgICAgICAgIGlmIChlbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzVHJlZShlbGVtZW50LCB0aGlzLnJlbW92ZUVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NBZGRlZE5vZGVzKG5vZGVzKSB7XG4gICAgICAgIGZvciAoY29uc3Qgbm9kZSBvZiBBcnJheS5mcm9tKG5vZGVzKSkge1xuICAgICAgICAgICAgY29uc3QgZWxlbWVudCA9IHRoaXMuZWxlbWVudEZyb21Ob2RlKG5vZGUpO1xuICAgICAgICAgICAgaWYgKGVsZW1lbnQgJiYgdGhpcy5lbGVtZW50SXNBY3RpdmUoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnByb2Nlc3NUcmVlKGVsZW1lbnQsIHRoaXMuYWRkRWxlbWVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVsZWdhdGUubWF0Y2hFbGVtZW50KGVsZW1lbnQpO1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUgPSB0aGlzLmVsZW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVsZWdhdGUubWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKTtcbiAgICB9XG4gICAgcHJvY2Vzc1RyZWUodHJlZSwgcHJvY2Vzc29yKSB7XG4gICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiB0aGlzLm1hdGNoRWxlbWVudHNJblRyZWUodHJlZSkpIHtcbiAgICAgICAgICAgIHByb2Nlc3Nvci5jYWxsKHRoaXMsIGVsZW1lbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRGcm9tTm9kZShub2RlKSB7XG4gICAgICAgIGlmIChub2RlLm5vZGVUeXBlID09IE5vZGUuRUxFTUVOVF9OT0RFKSB7XG4gICAgICAgICAgICByZXR1cm4gbm9kZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50SXNBY3RpdmUoZWxlbWVudCkge1xuICAgICAgICBpZiAoZWxlbWVudC5pc0Nvbm5lY3RlZCAhPSB0aGlzLmVsZW1lbnQuaXNDb25uZWN0ZWQpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnQuY29udGFpbnMoZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgYWRkRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGlmICghdGhpcy5lbGVtZW50cy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIGlmICh0aGlzLmVsZW1lbnRJc0FjdGl2ZShlbGVtZW50KSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZWxlbWVudHMuYWRkKGVsZW1lbnQpO1xuICAgICAgICAgICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkKSB7XG4gICAgICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWQoZWxlbWVudCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHJlbW92ZUVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBpZiAodGhpcy5lbGVtZW50cy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuZWxlbWVudHMuZGVsZXRlKGVsZW1lbnQpO1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZCkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZChlbGVtZW50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbn1cblxuY2xhc3MgQXR0cmlidXRlT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlTmFtZSA9IGF0dHJpYnV0ZU5hbWU7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIgPSBuZXcgRWxlbWVudE9ic2VydmVyKGVsZW1lbnQsIHRoaXMpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudE9ic2VydmVyLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzZWxlY3RvcigpIHtcbiAgICAgICAgcmV0dXJuIGBbJHt0aGlzLmF0dHJpYnV0ZU5hbWV9XWA7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5wYXVzZShjYWxsYmFjayk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBnZXQgc3RhcnRlZCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0ZWQ7XG4gICAgfVxuICAgIG1hdGNoRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIHJldHVybiBlbGVtZW50Lmhhc0F0dHJpYnV0ZSh0aGlzLmF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUpIHtcbiAgICAgICAgY29uc3QgbWF0Y2ggPSB0aGlzLm1hdGNoRWxlbWVudCh0cmVlKSA/IFt0cmVlXSA6IFtdO1xuICAgICAgICBjb25zdCBtYXRjaGVzID0gQXJyYXkuZnJvbSh0cmVlLnF1ZXJ5U2VsZWN0b3JBbGwodGhpcy5zZWxlY3RvcikpO1xuICAgICAgICByZXR1cm4gbWF0Y2guY29uY2F0KG1hdGNoZXMpO1xuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQsIHRoaXMuYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZEF0dHJpYnV0ZShlbGVtZW50LCB0aGlzLmF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZVZhbHVlQ2hhbmdlZCAmJiB0aGlzLmF0dHJpYnV0ZU5hbWUgPT0gYXR0cmlidXRlTmFtZSkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxufVxuXG5mdW5jdGlvbiBhZGQobWFwLCBrZXksIHZhbHVlKSB7XG4gICAgZmV0Y2gobWFwLCBrZXkpLmFkZCh2YWx1ZSk7XG59XG5mdW5jdGlvbiBkZWwobWFwLCBrZXksIHZhbHVlKSB7XG4gICAgZmV0Y2gobWFwLCBrZXkpLmRlbGV0ZSh2YWx1ZSk7XG4gICAgcHJ1bmUobWFwLCBrZXkpO1xufVxuZnVuY3Rpb24gZmV0Y2gobWFwLCBrZXkpIHtcbiAgICBsZXQgdmFsdWVzID0gbWFwLmdldChrZXkpO1xuICAgIGlmICghdmFsdWVzKSB7XG4gICAgICAgIHZhbHVlcyA9IG5ldyBTZXQoKTtcbiAgICAgICAgbWFwLnNldChrZXksIHZhbHVlcyk7XG4gICAgfVxuICAgIHJldHVybiB2YWx1ZXM7XG59XG5mdW5jdGlvbiBwcnVuZShtYXAsIGtleSkge1xuICAgIGNvbnN0IHZhbHVlcyA9IG1hcC5nZXQoa2V5KTtcbiAgICBpZiAodmFsdWVzICE9IG51bGwgJiYgdmFsdWVzLnNpemUgPT0gMCkge1xuICAgICAgICBtYXAuZGVsZXRlKGtleSk7XG4gICAgfVxufVxuXG5jbGFzcyBNdWx0aW1hcCB7XG4gICAgY29uc3RydWN0b3IoKSB7XG4gICAgICAgIHRoaXMudmFsdWVzQnlLZXkgPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIGdldCBrZXlzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnZhbHVlc0J5S2V5LmtleXMoKSk7XG4gICAgfVxuICAgIGdldCB2YWx1ZXMoKSB7XG4gICAgICAgIGNvbnN0IHNldHMgPSBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkudmFsdWVzKCkpO1xuICAgICAgICByZXR1cm4gc2V0cy5yZWR1Y2UoKHZhbHVlcywgc2V0KSA9PiB2YWx1ZXMuY29uY2F0KEFycmF5LmZyb20oc2V0KSksIFtdKTtcbiAgICB9XG4gICAgZ2V0IHNpemUoKSB7XG4gICAgICAgIGNvbnN0IHNldHMgPSBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkudmFsdWVzKCkpO1xuICAgICAgICByZXR1cm4gc2V0cy5yZWR1Y2UoKHNpemUsIHNldCkgPT4gc2l6ZSArIHNldC5zaXplLCAwKTtcbiAgICB9XG4gICAgYWRkKGtleSwgdmFsdWUpIHtcbiAgICAgICAgYWRkKHRoaXMudmFsdWVzQnlLZXksIGtleSwgdmFsdWUpO1xuICAgIH1cbiAgICBkZWxldGUoa2V5LCB2YWx1ZSkge1xuICAgICAgICBkZWwodGhpcy52YWx1ZXNCeUtleSwga2V5LCB2YWx1ZSk7XG4gICAgfVxuICAgIGhhcyhrZXksIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHZhbHVlcyA9IHRoaXMudmFsdWVzQnlLZXkuZ2V0KGtleSk7XG4gICAgICAgIHJldHVybiB2YWx1ZXMgIT0gbnVsbCAmJiB2YWx1ZXMuaGFzKHZhbHVlKTtcbiAgICB9XG4gICAgaGFzS2V5KGtleSkge1xuICAgICAgICByZXR1cm4gdGhpcy52YWx1ZXNCeUtleS5oYXMoa2V5KTtcbiAgICB9XG4gICAgaGFzVmFsdWUodmFsdWUpIHtcbiAgICAgICAgY29uc3Qgc2V0cyA9IEFycmF5LmZyb20odGhpcy52YWx1ZXNCeUtleS52YWx1ZXMoKSk7XG4gICAgICAgIHJldHVybiBzZXRzLnNvbWUoKHNldCkgPT4gc2V0Lmhhcyh2YWx1ZSkpO1xuICAgIH1cbiAgICBnZXRWYWx1ZXNGb3JLZXkoa2V5KSB7XG4gICAgICAgIGNvbnN0IHZhbHVlcyA9IHRoaXMudmFsdWVzQnlLZXkuZ2V0KGtleSk7XG4gICAgICAgIHJldHVybiB2YWx1ZXMgPyBBcnJheS5mcm9tKHZhbHVlcykgOiBbXTtcbiAgICB9XG4gICAgZ2V0S2V5c0ZvclZhbHVlKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkpXG4gICAgICAgICAgICAuZmlsdGVyKChbX2tleSwgdmFsdWVzXSkgPT4gdmFsdWVzLmhhcyh2YWx1ZSkpXG4gICAgICAgICAgICAubWFwKChba2V5LCBfdmFsdWVzXSkgPT4ga2V5KTtcbiAgICB9XG59XG5cbmNsYXNzIEluZGV4ZWRNdWx0aW1hcCBleHRlbmRzIE11bHRpbWFwIHtcbiAgICBjb25zdHJ1Y3RvcigpIHtcbiAgICAgICAgc3VwZXIoKTtcbiAgICAgICAgdGhpcy5rZXlzQnlWYWx1ZSA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgZ2V0IHZhbHVlcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5rZXlzQnlWYWx1ZS5rZXlzKCkpO1xuICAgIH1cbiAgICBhZGQoa2V5LCB2YWx1ZSkge1xuICAgICAgICBzdXBlci5hZGQoa2V5LCB2YWx1ZSk7XG4gICAgICAgIGFkZCh0aGlzLmtleXNCeVZhbHVlLCB2YWx1ZSwga2V5KTtcbiAgICB9XG4gICAgZGVsZXRlKGtleSwgdmFsdWUpIHtcbiAgICAgICAgc3VwZXIuZGVsZXRlKGtleSwgdmFsdWUpO1xuICAgICAgICBkZWwodGhpcy5rZXlzQnlWYWx1ZSwgdmFsdWUsIGtleSk7XG4gICAgfVxuICAgIGhhc1ZhbHVlKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmtleXNCeVZhbHVlLmhhcyh2YWx1ZSk7XG4gICAgfVxuICAgIGdldEtleXNGb3JWYWx1ZSh2YWx1ZSkge1xuICAgICAgICBjb25zdCBzZXQgPSB0aGlzLmtleXNCeVZhbHVlLmdldCh2YWx1ZSk7XG4gICAgICAgIHJldHVybiBzZXQgPyBBcnJheS5mcm9tKHNldCkgOiBbXTtcbiAgICB9XG59XG5cbmNsYXNzIFNlbGVjdG9yT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIHNlbGVjdG9yLCBkZWxlZ2F0ZSwgZGV0YWlscykge1xuICAgICAgICB0aGlzLl9zZWxlY3RvciA9IHNlbGVjdG9yO1xuICAgICAgICB0aGlzLmRldGFpbHMgPSBkZXRhaWxzO1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlciA9IG5ldyBFbGVtZW50T2JzZXJ2ZXIoZWxlbWVudCwgdGhpcyk7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5tYXRjaGVzQnlFbGVtZW50ID0gbmV3IE11bHRpbWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RhcnRlZDtcbiAgICB9XG4gICAgZ2V0IHNlbGVjdG9yKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5fc2VsZWN0b3I7XG4gICAgfVxuICAgIHNldCBzZWxlY3RvcihzZWxlY3Rvcikge1xuICAgICAgICB0aGlzLl9zZWxlY3RvciA9IHNlbGVjdG9yO1xuICAgICAgICB0aGlzLnJlZnJlc2goKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHBhdXNlKGNhbGxiYWNrKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnBhdXNlKGNhbGxiYWNrKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgeyBzZWxlY3RvciB9ID0gdGhpcztcbiAgICAgICAgaWYgKHNlbGVjdG9yKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gZWxlbWVudC5tYXRjaGVzKHNlbGVjdG9yKTtcbiAgICAgICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLnNlbGVjdG9yTWF0Y2hFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIG1hdGNoZXMgJiYgdGhpcy5kZWxlZ2F0ZS5zZWxlY3Rvck1hdGNoRWxlbWVudChlbGVtZW50LCB0aGlzLmRldGFpbHMpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmV0dXJuIG1hdGNoZXM7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2ggPSB0aGlzLm1hdGNoRWxlbWVudCh0cmVlKSA/IFt0cmVlXSA6IFtdO1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlcyA9IEFycmF5LmZyb20odHJlZS5xdWVyeVNlbGVjdG9yQWxsKHNlbGVjdG9yKSkuZmlsdGVyKChtYXRjaCkgPT4gdGhpcy5tYXRjaEVsZW1lbnQobWF0Y2gpKTtcbiAgICAgICAgICAgIHJldHVybiBtYXRjaC5jb25jYXQobWF0Y2hlcyk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gW107XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCB7IHNlbGVjdG9yIH0gPSB0aGlzO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkKGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3JzID0gdGhpcy5tYXRjaGVzQnlFbGVtZW50LmdldEtleXNGb3JWYWx1ZShlbGVtZW50KTtcbiAgICAgICAgZm9yIChjb25zdCBzZWxlY3RvciBvZiBzZWxlY3RvcnMpIHtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIF9hdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlcyA9IHRoaXMubWF0Y2hFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlZEJlZm9yZSA9IHRoaXMubWF0Y2hlc0J5RWxlbWVudC5oYXMoc2VsZWN0b3IsIGVsZW1lbnQpO1xuICAgICAgICAgICAgaWYgKG1hdGNoZXMgJiYgIW1hdGNoZWRCZWZvcmUpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnNlbGVjdG9yTWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIGlmICghbWF0Y2hlcyAmJiBtYXRjaGVkQmVmb3JlKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUuc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yLCB0aGlzLmRldGFpbHMpO1xuICAgICAgICB0aGlzLm1hdGNoZXNCeUVsZW1lbnQuYWRkKHNlbGVjdG9yLCBlbGVtZW50KTtcbiAgICB9XG4gICAgc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpIHtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3RvciwgdGhpcy5kZXRhaWxzKTtcbiAgICAgICAgdGhpcy5tYXRjaGVzQnlFbGVtZW50LmRlbGV0ZShzZWxlY3RvciwgZWxlbWVudCk7XG4gICAgfVxufVxuXG5jbGFzcyBTdHJpbmdNYXBPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXAgPSBuZXcgTWFwKCk7XG4gICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlciA9IG5ldyBNdXRhdGlvbk9ic2VydmVyKChtdXRhdGlvbnMpID0+IHRoaXMucHJvY2Vzc011dGF0aW9ucyhtdXRhdGlvbnMpKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLm9ic2VydmUodGhpcy5lbGVtZW50LCB7IGF0dHJpYnV0ZXM6IHRydWUsIGF0dHJpYnV0ZU9sZFZhbHVlOiB0cnVlIH0pO1xuICAgICAgICAgICAgdGhpcy5yZWZyZXNoKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLnRha2VSZWNvcmRzKCk7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgZm9yIChjb25zdCBhdHRyaWJ1dGVOYW1lIG9mIHRoaXMua25vd25BdHRyaWJ1dGVOYW1lcykge1xuICAgICAgICAgICAgICAgIHRoaXMucmVmcmVzaEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lLCBudWxsKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IG11dGF0aW9uIG9mIG11dGF0aW9ucykge1xuICAgICAgICAgICAgICAgIHRoaXMucHJvY2Vzc011dGF0aW9uKG11dGF0aW9uKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzTXV0YXRpb24obXV0YXRpb24pIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IG11dGF0aW9uLmF0dHJpYnV0ZU5hbWU7XG4gICAgICAgIGlmIChhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgICAgICB0aGlzLnJlZnJlc2hBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSwgbXV0YXRpb24ub2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2hBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3Qga2V5ID0gdGhpcy5kZWxlZ2F0ZS5nZXRTdHJpbmdNYXBLZXlGb3JBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIGlmIChrZXkgIT0gbnVsbCkge1xuICAgICAgICAgICAgaWYgKCF0aGlzLnN0cmluZ01hcC5oYXMoYXR0cmlidXRlTmFtZSkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjb25zdCB2YWx1ZSA9IHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICBpZiAodGhpcy5zdHJpbmdNYXAuZ2V0KGF0dHJpYnV0ZU5hbWUpICE9IHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zdHJpbmdNYXBWYWx1ZUNoYW5nZWQodmFsdWUsIGtleSwgb2xkVmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKHZhbHVlID09IG51bGwpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvbGRWYWx1ZSA9IHRoaXMuc3RyaW5nTWFwLmdldChhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcC5kZWxldGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG9sZFZhbHVlKVxuICAgICAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcC5zZXQoYXR0cmlidXRlTmFtZSwgdmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlBZGRlZCkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlBZGRlZChrZXksIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwga2V5LCBvbGRWYWx1ZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBWYWx1ZUNoYW5nZWQpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwVmFsdWVDaGFuZ2VkKHZhbHVlLCBrZXksIG9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBLZXlSZW1vdmVkKGtleSwgYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwS2V5UmVtb3ZlZCkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlSZW1vdmVkKGtleSwgYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBrbm93bkF0dHJpYnV0ZU5hbWVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbShuZXcgU2V0KHRoaXMuY3VycmVudEF0dHJpYnV0ZU5hbWVzLmNvbmNhdCh0aGlzLnJlY29yZGVkQXR0cmlidXRlTmFtZXMpKSk7XG4gICAgfVxuICAgIGdldCBjdXJyZW50QXR0cmlidXRlTmFtZXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuZWxlbWVudC5hdHRyaWJ1dGVzKS5tYXAoKGF0dHJpYnV0ZSkgPT4gYXR0cmlidXRlLm5hbWUpO1xuICAgIH1cbiAgICBnZXQgcmVjb3JkZWRBdHRyaWJ1dGVOYW1lcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5zdHJpbmdNYXAua2V5cygpKTtcbiAgICB9XG59XG5cbmNsYXNzIFRva2VuTGlzdE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyID0gbmV3IEF0dHJpYnV0ZU9ic2VydmVyKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudG9rZW5zQnlFbGVtZW50ID0gbmV3IE11bHRpbWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLnBhdXNlKGNhbGxiYWNrKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGF0dHJpYnV0ZU5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLmF0dHJpYnV0ZU5hbWU7XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQpIHtcbiAgICAgICAgdGhpcy50b2tlbnNNYXRjaGVkKHRoaXMucmVhZFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCkpO1xuICAgIH1cbiAgICBlbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgW3VubWF0Y2hlZFRva2VucywgbWF0Y2hlZFRva2Vuc10gPSB0aGlzLnJlZnJlc2hUb2tlbnNGb3JFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICB0aGlzLnRva2Vuc1VubWF0Y2hlZCh1bm1hdGNoZWRUb2tlbnMpO1xuICAgICAgICB0aGlzLnRva2Vuc01hdGNoZWQobWF0Y2hlZFRva2Vucyk7XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUoZWxlbWVudCkge1xuICAgICAgICB0aGlzLnRva2Vuc1VubWF0Y2hlZCh0aGlzLnRva2Vuc0J5RWxlbWVudC5nZXRWYWx1ZXNGb3JLZXkoZWxlbWVudCkpO1xuICAgIH1cbiAgICB0b2tlbnNNYXRjaGVkKHRva2Vucykge1xuICAgICAgICB0b2tlbnMuZm9yRWFjaCgodG9rZW4pID0+IHRoaXMudG9rZW5NYXRjaGVkKHRva2VuKSk7XG4gICAgfVxuICAgIHRva2Vuc1VubWF0Y2hlZCh0b2tlbnMpIHtcbiAgICAgICAgdG9rZW5zLmZvckVhY2goKHRva2VuKSA9PiB0aGlzLnRva2VuVW5tYXRjaGVkKHRva2VuKSk7XG4gICAgfVxuICAgIHRva2VuTWF0Y2hlZCh0b2tlbikge1xuICAgICAgICB0aGlzLmRlbGVnYXRlLnRva2VuTWF0Y2hlZCh0b2tlbik7XG4gICAgICAgIHRoaXMudG9rZW5zQnlFbGVtZW50LmFkZCh0b2tlbi5lbGVtZW50LCB0b2tlbik7XG4gICAgfVxuICAgIHRva2VuVW5tYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUudG9rZW5Vbm1hdGNoZWQodG9rZW4pO1xuICAgICAgICB0aGlzLnRva2Vuc0J5RWxlbWVudC5kZWxldGUodG9rZW4uZWxlbWVudCwgdG9rZW4pO1xuICAgIH1cbiAgICByZWZyZXNoVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGNvbnN0IHByZXZpb3VzVG9rZW5zID0gdGhpcy50b2tlbnNCeUVsZW1lbnQuZ2V0VmFsdWVzRm9yS2V5KGVsZW1lbnQpO1xuICAgICAgICBjb25zdCBjdXJyZW50VG9rZW5zID0gdGhpcy5yZWFkVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgY29uc3QgZmlyc3REaWZmZXJpbmdJbmRleCA9IHppcChwcmV2aW91c1Rva2VucywgY3VycmVudFRva2VucykuZmluZEluZGV4KChbcHJldmlvdXNUb2tlbiwgY3VycmVudFRva2VuXSkgPT4gIXRva2Vuc0FyZUVxdWFsKHByZXZpb3VzVG9rZW4sIGN1cnJlbnRUb2tlbikpO1xuICAgICAgICBpZiAoZmlyc3REaWZmZXJpbmdJbmRleCA9PSAtMSkge1xuICAgICAgICAgICAgcmV0dXJuIFtbXSwgW11dO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIFtwcmV2aW91c1Rva2Vucy5zbGljZShmaXJzdERpZmZlcmluZ0luZGV4KSwgY3VycmVudFRva2Vucy5zbGljZShmaXJzdERpZmZlcmluZ0luZGV4KV07XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVhZFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5hdHRyaWJ1dGVOYW1lO1xuICAgICAgICBjb25zdCB0b2tlblN0cmluZyA9IGVsZW1lbnQuZ2V0QXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpIHx8IFwiXCI7XG4gICAgICAgIHJldHVybiBwYXJzZVRva2VuU3RyaW5nKHRva2VuU3RyaW5nLCBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKTtcbiAgICB9XG59XG5mdW5jdGlvbiBwYXJzZVRva2VuU3RyaW5nKHRva2VuU3RyaW5nLCBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgcmV0dXJuIHRva2VuU3RyaW5nXG4gICAgICAgIC50cmltKClcbiAgICAgICAgLnNwbGl0KC9cXHMrLylcbiAgICAgICAgLmZpbHRlcigoY29udGVudCkgPT4gY29udGVudC5sZW5ndGgpXG4gICAgICAgIC5tYXAoKGNvbnRlbnQsIGluZGV4KSA9PiAoeyBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBjb250ZW50LCBpbmRleCB9KSk7XG59XG5mdW5jdGlvbiB6aXAobGVmdCwgcmlnaHQpIHtcbiAgICBjb25zdCBsZW5ndGggPSBNYXRoLm1heChsZWZ0Lmxlbmd0aCwgcmlnaHQubGVuZ3RoKTtcbiAgICByZXR1cm4gQXJyYXkuZnJvbSh7IGxlbmd0aCB9LCAoXywgaW5kZXgpID0+IFtsZWZ0W2luZGV4XSwgcmlnaHRbaW5kZXhdXSk7XG59XG5mdW5jdGlvbiB0b2tlbnNBcmVFcXVhbChsZWZ0LCByaWdodCkge1xuICAgIHJldHVybiBsZWZ0ICYmIHJpZ2h0ICYmIGxlZnQuaW5kZXggPT0gcmlnaHQuaW5kZXggJiYgbGVmdC5jb250ZW50ID09IHJpZ2h0LmNvbnRlbnQ7XG59XG5cbmNsYXNzIFZhbHVlTGlzdE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyID0gbmV3IFRva2VuTGlzdE9ic2VydmVyKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMucGFyc2VSZXN1bHRzQnlUb2tlbiA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudCA9IG5ldyBXZWFrTWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy50b2tlbkxpc3RPYnNlcnZlci5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgYXR0cmlidXRlTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuYXR0cmlidXRlTmFtZTtcbiAgICB9XG4gICAgdG9rZW5NYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdG9rZW47XG4gICAgICAgIGNvbnN0IHsgdmFsdWUgfSA9IHRoaXMuZmV0Y2hQYXJzZVJlc3VsdEZvclRva2VuKHRva2VuKTtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICB0aGlzLmZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkuc2V0KHRva2VuLCB2YWx1ZSk7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHRva2VuVW5tYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdG9rZW47XG4gICAgICAgIGNvbnN0IHsgdmFsdWUgfSA9IHRoaXMuZmV0Y2hQYXJzZVJlc3VsdEZvclRva2VuKHRva2VuKTtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICB0aGlzLmZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkuZGVsZXRlKHRva2VuKTtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZFZhbHVlKGVsZW1lbnQsIHZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBmZXRjaFBhcnNlUmVzdWx0Rm9yVG9rZW4odG9rZW4pIHtcbiAgICAgICAgbGV0IHBhcnNlUmVzdWx0ID0gdGhpcy5wYXJzZVJlc3VsdHNCeVRva2VuLmdldCh0b2tlbik7XG4gICAgICAgIGlmICghcGFyc2VSZXN1bHQpIHtcbiAgICAgICAgICAgIHBhcnNlUmVzdWx0ID0gdGhpcy5wYXJzZVRva2VuKHRva2VuKTtcbiAgICAgICAgICAgIHRoaXMucGFyc2VSZXN1bHRzQnlUb2tlbi5zZXQodG9rZW4sIHBhcnNlUmVzdWx0KTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFyc2VSZXN1bHQ7XG4gICAgfVxuICAgIGZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBsZXQgdmFsdWVzQnlUb2tlbiA9IHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudC5nZXQoZWxlbWVudCk7XG4gICAgICAgIGlmICghdmFsdWVzQnlUb2tlbikge1xuICAgICAgICAgICAgdmFsdWVzQnlUb2tlbiA9IG5ldyBNYXAoKTtcbiAgICAgICAgICAgIHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudC5zZXQoZWxlbWVudCwgdmFsdWVzQnlUb2tlbik7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHZhbHVlc0J5VG9rZW47XG4gICAgfVxuICAgIHBhcnNlVG9rZW4odG9rZW4pIHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gdGhpcy5kZWxlZ2F0ZS5wYXJzZVZhbHVlRm9yVG9rZW4odG9rZW4pO1xuICAgICAgICAgICAgcmV0dXJuIHsgdmFsdWUgfTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHJldHVybiB7IGVycm9yIH07XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmNsYXNzIEJpbmRpbmdPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24gPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIgPSBuZXcgVmFsdWVMaXN0T2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLmFjdGlvbkF0dHJpYnV0ZSwgdGhpcyk7XG4gICAgICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICAgICAgZGVsZXRlIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXI7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBbGxBY3Rpb25zKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IGFjdGlvbkF0dHJpYnV0ZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmFjdGlvbkF0dHJpYnV0ZTtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY2hlbWE7XG4gICAgfVxuICAgIGdldCBiaW5kaW5ncygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5iaW5kaW5nc0J5QWN0aW9uLnZhbHVlcygpKTtcbiAgICB9XG4gICAgY29ubmVjdEFjdGlvbihhY3Rpb24pIHtcbiAgICAgICAgY29uc3QgYmluZGluZyA9IG5ldyBCaW5kaW5nKHRoaXMuY29udGV4dCwgYWN0aW9uKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nc0J5QWN0aW9uLnNldChhY3Rpb24sIGJpbmRpbmcpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlLmJpbmRpbmdDb25uZWN0ZWQoYmluZGluZyk7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RBY3Rpb24oYWN0aW9uKSB7XG4gICAgICAgIGNvbnN0IGJpbmRpbmcgPSB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uZ2V0KGFjdGlvbik7XG4gICAgICAgIGlmIChiaW5kaW5nKSB7XG4gICAgICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uZGVsZXRlKGFjdGlvbik7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZyk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZGlzY29ubmVjdEFsbEFjdGlvbnMoKSB7XG4gICAgICAgIHRoaXMuYmluZGluZ3MuZm9yRWFjaCgoYmluZGluZykgPT4gdGhpcy5kZWxlZ2F0ZS5iaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcsIHRydWUpKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nc0J5QWN0aW9uLmNsZWFyKCk7XG4gICAgfVxuICAgIHBhcnNlVmFsdWVGb3JUb2tlbih0b2tlbikge1xuICAgICAgICBjb25zdCBhY3Rpb24gPSBBY3Rpb24uZm9yVG9rZW4odG9rZW4sIHRoaXMuc2NoZW1hKTtcbiAgICAgICAgaWYgKGFjdGlvbi5pZGVudGlmaWVyID09IHRoaXMuaWRlbnRpZmllcikge1xuICAgICAgICAgICAgcmV0dXJuIGFjdGlvbjtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZFZhbHVlKGVsZW1lbnQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmNvbm5lY3RBY3Rpb24oYWN0aW9uKTtcbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZFZhbHVlKGVsZW1lbnQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBY3Rpb24oYWN0aW9uKTtcbiAgICB9XG59XG5cbmNsYXNzIFZhbHVlT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIHJlY2VpdmVyKSB7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMucmVjZWl2ZXIgPSByZWNlaXZlcjtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXBPYnNlcnZlciA9IG5ldyBTdHJpbmdNYXBPYnNlcnZlcih0aGlzLmVsZW1lbnQsIHRoaXMpO1xuICAgICAgICB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcCA9IHRoaXMuY29udHJvbGxlci52YWx1ZURlc2NyaXB0b3JNYXA7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLnN0cmluZ01hcE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrc0ZvckRlZmF1bHRWYWx1ZXMoKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXBPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmNvbnRyb2xsZXI7XG4gICAgfVxuICAgIGdldFN0cmluZ01hcEtleUZvckF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmIChhdHRyaWJ1dGVOYW1lIGluIHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwKSB7XG4gICAgICAgICAgICByZXR1cm4gdGhpcy52YWx1ZURlc2NyaXB0b3JNYXBbYXR0cmlidXRlTmFtZV0ubmFtZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBLZXlBZGRlZChrZXksIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwW2F0dHJpYnV0ZU5hbWVdO1xuICAgICAgICBpZiAoIXRoaXMuaGFzVmFsdWUoa2V5KSkge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2soa2V5LCBkZXNjcmlwdG9yLndyaXRlcih0aGlzLnJlY2VpdmVyW2tleV0pLCBkZXNjcmlwdG9yLndyaXRlcihkZXNjcmlwdG9yLmRlZmF1bHRWYWx1ZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwgbmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFtuYW1lXTtcbiAgICAgICAgaWYgKHZhbHVlID09PSBudWxsKVxuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICBpZiAob2xkVmFsdWUgPT09IG51bGwpIHtcbiAgICAgICAgICAgIG9sZFZhbHVlID0gZGVzY3JpcHRvci53cml0ZXIoZGVzY3JpcHRvci5kZWZhdWx0VmFsdWUpO1xuICAgICAgICB9XG4gICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKG5hbWUsIHZhbHVlLCBvbGRWYWx1ZSk7XG4gICAgfVxuICAgIHN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JOYW1lTWFwW2tleV07XG4gICAgICAgIGlmICh0aGlzLmhhc1ZhbHVlKGtleSkpIHtcbiAgICAgICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKGtleSwgZGVzY3JpcHRvci53cml0ZXIodGhpcy5yZWNlaXZlcltrZXldKSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2soa2V5LCBkZXNjcmlwdG9yLndyaXRlcihkZXNjcmlwdG9yLmRlZmF1bHRWYWx1ZSksIG9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBpbnZva2VDaGFuZ2VkQ2FsbGJhY2tzRm9yRGVmYXVsdFZhbHVlcygpIHtcbiAgICAgICAgZm9yIChjb25zdCB7IGtleSwgbmFtZSwgZGVmYXVsdFZhbHVlLCB3cml0ZXIgfSBvZiB0aGlzLnZhbHVlRGVzY3JpcHRvcnMpIHtcbiAgICAgICAgICAgIGlmIChkZWZhdWx0VmFsdWUgIT0gdW5kZWZpbmVkICYmICF0aGlzLmNvbnRyb2xsZXIuZGF0YS5oYXMoa2V5KSkge1xuICAgICAgICAgICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKG5hbWUsIHdyaXRlcihkZWZhdWx0VmFsdWUpLCB1bmRlZmluZWQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGludm9rZUNoYW5nZWRDYWxsYmFjayhuYW1lLCByYXdWYWx1ZSwgcmF3T2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3QgY2hhbmdlZE1ldGhvZE5hbWUgPSBgJHtuYW1lfUNoYW5nZWRgO1xuICAgICAgICBjb25zdCBjaGFuZ2VkTWV0aG9kID0gdGhpcy5yZWNlaXZlcltjaGFuZ2VkTWV0aG9kTmFtZV07XG4gICAgICAgIGlmICh0eXBlb2YgY2hhbmdlZE1ldGhvZCA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck5hbWVNYXBbbmFtZV07XG4gICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gZGVzY3JpcHRvci5yZWFkZXIocmF3VmFsdWUpO1xuICAgICAgICAgICAgICAgIGxldCBvbGRWYWx1ZSA9IHJhd09sZFZhbHVlO1xuICAgICAgICAgICAgICAgIGlmIChyYXdPbGRWYWx1ZSkge1xuICAgICAgICAgICAgICAgICAgICBvbGRWYWx1ZSA9IGRlc2NyaXB0b3IucmVhZGVyKHJhd09sZFZhbHVlKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgY2hhbmdlZE1ldGhvZC5jYWxsKHRoaXMucmVjZWl2ZXIsIHZhbHVlLCBvbGRWYWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgICAgICBpZiAoZXJyb3IgaW5zdGFuY2VvZiBUeXBlRXJyb3IpIHtcbiAgICAgICAgICAgICAgICAgICAgZXJyb3IubWVzc2FnZSA9IGBTdGltdWx1cyBWYWx1ZSBcIiR7dGhpcy5jb250ZXh0LmlkZW50aWZpZXJ9LiR7ZGVzY3JpcHRvci5uYW1lfVwiIC0gJHtlcnJvci5tZXNzYWdlfWA7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHRocm93IGVycm9yO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGdldCB2YWx1ZURlc2NyaXB0b3JzKCkge1xuICAgICAgICBjb25zdCB7IHZhbHVlRGVzY3JpcHRvck1hcCB9ID0gdGhpcztcbiAgICAgICAgcmV0dXJuIE9iamVjdC5rZXlzKHZhbHVlRGVzY3JpcHRvck1hcCkubWFwKChrZXkpID0+IHZhbHVlRGVzY3JpcHRvck1hcFtrZXldKTtcbiAgICB9XG4gICAgZ2V0IHZhbHVlRGVzY3JpcHRvck5hbWVNYXAoKSB7XG4gICAgICAgIGNvbnN0IGRlc2NyaXB0b3JzID0ge307XG4gICAgICAgIE9iamVjdC5rZXlzKHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwKS5mb3JFYWNoKChrZXkpID0+IHtcbiAgICAgICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcFtrZXldO1xuICAgICAgICAgICAgZGVzY3JpcHRvcnNbZGVzY3JpcHRvci5uYW1lXSA9IGRlc2NyaXB0b3I7XG4gICAgICAgIH0pO1xuICAgICAgICByZXR1cm4gZGVzY3JpcHRvcnM7XG4gICAgfVxuICAgIGhhc1ZhbHVlKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFthdHRyaWJ1dGVOYW1lXTtcbiAgICAgICAgY29uc3QgaGFzTWV0aG9kTmFtZSA9IGBoYXMke2NhcGl0YWxpemUoZGVzY3JpcHRvci5uYW1lKX1gO1xuICAgICAgICByZXR1cm4gdGhpcy5yZWNlaXZlcltoYXNNZXRob2ROYW1lXTtcbiAgICB9XG59XG5cbmNsYXNzIFRhcmdldE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3Rvcihjb250ZXh0LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudGFyZ2V0c0J5TmFtZSA9IG5ldyBNdWx0aW1hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnRva2VuTGlzdE9ic2VydmVyKSB7XG4gICAgICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyID0gbmV3IFRva2VuTGlzdE9ic2VydmVyKHRoaXMuZWxlbWVudCwgdGhpcy5hdHRyaWJ1dGVOYW1lLCB0aGlzKTtcbiAgICAgICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy50b2tlbkxpc3RPYnNlcnZlcikge1xuICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0QWxsVGFyZ2V0cygpO1xuICAgICAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgICAgICBkZWxldGUgdGhpcy50b2tlbkxpc3RPYnNlcnZlcjtcbiAgICAgICAgfVxuICAgIH1cbiAgICB0b2tlbk1hdGNoZWQoeyBlbGVtZW50LCBjb250ZW50OiBuYW1lIH0pIHtcbiAgICAgICAgaWYgKHRoaXMuc2NvcGUuY29udGFpbnNFbGVtZW50KGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLmNvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgdG9rZW5Vbm1hdGNoZWQoeyBlbGVtZW50LCBjb250ZW50OiBuYW1lIH0pIHtcbiAgICAgICAgdGhpcy5kaXNjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpO1xuICAgIH1cbiAgICBjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpIHtcbiAgICAgICAgdmFyIF9hO1xuICAgICAgICBpZiAoIXRoaXMudGFyZ2V0c0J5TmFtZS5oYXMobmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMudGFyZ2V0c0J5TmFtZS5hZGQobmFtZSwgZWxlbWVudCk7XG4gICAgICAgICAgICAoX2EgPSB0aGlzLnRva2VuTGlzdE9ic2VydmVyKSA9PT0gbnVsbCB8fCBfYSA9PT0gdm9pZCAwID8gdm9pZCAwIDogX2EucGF1c2UoKCkgPT4gdGhpcy5kZWxlZ2F0ZS50YXJnZXRDb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICh0aGlzLnRhcmdldHNCeU5hbWUuaGFzKG5hbWUsIGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLnRhcmdldHNCeU5hbWUuZGVsZXRlKG5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy50b2tlbkxpc3RPYnNlcnZlcikgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUudGFyZ2V0RGlzY29ubmVjdGVkKGVsZW1lbnQsIG5hbWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0QWxsVGFyZ2V0cygpIHtcbiAgICAgICAgZm9yIChjb25zdCBuYW1lIG9mIHRoaXMudGFyZ2V0c0J5TmFtZS5rZXlzKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgdGhpcy50YXJnZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShuYW1lKSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdFRhcmdldChlbGVtZW50LCBuYW1lKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgYXR0cmlidXRlTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIGBkYXRhLSR7dGhpcy5jb250ZXh0LmlkZW50aWZpZXJ9LXRhcmdldGA7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzY29wZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY29wZTtcbiAgICB9XG59XG5cbmZ1bmN0aW9uIHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBhbmNlc3RvcnMgPSBnZXRBbmNlc3RvcnNGb3JDb25zdHJ1Y3Rvcihjb25zdHJ1Y3Rvcik7XG4gICAgcmV0dXJuIEFycmF5LmZyb20oYW5jZXN0b3JzLnJlZHVjZSgodmFsdWVzLCBjb25zdHJ1Y3RvcikgPT4ge1xuICAgICAgICBnZXRPd25TdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgcHJvcGVydHlOYW1lKS5mb3JFYWNoKChuYW1lKSA9PiB2YWx1ZXMuYWRkKG5hbWUpKTtcbiAgICAgICAgcmV0dXJuIHZhbHVlcztcbiAgICB9LCBuZXcgU2V0KCkpKTtcbn1cbmZ1bmN0aW9uIHJlYWRJbmhlcml0YWJsZVN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBhbmNlc3RvcnMgPSBnZXRBbmNlc3RvcnNGb3JDb25zdHJ1Y3Rvcihjb25zdHJ1Y3Rvcik7XG4gICAgcmV0dXJuIGFuY2VzdG9ycy5yZWR1Y2UoKHBhaXJzLCBjb25zdHJ1Y3RvcikgPT4ge1xuICAgICAgICBwYWlycy5wdXNoKC4uLmdldE93blN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpKTtcbiAgICAgICAgcmV0dXJuIHBhaXJzO1xuICAgIH0sIFtdKTtcbn1cbmZ1bmN0aW9uIGdldEFuY2VzdG9yc0ZvckNvbnN0cnVjdG9yKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgYW5jZXN0b3JzID0gW107XG4gICAgd2hpbGUgKGNvbnN0cnVjdG9yKSB7XG4gICAgICAgIGFuY2VzdG9ycy5wdXNoKGNvbnN0cnVjdG9yKTtcbiAgICAgICAgY29uc3RydWN0b3IgPSBPYmplY3QuZ2V0UHJvdG90eXBlT2YoY29uc3RydWN0b3IpO1xuICAgIH1cbiAgICByZXR1cm4gYW5jZXN0b3JzLnJldmVyc2UoKTtcbn1cbmZ1bmN0aW9uIGdldE93blN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBkZWZpbml0aW9uID0gY29uc3RydWN0b3JbcHJvcGVydHlOYW1lXTtcbiAgICByZXR1cm4gQXJyYXkuaXNBcnJheShkZWZpbml0aW9uKSA/IGRlZmluaXRpb24gOiBbXTtcbn1cbmZ1bmN0aW9uIGdldE93blN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBkZWZpbml0aW9uID0gY29uc3RydWN0b3JbcHJvcGVydHlOYW1lXTtcbiAgICByZXR1cm4gZGVmaW5pdGlvbiA/IE9iamVjdC5rZXlzKGRlZmluaXRpb24pLm1hcCgoa2V5KSA9PiBba2V5LCBkZWZpbml0aW9uW2tleV1dKSA6IFtdO1xufVxuXG5jbGFzcyBPdXRsZXRPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5vdXRsZXRzQnlOYW1lID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUgPSBuZXcgTXVsdGltYXAoKTtcbiAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwID0gbmV3IE1hcCgpO1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwID0gbmV3IE1hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0RGVmaW5pdGlvbnMuZm9yRWFjaCgob3V0bGV0TmFtZSkgPT4ge1xuICAgICAgICAgICAgICAgIHRoaXMuc2V0dXBTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICAgICAgICAgIHRoaXMuc2V0dXBBdHRyaWJ1dGVPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgICAgIHRoaXMuZGVwZW5kZW50Q29udGV4dHMuZm9yRWFjaCgoY29udGV4dCkgPT4gY29udGV4dC5yZWZyZXNoKCkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIucmVmcmVzaCgpKTtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIucmVmcmVzaCgpKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBbGxPdXRsZXRzKCk7XG4gICAgICAgICAgICB0aGlzLnN0b3BTZWxlY3Rvck9ic2VydmVycygpO1xuICAgICAgICAgICAgdGhpcy5zdG9wQXR0cmlidXRlT2JzZXJ2ZXJzKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcFNlbGVjdG9yT2JzZXJ2ZXJzKCkge1xuICAgICAgICBpZiAodGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLnNpemUgPiAwKSB7XG4gICAgICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuZm9yRWFjaCgob2JzZXJ2ZXIpID0+IG9ic2VydmVyLnN0b3AoKSk7XG4gICAgICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuY2xlYXIoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wQXR0cmlidXRlT2JzZXJ2ZXJzKCkge1xuICAgICAgICBpZiAodGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5zaXplID4gMCkge1xuICAgICAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIuc3RvcCgpKTtcbiAgICAgICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuY2xlYXIoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzZWxlY3Rvck1hdGNoZWQoZWxlbWVudCwgX3NlbGVjdG9yLCB7IG91dGxldE5hbWUgfSkge1xuICAgICAgICBjb25zdCBvdXRsZXQgPSB0aGlzLmdldE91dGxldChlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKG91dGxldCkge1xuICAgICAgICAgICAgdGhpcy5jb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgX3NlbGVjdG9yLCB7IG91dGxldE5hbWUgfSkge1xuICAgICAgICBjb25zdCBvdXRsZXQgPSB0aGlzLmdldE91dGxldEZyb21NYXAoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIGlmIChvdXRsZXQpIHtcbiAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdE91dGxldChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHNlbGVjdG9yTWF0Y2hFbGVtZW50KGVsZW1lbnQsIHsgb3V0bGV0TmFtZSB9KSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5zZWxlY3RvcihvdXRsZXROYW1lKTtcbiAgICAgICAgY29uc3QgaGFzT3V0bGV0ID0gdGhpcy5oYXNPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IGhhc091dGxldENvbnRyb2xsZXIgPSBlbGVtZW50Lm1hdGNoZXMoYFske3RoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGV9fj0ke291dGxldE5hbWV9XWApO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIHJldHVybiBoYXNPdXRsZXQgJiYgaGFzT3V0bGV0Q29udHJvbGxlciAmJiBlbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkQXR0cmlidXRlKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBjb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICghdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5oYXMob3V0bGV0TmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0c0J5TmFtZS5hZGQob3V0bGV0TmFtZSwgb3V0bGV0KTtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuYWRkKG91dGxldE5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmdldChvdXRsZXROYW1lKSkgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUub3V0bGV0Q29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgaWYgKHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuaGFzKG91dGxldE5hbWUsIGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLm91dGxldHNCeU5hbWUuZGVsZXRlKG91dGxldE5hbWUsIG91dGxldCk7XG4gICAgICAgICAgICB0aGlzLm91dGxldEVsZW1lbnRzQnlOYW1lLmRlbGV0ZShvdXRsZXROYW1lLCBlbGVtZW50KTtcbiAgICAgICAgICAgIChfYSA9IHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcFxuICAgICAgICAgICAgICAgIC5nZXQob3V0bGV0TmFtZSkpID09PSBudWxsIHx8IF9hID09PSB2b2lkIDAgPyB2b2lkIDAgOiBfYS5wYXVzZSgoKSA9PiB0aGlzLmRlbGVnYXRlLm91dGxldERpc2Nvbm5lY3RlZChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0QWxsT3V0bGV0cygpIHtcbiAgICAgICAgZm9yIChjb25zdCBvdXRsZXROYW1lIG9mIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUua2V5cykge1xuICAgICAgICAgICAgZm9yIChjb25zdCBlbGVtZW50IG9mIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuZ2V0VmFsdWVzRm9yS2V5KG91dGxldE5hbWUpKSB7XG4gICAgICAgICAgICAgICAgZm9yIChjb25zdCBvdXRsZXQgb2YgdGhpcy5vdXRsZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShvdXRsZXROYW1lKSkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgdXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IG9ic2VydmVyID0gdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmdldChvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKG9ic2VydmVyKSB7XG4gICAgICAgICAgICBvYnNlcnZlci5zZWxlY3RvciA9IHRoaXMuc2VsZWN0b3Iob3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2V0dXBTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLnNlbGVjdG9yKG91dGxldE5hbWUpO1xuICAgICAgICBjb25zdCBzZWxlY3Rvck9ic2VydmVyID0gbmV3IFNlbGVjdG9yT2JzZXJ2ZXIoZG9jdW1lbnQuYm9keSwgc2VsZWN0b3IsIHRoaXMsIHsgb3V0bGV0TmFtZSB9KTtcbiAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLnNldChvdXRsZXROYW1lLCBzZWxlY3Rvck9ic2VydmVyKTtcbiAgICAgICAgc2VsZWN0b3JPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzZXR1cEF0dHJpYnV0ZU9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuYXR0cmlidXRlTmFtZUZvck91dGxldE5hbWUob3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU9ic2VydmVyID0gbmV3IEF0dHJpYnV0ZU9ic2VydmVyKHRoaXMuc2NvcGUuZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgdGhpcyk7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuc2V0KG91dGxldE5hbWUsIGF0dHJpYnV0ZU9ic2VydmVyKTtcbiAgICAgICAgYXR0cmlidXRlT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgc2VsZWN0b3Iob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5vdXRsZXRzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgYXR0cmlidXRlTmFtZUZvck91dGxldE5hbWUob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5zY2hlbWEub3V0bGV0QXR0cmlidXRlRm9yU2NvcGUodGhpcy5pZGVudGlmaWVyLCBvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgZ2V0T3V0bGV0TmFtZUZyb21PdXRsZXRBdHRyaWJ1dGVOYW1lKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0RGVmaW5pdGlvbnMuZmluZCgob3V0bGV0TmFtZSkgPT4gdGhpcy5hdHRyaWJ1dGVOYW1lRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKSA9PT0gYXR0cmlidXRlTmFtZSk7XG4gICAgfVxuICAgIGdldCBvdXRsZXREZXBlbmRlbmNpZXMoKSB7XG4gICAgICAgIGNvbnN0IGRlcGVuZGVuY2llcyA9IG5ldyBNdWx0aW1hcCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5tb2R1bGVzLmZvckVhY2goKG1vZHVsZSkgPT4ge1xuICAgICAgICAgICAgY29uc3QgY29uc3RydWN0b3IgPSBtb2R1bGUuZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3I7XG4gICAgICAgICAgICBjb25zdCBvdXRsZXRzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwib3V0bGV0c1wiKTtcbiAgICAgICAgICAgIG91dGxldHMuZm9yRWFjaCgob3V0bGV0KSA9PiBkZXBlbmRlbmNpZXMuYWRkKG91dGxldCwgbW9kdWxlLmlkZW50aWZpZXIpKTtcbiAgICAgICAgfSk7XG4gICAgICAgIHJldHVybiBkZXBlbmRlbmNpZXM7XG4gICAgfVxuICAgIGdldCBvdXRsZXREZWZpbml0aW9ucygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0RGVwZW5kZW5jaWVzLmdldEtleXNGb3JWYWx1ZSh0aGlzLmlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBnZXQgZGVwZW5kZW50Q29udHJvbGxlcklkZW50aWZpZXJzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXREZXBlbmRlbmNpZXMuZ2V0VmFsdWVzRm9yS2V5KHRoaXMuaWRlbnRpZmllcik7XG4gICAgfVxuICAgIGdldCBkZXBlbmRlbnRDb250ZXh0cygpIHtcbiAgICAgICAgY29uc3QgaWRlbnRpZmllcnMgPSB0aGlzLmRlcGVuZGVudENvbnRyb2xsZXJJZGVudGlmaWVycztcbiAgICAgICAgcmV0dXJuIHRoaXMucm91dGVyLmNvbnRleHRzLmZpbHRlcigoY29udGV4dCkgPT4gaWRlbnRpZmllcnMuaW5jbHVkZXMoY29udGV4dC5pZGVudGlmaWVyKSk7XG4gICAgfVxuICAgIGhhc091dGxldChlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiAhIXRoaXMuZ2V0T3V0bGV0KGVsZW1lbnQsIG91dGxldE5hbWUpIHx8ICEhdGhpcy5nZXRPdXRsZXRGcm9tTWFwKGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBnZXRPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5nZXRDb250cm9sbGVyRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgfVxuICAgIGdldE91dGxldEZyb21NYXAoZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShvdXRsZXROYW1lKS5maW5kKChvdXRsZXQpID0+IG91dGxldC5lbGVtZW50ID09PSBlbGVtZW50KTtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IGFwcGxpY2F0aW9uKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgcm91dGVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5yb3V0ZXI7XG4gICAgfVxufVxuXG5jbGFzcyBDb250ZXh0IHtcbiAgICBjb25zdHJ1Y3Rvcihtb2R1bGUsIHNjb3BlKSB7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eSA9IChmdW5jdGlvbk5hbWUsIGRldGFpbCA9IHt9KSA9PiB7XG4gICAgICAgICAgICBjb25zdCB7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXIsIGVsZW1lbnQgfSA9IHRoaXM7XG4gICAgICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9LCBkZXRhaWwpO1xuICAgICAgICAgICAgdGhpcy5hcHBsaWNhdGlvbi5sb2dEZWJ1Z0FjdGl2aXR5KHRoaXMuaWRlbnRpZmllciwgZnVuY3Rpb25OYW1lLCBkZXRhaWwpO1xuICAgICAgICB9O1xuICAgICAgICB0aGlzLm1vZHVsZSA9IG1vZHVsZTtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgICAgICB0aGlzLmNvbnRyb2xsZXIgPSBuZXcgbW9kdWxlLmNvbnRyb2xsZXJDb25zdHJ1Y3Rvcih0aGlzKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nT2JzZXJ2ZXIgPSBuZXcgQmluZGluZ09ic2VydmVyKHRoaXMsIHRoaXMuZGlzcGF0Y2hlcik7XG4gICAgICAgIHRoaXMudmFsdWVPYnNlcnZlciA9IG5ldyBWYWx1ZU9ic2VydmVyKHRoaXMsIHRoaXMuY29udHJvbGxlcik7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIgPSBuZXcgVGFyZ2V0T2JzZXJ2ZXIodGhpcywgdGhpcyk7XG4gICAgICAgIHRoaXMub3V0bGV0T2JzZXJ2ZXIgPSBuZXcgT3V0bGV0T2JzZXJ2ZXIodGhpcywgdGhpcyk7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLmNvbnRyb2xsZXIuaW5pdGlhbGl6ZSgpO1xuICAgICAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiaW5pdGlhbGl6ZVwiKTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHRoaXMuaGFuZGxlRXJyb3IoZXJyb3IsIFwiaW5pdGlhbGl6aW5nIGNvbnRyb2xsZXJcIik7XG4gICAgICAgIH1cbiAgICB9XG4gICAgY29ubmVjdCgpIHtcbiAgICAgICAgdGhpcy5iaW5kaW5nT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy52YWx1ZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB0cnkge1xuICAgICAgICAgICAgdGhpcy5jb250cm9sbGVyLmNvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImNvbm5lY3RcIik7XG4gICAgICAgIH1cbiAgICAgICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICB0aGlzLmhhbmRsZUVycm9yKGVycm9yLCBcImNvbm5lY3RpbmcgY29udHJvbGxlclwiKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLm91dGxldE9ic2VydmVyLnJlZnJlc2goKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIHRoaXMuY29udHJvbGxlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJkaXNjb25uZWN0XCIpO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgdGhpcy5oYW5kbGVFcnJvcihlcnJvciwgXCJkaXNjb25uZWN0aW5nIGNvbnRyb2xsZXJcIik7XG4gICAgICAgIH1cbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLnZhbHVlT2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLmJpbmRpbmdPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGdldCBhcHBsaWNhdGlvbigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMubW9kdWxlLmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMubW9kdWxlLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGRpc3BhdGNoZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmRpc3BhdGNoZXI7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgcGFyZW50RWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5wYXJlbnRFbGVtZW50O1xuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsID0ge30pIHtcbiAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50IH0gPSB0aGlzO1xuICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9LCBkZXRhaWwpO1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBgRXJyb3IgJHttZXNzYWdlfWAsIGRldGFpbCk7XG4gICAgfVxuICAgIHRhcmdldENvbm5lY3RlZChlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHRoaXMuaW52b2tlQ29udHJvbGxlck1ldGhvZChgJHtuYW1lfVRhcmdldENvbm5lY3RlZGAsIGVsZW1lbnQpO1xuICAgIH1cbiAgICB0YXJnZXREaXNjb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZX1UYXJnZXREaXNjb25uZWN0ZWRgLCBlbGVtZW50KTtcbiAgICB9XG4gICAgb3V0bGV0Q29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZXNwYWNlQ2FtZWxpemUobmFtZSl9T3V0bGV0Q29ubmVjdGVkYCwgb3V0bGV0LCBlbGVtZW50KTtcbiAgICB9XG4gICAgb3V0bGV0RGlzY29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZXNwYWNlQ2FtZWxpemUobmFtZSl9T3V0bGV0RGlzY29ubmVjdGVkYCwgb3V0bGV0LCBlbGVtZW50KTtcbiAgICB9XG4gICAgaW52b2tlQ29udHJvbGxlck1ldGhvZChtZXRob2ROYW1lLCAuLi5hcmdzKSB7XG4gICAgICAgIGNvbnN0IGNvbnRyb2xsZXIgPSB0aGlzLmNvbnRyb2xsZXI7XG4gICAgICAgIGlmICh0eXBlb2YgY29udHJvbGxlclttZXRob2ROYW1lXSA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgICAgIGNvbnRyb2xsZXJbbWV0aG9kTmFtZV0oLi4uYXJncyk7XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmZ1bmN0aW9uIGJsZXNzKGNvbnN0cnVjdG9yKSB7XG4gICAgcmV0dXJuIHNoYWRvdyhjb25zdHJ1Y3RvciwgZ2V0Qmxlc3NlZFByb3BlcnRpZXMoY29uc3RydWN0b3IpKTtcbn1cbmZ1bmN0aW9uIHNoYWRvdyhjb25zdHJ1Y3RvciwgcHJvcGVydGllcykge1xuICAgIGNvbnN0IHNoYWRvd0NvbnN0cnVjdG9yID0gZXh0ZW5kKGNvbnN0cnVjdG9yKTtcbiAgICBjb25zdCBzaGFkb3dQcm9wZXJ0aWVzID0gZ2V0U2hhZG93UHJvcGVydGllcyhjb25zdHJ1Y3Rvci5wcm90b3R5cGUsIHByb3BlcnRpZXMpO1xuICAgIE9iamVjdC5kZWZpbmVQcm9wZXJ0aWVzKHNoYWRvd0NvbnN0cnVjdG9yLnByb3RvdHlwZSwgc2hhZG93UHJvcGVydGllcyk7XG4gICAgcmV0dXJuIHNoYWRvd0NvbnN0cnVjdG9yO1xufVxuZnVuY3Rpb24gZ2V0Qmxlc3NlZFByb3BlcnRpZXMoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBibGVzc2luZ3MgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJibGVzc2luZ3NcIik7XG4gICAgcmV0dXJuIGJsZXNzaW5ncy5yZWR1Y2UoKGJsZXNzZWRQcm9wZXJ0aWVzLCBibGVzc2luZykgPT4ge1xuICAgICAgICBjb25zdCBwcm9wZXJ0aWVzID0gYmxlc3NpbmcoY29uc3RydWN0b3IpO1xuICAgICAgICBmb3IgKGNvbnN0IGtleSBpbiBwcm9wZXJ0aWVzKSB7XG4gICAgICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gYmxlc3NlZFByb3BlcnRpZXNba2V5XSB8fCB7fTtcbiAgICAgICAgICAgIGJsZXNzZWRQcm9wZXJ0aWVzW2tleV0gPSBPYmplY3QuYXNzaWduKGRlc2NyaXB0b3IsIHByb3BlcnRpZXNba2V5XSk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGJsZXNzZWRQcm9wZXJ0aWVzO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIGdldFNoYWRvd1Byb3BlcnRpZXMocHJvdG90eXBlLCBwcm9wZXJ0aWVzKSB7XG4gICAgcmV0dXJuIGdldE93bktleXMocHJvcGVydGllcykucmVkdWNlKChzaGFkb3dQcm9wZXJ0aWVzLCBrZXkpID0+IHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IGdldFNoYWRvd2VkRGVzY3JpcHRvcihwcm90b3R5cGUsIHByb3BlcnRpZXMsIGtleSk7XG4gICAgICAgIGlmIChkZXNjcmlwdG9yKSB7XG4gICAgICAgICAgICBPYmplY3QuYXNzaWduKHNoYWRvd1Byb3BlcnRpZXMsIHsgW2tleV06IGRlc2NyaXB0b3IgfSk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHNoYWRvd1Byb3BlcnRpZXM7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gZ2V0U2hhZG93ZWREZXNjcmlwdG9yKHByb3RvdHlwZSwgcHJvcGVydGllcywga2V5KSB7XG4gICAgY29uc3Qgc2hhZG93aW5nRGVzY3JpcHRvciA9IE9iamVjdC5nZXRPd25Qcm9wZXJ0eURlc2NyaXB0b3IocHJvdG90eXBlLCBrZXkpO1xuICAgIGNvbnN0IHNoYWRvd2VkQnlWYWx1ZSA9IHNoYWRvd2luZ0Rlc2NyaXB0b3IgJiYgXCJ2YWx1ZVwiIGluIHNoYWRvd2luZ0Rlc2NyaXB0b3I7XG4gICAgaWYgKCFzaGFkb3dlZEJ5VmFsdWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IE9iamVjdC5nZXRPd25Qcm9wZXJ0eURlc2NyaXB0b3IocHJvcGVydGllcywga2V5KS52YWx1ZTtcbiAgICAgICAgaWYgKHNoYWRvd2luZ0Rlc2NyaXB0b3IpIHtcbiAgICAgICAgICAgIGRlc2NyaXB0b3IuZ2V0ID0gc2hhZG93aW5nRGVzY3JpcHRvci5nZXQgfHwgZGVzY3JpcHRvci5nZXQ7XG4gICAgICAgICAgICBkZXNjcmlwdG9yLnNldCA9IHNoYWRvd2luZ0Rlc2NyaXB0b3Iuc2V0IHx8IGRlc2NyaXB0b3Iuc2V0O1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBkZXNjcmlwdG9yO1xuICAgIH1cbn1cbmNvbnN0IGdldE93bktleXMgPSAoKCkgPT4ge1xuICAgIGlmICh0eXBlb2YgT2JqZWN0LmdldE93blByb3BlcnR5U3ltYm9scyA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgcmV0dXJuIChvYmplY3QpID0+IFsuLi5PYmplY3QuZ2V0T3duUHJvcGVydHlOYW1lcyhvYmplY3QpLCAuLi5PYmplY3QuZ2V0T3duUHJvcGVydHlTeW1ib2xzKG9iamVjdCldO1xuICAgIH1cbiAgICBlbHNlIHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5nZXRPd25Qcm9wZXJ0eU5hbWVzO1xuICAgIH1cbn0pKCk7XG5jb25zdCBleHRlbmQgPSAoKCkgPT4ge1xuICAgIGZ1bmN0aW9uIGV4dGVuZFdpdGhSZWZsZWN0KGNvbnN0cnVjdG9yKSB7XG4gICAgICAgIGZ1bmN0aW9uIGV4dGVuZGVkKCkge1xuICAgICAgICAgICAgcmV0dXJuIFJlZmxlY3QuY29uc3RydWN0KGNvbnN0cnVjdG9yLCBhcmd1bWVudHMsIG5ldy50YXJnZXQpO1xuICAgICAgICB9XG4gICAgICAgIGV4dGVuZGVkLnByb3RvdHlwZSA9IE9iamVjdC5jcmVhdGUoY29uc3RydWN0b3IucHJvdG90eXBlLCB7XG4gICAgICAgICAgICBjb25zdHJ1Y3RvcjogeyB2YWx1ZTogZXh0ZW5kZWQgfSxcbiAgICAgICAgfSk7XG4gICAgICAgIFJlZmxlY3Quc2V0UHJvdG90eXBlT2YoZXh0ZW5kZWQsIGNvbnN0cnVjdG9yKTtcbiAgICAgICAgcmV0dXJuIGV4dGVuZGVkO1xuICAgIH1cbiAgICBmdW5jdGlvbiB0ZXN0UmVmbGVjdEV4dGVuc2lvbigpIHtcbiAgICAgICAgY29uc3QgYSA9IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHRoaXMuYS5jYWxsKHRoaXMpO1xuICAgICAgICB9O1xuICAgICAgICBjb25zdCBiID0gZXh0ZW5kV2l0aFJlZmxlY3QoYSk7XG4gICAgICAgIGIucHJvdG90eXBlLmEgPSBmdW5jdGlvbiAoKSB7IH07XG4gICAgICAgIHJldHVybiBuZXcgYigpO1xuICAgIH1cbiAgICB0cnkge1xuICAgICAgICB0ZXN0UmVmbGVjdEV4dGVuc2lvbigpO1xuICAgICAgICByZXR1cm4gZXh0ZW5kV2l0aFJlZmxlY3Q7XG4gICAgfVxuICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICByZXR1cm4gKGNvbnN0cnVjdG9yKSA9PiBjbGFzcyBleHRlbmRlZCBleHRlbmRzIGNvbnN0cnVjdG9yIHtcbiAgICAgICAgfTtcbiAgICB9XG59KSgpO1xuXG5mdW5jdGlvbiBibGVzc0RlZmluaXRpb24oZGVmaW5pdGlvbikge1xuICAgIHJldHVybiB7XG4gICAgICAgIGlkZW50aWZpZXI6IGRlZmluaXRpb24uaWRlbnRpZmllcixcbiAgICAgICAgY29udHJvbGxlckNvbnN0cnVjdG9yOiBibGVzcyhkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvciksXG4gICAgfTtcbn1cblxuY2xhc3MgTW9kdWxlIHtcbiAgICBjb25zdHJ1Y3RvcihhcHBsaWNhdGlvbiwgZGVmaW5pdGlvbikge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uID0gYXBwbGljYXRpb247XG4gICAgICAgIHRoaXMuZGVmaW5pdGlvbiA9IGJsZXNzRGVmaW5pdGlvbihkZWZpbml0aW9uKTtcbiAgICAgICAgdGhpcy5jb250ZXh0c0J5U2NvcGUgPSBuZXcgV2Vha01hcCgpO1xuICAgICAgICB0aGlzLmNvbm5lY3RlZENvbnRleHRzID0gbmV3IFNldCgpO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVmaW5pdGlvbi5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlckNvbnN0cnVjdG9yKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5kZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvcjtcbiAgICB9XG4gICAgZ2V0IGNvbnRleHRzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmNvbm5lY3RlZENvbnRleHRzKTtcbiAgICB9XG4gICAgY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkge1xuICAgICAgICBjb25zdCBjb250ZXh0ID0gdGhpcy5mZXRjaENvbnRleHRGb3JTY29wZShzY29wZSk7XG4gICAgICAgIHRoaXMuY29ubmVjdGVkQ29udGV4dHMuYWRkKGNvbnRleHQpO1xuICAgICAgICBjb250ZXh0LmNvbm5lY3QoKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkge1xuICAgICAgICBjb25zdCBjb250ZXh0ID0gdGhpcy5jb250ZXh0c0J5U2NvcGUuZ2V0KHNjb3BlKTtcbiAgICAgICAgaWYgKGNvbnRleHQpIHtcbiAgICAgICAgICAgIHRoaXMuY29ubmVjdGVkQ29udGV4dHMuZGVsZXRlKGNvbnRleHQpO1xuICAgICAgICAgICAgY29udGV4dC5kaXNjb25uZWN0KCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZmV0Y2hDb250ZXh0Rm9yU2NvcGUoc2NvcGUpIHtcbiAgICAgICAgbGV0IGNvbnRleHQgPSB0aGlzLmNvbnRleHRzQnlTY29wZS5nZXQoc2NvcGUpO1xuICAgICAgICBpZiAoIWNvbnRleHQpIHtcbiAgICAgICAgICAgIGNvbnRleHQgPSBuZXcgQ29udGV4dCh0aGlzLCBzY29wZSk7XG4gICAgICAgICAgICB0aGlzLmNvbnRleHRzQnlTY29wZS5zZXQoc2NvcGUsIGNvbnRleHQpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBjb250ZXh0O1xuICAgIH1cbn1cblxuY2xhc3MgQ2xhc3NNYXAge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgaGFzKG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGF0YS5oYXModGhpcy5nZXREYXRhS2V5KG5hbWUpKTtcbiAgICB9XG4gICAgZ2V0KG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZ2V0QWxsKG5hbWUpWzBdO1xuICAgIH1cbiAgICBnZXRBbGwobmFtZSkge1xuICAgICAgICBjb25zdCB0b2tlblN0cmluZyA9IHRoaXMuZGF0YS5nZXQodGhpcy5nZXREYXRhS2V5KG5hbWUpKSB8fCBcIlwiO1xuICAgICAgICByZXR1cm4gdG9rZW5pemUodG9rZW5TdHJpbmcpO1xuICAgIH1cbiAgICBnZXRBdHRyaWJ1dGVOYW1lKG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGF0YS5nZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KHRoaXMuZ2V0RGF0YUtleShuYW1lKSk7XG4gICAgfVxuICAgIGdldERhdGFLZXkobmFtZSkge1xuICAgICAgICByZXR1cm4gYCR7bmFtZX0tY2xhc3NgO1xuICAgIH1cbiAgICBnZXQgZGF0YSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZGF0YTtcbiAgICB9XG59XG5cbmNsYXNzIERhdGFNYXAge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQoa2V5KSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUobmFtZSk7XG4gICAgfVxuICAgIHNldChrZXksIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgdGhpcy5lbGVtZW50LnNldEF0dHJpYnV0ZShuYW1lLCB2YWx1ZSk7XG4gICAgICAgIHJldHVybiB0aGlzLmdldChrZXkpO1xuICAgIH1cbiAgICBoYXMoa2V5KSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5oYXNBdHRyaWJ1dGUobmFtZSk7XG4gICAgfVxuICAgIGRlbGV0ZShrZXkpIHtcbiAgICAgICAgaWYgKHRoaXMuaGFzKGtleSkpIHtcbiAgICAgICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgICAgIHRoaXMuZWxlbWVudC5yZW1vdmVBdHRyaWJ1dGUobmFtZSk7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KGtleSkge1xuICAgICAgICByZXR1cm4gYGRhdGEtJHt0aGlzLmlkZW50aWZpZXJ9LSR7ZGFzaGVyaXplKGtleSl9YDtcbiAgICB9XG59XG5cbmNsYXNzIEd1aWRlIHtcbiAgICBjb25zdHJ1Y3Rvcihsb2dnZXIpIHtcbiAgICAgICAgdGhpcy53YXJuZWRLZXlzQnlPYmplY3QgPSBuZXcgV2Vha01hcCgpO1xuICAgICAgICB0aGlzLmxvZ2dlciA9IGxvZ2dlcjtcbiAgICB9XG4gICAgd2FybihvYmplY3QsIGtleSwgbWVzc2FnZSkge1xuICAgICAgICBsZXQgd2FybmVkS2V5cyA9IHRoaXMud2FybmVkS2V5c0J5T2JqZWN0LmdldChvYmplY3QpO1xuICAgICAgICBpZiAoIXdhcm5lZEtleXMpIHtcbiAgICAgICAgICAgIHdhcm5lZEtleXMgPSBuZXcgU2V0KCk7XG4gICAgICAgICAgICB0aGlzLndhcm5lZEtleXNCeU9iamVjdC5zZXQob2JqZWN0LCB3YXJuZWRLZXlzKTtcbiAgICAgICAgfVxuICAgICAgICBpZiAoIXdhcm5lZEtleXMuaGFzKGtleSkpIHtcbiAgICAgICAgICAgIHdhcm5lZEtleXMuYWRkKGtleSk7XG4gICAgICAgICAgICB0aGlzLmxvZ2dlci53YXJuKG1lc3NhZ2UsIG9iamVjdCk7XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmZ1bmN0aW9uIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbihhdHRyaWJ1dGVOYW1lLCB0b2tlbikge1xuICAgIHJldHVybiBgWyR7YXR0cmlidXRlTmFtZX1+PVwiJHt0b2tlbn1cIl1gO1xufVxuXG5jbGFzcyBUYXJnZXRTZXQge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5zY2hlbWE7XG4gICAgfVxuICAgIGhhcyh0YXJnZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmZpbmQodGFyZ2V0TmFtZSkgIT0gbnVsbDtcbiAgICB9XG4gICAgZmluZCguLi50YXJnZXROYW1lcykge1xuICAgICAgICByZXR1cm4gdGFyZ2V0TmFtZXMucmVkdWNlKCh0YXJnZXQsIHRhcmdldE5hbWUpID0+IHRhcmdldCB8fCB0aGlzLmZpbmRUYXJnZXQodGFyZ2V0TmFtZSkgfHwgdGhpcy5maW5kTGVnYWN5VGFyZ2V0KHRhcmdldE5hbWUpLCB1bmRlZmluZWQpO1xuICAgIH1cbiAgICBmaW5kQWxsKC4uLnRhcmdldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiB0YXJnZXROYW1lcy5yZWR1Y2UoKHRhcmdldHMsIHRhcmdldE5hbWUpID0+IFtcbiAgICAgICAgICAgIC4uLnRhcmdldHMsXG4gICAgICAgICAgICAuLi50aGlzLmZpbmRBbGxUYXJnZXRzKHRhcmdldE5hbWUpLFxuICAgICAgICAgICAgLi4udGhpcy5maW5kQWxsTGVnYWN5VGFyZ2V0cyh0YXJnZXROYW1lKSxcbiAgICAgICAgXSwgW10pO1xuICAgIH1cbiAgICBmaW5kVGFyZ2V0KHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZmluZEVsZW1lbnQoc2VsZWN0b3IpO1xuICAgIH1cbiAgICBmaW5kQWxsVGFyZ2V0cyh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSk7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmZpbmRBbGxFbGVtZW50cyhzZWxlY3Rvcik7XG4gICAgfVxuICAgIGdldFNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGVGb3JTY29wZSh0aGlzLmlkZW50aWZpZXIpO1xuICAgICAgICByZXR1cm4gYXR0cmlidXRlVmFsdWVDb250YWluc1Rva2VuKGF0dHJpYnV0ZU5hbWUsIHRhcmdldE5hbWUpO1xuICAgIH1cbiAgICBmaW5kTGVnYWN5VGFyZ2V0KHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldExlZ2FjeVNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVwcmVjYXRlKHRoaXMuc2NvcGUuZmluZEVsZW1lbnQoc2VsZWN0b3IpLCB0YXJnZXROYW1lKTtcbiAgICB9XG4gICAgZmluZEFsbExlZ2FjeVRhcmdldHModGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0TGVnYWN5U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5maW5kQWxsRWxlbWVudHMoc2VsZWN0b3IpLm1hcCgoZWxlbWVudCkgPT4gdGhpcy5kZXByZWNhdGUoZWxlbWVudCwgdGFyZ2V0TmFtZSkpO1xuICAgIH1cbiAgICBnZXRMZWdhY3lTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCB0YXJnZXREZXNjcmlwdG9yID0gYCR7dGhpcy5pZGVudGlmaWVyfS4ke3RhcmdldE5hbWV9YDtcbiAgICAgICAgcmV0dXJuIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbih0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGUsIHRhcmdldERlc2NyaXB0b3IpO1xuICAgIH1cbiAgICBkZXByZWNhdGUoZWxlbWVudCwgdGFyZ2V0TmFtZSkge1xuICAgICAgICBpZiAoZWxlbWVudCkge1xuICAgICAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyIH0gPSB0aGlzO1xuICAgICAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuc2NoZW1hLnRhcmdldEF0dHJpYnV0ZTtcbiAgICAgICAgICAgIGNvbnN0IHJldmlzZWRBdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEudGFyZ2V0QXR0cmlidXRlRm9yU2NvcGUoaWRlbnRpZmllcik7XG4gICAgICAgICAgICB0aGlzLmd1aWRlLndhcm4oZWxlbWVudCwgYHRhcmdldDoke3RhcmdldE5hbWV9YCwgYFBsZWFzZSByZXBsYWNlICR7YXR0cmlidXRlTmFtZX09XCIke2lkZW50aWZpZXJ9LiR7dGFyZ2V0TmFtZX1cIiB3aXRoICR7cmV2aXNlZEF0dHJpYnV0ZU5hbWV9PVwiJHt0YXJnZXROYW1lfVwiLiBgICtcbiAgICAgICAgICAgICAgICBgVGhlICR7YXR0cmlidXRlTmFtZX0gYXR0cmlidXRlIGlzIGRlcHJlY2F0ZWQgYW5kIHdpbGwgYmUgcmVtb3ZlZCBpbiBhIGZ1dHVyZSB2ZXJzaW9uIG9mIFN0aW11bHVzLmApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBlbGVtZW50O1xuICAgIH1cbiAgICBnZXQgZ3VpZGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmd1aWRlO1xuICAgIH1cbn1cblxuY2xhc3MgT3V0bGV0U2V0IHtcbiAgICBjb25zdHJ1Y3RvcihzY29wZSwgY29udHJvbGxlckVsZW1lbnQpIHtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgICAgICB0aGlzLmNvbnRyb2xsZXJFbGVtZW50ID0gY29udHJvbGxlckVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuc2NoZW1hO1xuICAgIH1cbiAgICBoYXMob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5maW5kKG91dGxldE5hbWUpICE9IG51bGw7XG4gICAgfVxuICAgIGZpbmQoLi4ub3V0bGV0TmFtZXMpIHtcbiAgICAgICAgcmV0dXJuIG91dGxldE5hbWVzLnJlZHVjZSgob3V0bGV0LCBvdXRsZXROYW1lKSA9PiBvdXRsZXQgfHwgdGhpcy5maW5kT3V0bGV0KG91dGxldE5hbWUpLCB1bmRlZmluZWQpO1xuICAgIH1cbiAgICBmaW5kQWxsKC4uLm91dGxldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiBvdXRsZXROYW1lcy5yZWR1Y2UoKG91dGxldHMsIG91dGxldE5hbWUpID0+IFsuLi5vdXRsZXRzLCAuLi50aGlzLmZpbmRBbGxPdXRsZXRzKG91dGxldE5hbWUpXSwgW10pO1xuICAgIH1cbiAgICBnZXRTZWxlY3RvckZvck91dGxldE5hbWUob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEub3V0bGV0QXR0cmlidXRlRm9yU2NvcGUodGhpcy5pZGVudGlmaWVyLCBvdXRsZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udHJvbGxlckVsZW1lbnQuZ2V0QXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbiAgICBmaW5kT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKHNlbGVjdG9yKVxuICAgICAgICAgICAgcmV0dXJuIHRoaXMuZmluZEVsZW1lbnQoc2VsZWN0b3IsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBmaW5kQWxsT3V0bGV0cyhvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRTZWxlY3RvckZvck91dGxldE5hbWUob3V0bGV0TmFtZSk7XG4gICAgICAgIHJldHVybiBzZWxlY3RvciA/IHRoaXMuZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yLCBvdXRsZXROYW1lKSA6IFtdO1xuICAgIH1cbiAgICBmaW5kRWxlbWVudChzZWxlY3Rvciwgb3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBlbGVtZW50cyA9IHRoaXMuc2NvcGUucXVlcnlFbGVtZW50cyhzZWxlY3Rvcik7XG4gICAgICAgIHJldHVybiBlbGVtZW50cy5maWx0ZXIoKGVsZW1lbnQpID0+IHRoaXMubWF0Y2hlc0VsZW1lbnQoZWxlbWVudCwgc2VsZWN0b3IsIG91dGxldE5hbWUpKVswXTtcbiAgICB9XG4gICAgZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yLCBvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGVsZW1lbnRzID0gdGhpcy5zY29wZS5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKTtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnRzLmZpbHRlcigoZWxlbWVudCkgPT4gdGhpcy5tYXRjaGVzRWxlbWVudChlbGVtZW50LCBzZWxlY3Rvciwgb3V0bGV0TmFtZSkpO1xuICAgIH1cbiAgICBtYXRjaGVzRWxlbWVudChlbGVtZW50LCBzZWxlY3Rvciwgb3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBjb250cm9sbGVyQXR0cmlidXRlID0gZWxlbWVudC5nZXRBdHRyaWJ1dGUodGhpcy5zY29wZS5zY2hlbWEuY29udHJvbGxlckF0dHJpYnV0ZSkgfHwgXCJcIjtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnQubWF0Y2hlcyhzZWxlY3RvcikgJiYgY29udHJvbGxlckF0dHJpYnV0ZS5zcGxpdChcIiBcIikuaW5jbHVkZXMob3V0bGV0TmFtZSk7XG4gICAgfVxufVxuXG5jbGFzcyBTY29wZSB7XG4gICAgY29uc3RydWN0b3Ioc2NoZW1hLCBlbGVtZW50LCBpZGVudGlmaWVyLCBsb2dnZXIpIHtcbiAgICAgICAgdGhpcy50YXJnZXRzID0gbmV3IFRhcmdldFNldCh0aGlzKTtcbiAgICAgICAgdGhpcy5jbGFzc2VzID0gbmV3IENsYXNzTWFwKHRoaXMpO1xuICAgICAgICB0aGlzLmRhdGEgPSBuZXcgRGF0YU1hcCh0aGlzKTtcbiAgICAgICAgdGhpcy5jb250YWluc0VsZW1lbnQgPSAoZWxlbWVudCkgPT4ge1xuICAgICAgICAgICAgcmV0dXJuIGVsZW1lbnQuY2xvc2VzdCh0aGlzLmNvbnRyb2xsZXJTZWxlY3RvcikgPT09IHRoaXMuZWxlbWVudDtcbiAgICAgICAgfTtcbiAgICAgICAgdGhpcy5zY2hlbWEgPSBzY2hlbWE7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuaWRlbnRpZmllciA9IGlkZW50aWZpZXI7XG4gICAgICAgIHRoaXMuZ3VpZGUgPSBuZXcgR3VpZGUobG9nZ2VyKTtcbiAgICAgICAgdGhpcy5vdXRsZXRzID0gbmV3IE91dGxldFNldCh0aGlzLmRvY3VtZW50U2NvcGUsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBmaW5kRWxlbWVudChzZWxlY3Rvcikge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpID8gdGhpcy5lbGVtZW50IDogdGhpcy5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKS5maW5kKHRoaXMuY29udGFpbnNFbGVtZW50KTtcbiAgICB9XG4gICAgZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yKSB7XG4gICAgICAgIHJldHVybiBbXG4gICAgICAgICAgICAuLi4odGhpcy5lbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpID8gW3RoaXMuZWxlbWVudF0gOiBbXSksXG4gICAgICAgICAgICAuLi50aGlzLnF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpLmZpbHRlcih0aGlzLmNvbnRhaW5zRWxlbWVudCksXG4gICAgICAgIF07XG4gICAgfVxuICAgIHF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5lbGVtZW50LnF1ZXJ5U2VsZWN0b3JBbGwoc2VsZWN0b3IpKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJTZWxlY3RvcigpIHtcbiAgICAgICAgcmV0dXJuIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbih0aGlzLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlLCB0aGlzLmlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBnZXQgaXNEb2N1bWVudFNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50ID09PSBkb2N1bWVudC5kb2N1bWVudEVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBkb2N1bWVudFNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5pc0RvY3VtZW50U2NvcGVcbiAgICAgICAgICAgID8gdGhpc1xuICAgICAgICAgICAgOiBuZXcgU2NvcGUodGhpcy5zY2hlbWEsIGRvY3VtZW50LmRvY3VtZW50RWxlbWVudCwgdGhpcy5pZGVudGlmaWVyLCB0aGlzLmd1aWRlLmxvZ2dlcik7XG4gICAgfVxufVxuXG5jbGFzcyBTY29wZU9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBzY2hlbWEsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIgPSBuZXcgVmFsdWVMaXN0T2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLmNvbnRyb2xsZXJBdHRyaWJ1dGUsIHRoaXMpO1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllckJ5RWxlbWVudCA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMgPSBuZXcgV2Vha01hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJBdHRyaWJ1dGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlO1xuICAgIH1cbiAgICBwYXJzZVZhbHVlRm9yVG9rZW4odG9rZW4pIHtcbiAgICAgICAgY29uc3QgeyBlbGVtZW50LCBjb250ZW50OiBpZGVudGlmaWVyIH0gPSB0b2tlbjtcbiAgICAgICAgcmV0dXJuIHRoaXMucGFyc2VWYWx1ZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBwYXJzZVZhbHVlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBzY29wZXNCeUlkZW50aWZpZXIgPSB0aGlzLmZldGNoU2NvcGVzQnlJZGVudGlmaWVyRm9yRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgbGV0IHNjb3BlID0gc2NvcGVzQnlJZGVudGlmaWVyLmdldChpZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKCFzY29wZSkge1xuICAgICAgICAgICAgc2NvcGUgPSB0aGlzLmRlbGVnYXRlLmNyZWF0ZVNjb3BlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcik7XG4gICAgICAgICAgICBzY29wZXNCeUlkZW50aWZpZXIuc2V0KGlkZW50aWZpZXIsIHNjb3BlKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gc2NvcGU7XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpIHtcbiAgICAgICAgY29uc3QgcmVmZXJlbmNlQ291bnQgPSAodGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5nZXQodmFsdWUpIHx8IDApICsgMTtcbiAgICAgICAgdGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5zZXQodmFsdWUsIHJlZmVyZW5jZUNvdW50KTtcbiAgICAgICAgaWYgKHJlZmVyZW5jZUNvdW50ID09IDEpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc2NvcGVDb25uZWN0ZWQodmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRWYWx1ZShlbGVtZW50LCB2YWx1ZSkge1xuICAgICAgICBjb25zdCByZWZlcmVuY2VDb3VudCA9IHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMuZ2V0KHZhbHVlKTtcbiAgICAgICAgaWYgKHJlZmVyZW5jZUNvdW50KSB7XG4gICAgICAgICAgICB0aGlzLnNjb3BlUmVmZXJlbmNlQ291bnRzLnNldCh2YWx1ZSwgcmVmZXJlbmNlQ291bnQgLSAxKTtcbiAgICAgICAgICAgIGlmIChyZWZlcmVuY2VDb3VudCA9PSAxKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zY29wZURpc2Nvbm5lY3RlZCh2YWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgZmV0Y2hTY29wZXNCeUlkZW50aWZpZXJGb3JFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgbGV0IHNjb3Blc0J5SWRlbnRpZmllciA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyQnlFbGVtZW50LmdldChlbGVtZW50KTtcbiAgICAgICAgaWYgKCFzY29wZXNCeUlkZW50aWZpZXIpIHtcbiAgICAgICAgICAgIHNjb3Blc0J5SWRlbnRpZmllciA9IG5ldyBNYXAoKTtcbiAgICAgICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyQnlFbGVtZW50LnNldChlbGVtZW50LCBzY29wZXNCeUlkZW50aWZpZXIpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBzY29wZXNCeUlkZW50aWZpZXI7XG4gICAgfVxufVxuXG5jbGFzcyBSb3V0ZXIge1xuICAgIGNvbnN0cnVjdG9yKGFwcGxpY2F0aW9uKSB7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24gPSBhcHBsaWNhdGlvbjtcbiAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyID0gbmV3IFNjb3BlT2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLnNjaGVtYSwgdGhpcyk7XG4gICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMubW9kdWxlc0J5SWRlbnRpZmllciA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGxvZ2dlcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24ubG9nZ2VyO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlckF0dHJpYnV0ZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGU7XG4gICAgfVxuICAgIGdldCBtb2R1bGVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIudmFsdWVzKCkpO1xuICAgIH1cbiAgICBnZXQgY29udGV4dHMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm1vZHVsZXMucmVkdWNlKChjb250ZXh0cywgbW9kdWxlKSA9PiBjb250ZXh0cy5jb25jYXQobW9kdWxlLmNvbnRleHRzKSwgW10pO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuc2NvcGVPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGxvYWREZWZpbml0aW9uKGRlZmluaXRpb24pIHtcbiAgICAgICAgdGhpcy51bmxvYWRJZGVudGlmaWVyKGRlZmluaXRpb24uaWRlbnRpZmllcik7XG4gICAgICAgIGNvbnN0IG1vZHVsZSA9IG5ldyBNb2R1bGUodGhpcy5hcHBsaWNhdGlvbiwgZGVmaW5pdGlvbik7XG4gICAgICAgIHRoaXMuY29ubmVjdE1vZHVsZShtb2R1bGUpO1xuICAgICAgICBjb25zdCBhZnRlckxvYWQgPSBkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3Rvci5hZnRlckxvYWQ7XG4gICAgICAgIGlmIChhZnRlckxvYWQpIHtcbiAgICAgICAgICAgIGFmdGVyTG9hZC5jYWxsKGRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yLCBkZWZpbml0aW9uLmlkZW50aWZpZXIsIHRoaXMuYXBwbGljYXRpb24pO1xuICAgICAgICB9XG4gICAgfVxuICAgIHVubG9hZElkZW50aWZpZXIoaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RNb2R1bGUobW9kdWxlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXRDb250ZXh0Rm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICByZXR1cm4gbW9kdWxlLmNvbnRleHRzLmZpbmQoKGNvbnRleHQpID0+IGNvbnRleHQuZWxlbWVudCA9PSBlbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9wb3NlVG9Db25uZWN0U2NvcGVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgICAgIGNvbnN0IHNjb3BlID0gdGhpcy5zY29wZU9ic2VydmVyLnBhcnNlVmFsdWVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKHNjb3BlKSB7XG4gICAgICAgICAgICB0aGlzLnNjb3BlT2JzZXJ2ZXIuZWxlbWVudE1hdGNoZWRWYWx1ZShzY29wZS5lbGVtZW50LCBzY29wZSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICBjb25zb2xlLmVycm9yKGBDb3VsZG4ndCBmaW5kIG9yIGNyZWF0ZSBzY29wZSBmb3IgaWRlbnRpZmllcjogXCIke2lkZW50aWZpZXJ9XCIgYW5kIGVsZW1lbnQ6YCwgZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgaGFuZGxlRXJyb3IoZXJyb3IsIG1lc3NhZ2UsIGRldGFpbCkge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwpO1xuICAgIH1cbiAgICBjcmVhdGVTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgcmV0dXJuIG5ldyBTY29wZSh0aGlzLnNjaGVtYSwgZWxlbWVudCwgaWRlbnRpZmllciwgdGhpcy5sb2dnZXIpO1xuICAgIH1cbiAgICBzY29wZUNvbm5lY3RlZChzY29wZSkge1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllci5hZGQoc2NvcGUuaWRlbnRpZmllciwgc2NvcGUpO1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KHNjb3BlLmlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICBtb2R1bGUuY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2NvcGVEaXNjb25uZWN0ZWQoc2NvcGUpIHtcbiAgICAgICAgdGhpcy5zY29wZXNCeUlkZW50aWZpZXIuZGVsZXRlKHNjb3BlLmlkZW50aWZpZXIsIHNjb3BlKTtcbiAgICAgICAgY29uc3QgbW9kdWxlID0gdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmdldChzY29wZS5pZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKG1vZHVsZSkge1xuICAgICAgICAgICAgbW9kdWxlLmRpc2Nvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGNvbm5lY3RNb2R1bGUobW9kdWxlKSB7XG4gICAgICAgIHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5zZXQobW9kdWxlLmlkZW50aWZpZXIsIG1vZHVsZSk7XG4gICAgICAgIGNvbnN0IHNjb3BlcyA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmdldFZhbHVlc0ZvcktleShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIHNjb3Blcy5mb3JFYWNoKChzY29wZSkgPT4gbW9kdWxlLmNvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdE1vZHVsZShtb2R1bGUpIHtcbiAgICAgICAgdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmRlbGV0ZShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIGNvbnN0IHNjb3BlcyA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmdldFZhbHVlc0ZvcktleShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIHNjb3Blcy5mb3JFYWNoKChzY29wZSkgPT4gbW9kdWxlLmRpc2Nvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpKTtcbiAgICB9XG59XG5cbmNvbnN0IGRlZmF1bHRTY2hlbWEgPSB7XG4gICAgY29udHJvbGxlckF0dHJpYnV0ZTogXCJkYXRhLWNvbnRyb2xsZXJcIixcbiAgICBhY3Rpb25BdHRyaWJ1dGU6IFwiZGF0YS1hY3Rpb25cIixcbiAgICB0YXJnZXRBdHRyaWJ1dGU6IFwiZGF0YS10YXJnZXRcIixcbiAgICB0YXJnZXRBdHRyaWJ1dGVGb3JTY29wZTogKGlkZW50aWZpZXIpID0+IGBkYXRhLSR7aWRlbnRpZmllcn0tdGFyZ2V0YCxcbiAgICBvdXRsZXRBdHRyaWJ1dGVGb3JTY29wZTogKGlkZW50aWZpZXIsIG91dGxldCkgPT4gYGRhdGEtJHtpZGVudGlmaWVyfS0ke291dGxldH0tb3V0bGV0YCxcbiAgICBrZXlNYXBwaW5nczogT2JqZWN0LmFzc2lnbihPYmplY3QuYXNzaWduKHsgZW50ZXI6IFwiRW50ZXJcIiwgdGFiOiBcIlRhYlwiLCBlc2M6IFwiRXNjYXBlXCIsIHNwYWNlOiBcIiBcIiwgdXA6IFwiQXJyb3dVcFwiLCBkb3duOiBcIkFycm93RG93blwiLCBsZWZ0OiBcIkFycm93TGVmdFwiLCByaWdodDogXCJBcnJvd1JpZ2h0XCIsIGhvbWU6IFwiSG9tZVwiLCBlbmQ6IFwiRW5kXCIsIHBhZ2VfdXA6IFwiUGFnZVVwXCIsIHBhZ2VfZG93bjogXCJQYWdlRG93blwiIH0sIG9iamVjdEZyb21FbnRyaWVzKFwiYWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXpcIi5zcGxpdChcIlwiKS5tYXAoKGMpID0+IFtjLCBjXSkpKSwgb2JqZWN0RnJvbUVudHJpZXMoXCIwMTIzNDU2Nzg5XCIuc3BsaXQoXCJcIikubWFwKChuKSA9PiBbbiwgbl0pKSksXG59O1xuZnVuY3Rpb24gb2JqZWN0RnJvbUVudHJpZXMoYXJyYXkpIHtcbiAgICByZXR1cm4gYXJyYXkucmVkdWNlKChtZW1vLCBbaywgdl0pID0+IChPYmplY3QuYXNzaWduKE9iamVjdC5hc3NpZ24oe30sIG1lbW8pLCB7IFtrXTogdiB9KSksIHt9KTtcbn1cblxuY2xhc3MgQXBwbGljYXRpb24ge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQgPSBkb2N1bWVudC5kb2N1bWVudEVsZW1lbnQsIHNjaGVtYSA9IGRlZmF1bHRTY2hlbWEpIHtcbiAgICAgICAgdGhpcy5sb2dnZXIgPSBjb25zb2xlO1xuICAgICAgICB0aGlzLmRlYnVnID0gZmFsc2U7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eSA9IChpZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCA9IHt9KSA9PiB7XG4gICAgICAgICAgICBpZiAodGhpcy5kZWJ1Zykge1xuICAgICAgICAgICAgICAgIHRoaXMubG9nRm9ybWF0dGVkTWVzc2FnZShpZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH07XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgICAgICB0aGlzLmRpc3BhdGNoZXIgPSBuZXcgRGlzcGF0Y2hlcih0aGlzKTtcbiAgICAgICAgdGhpcy5yb3V0ZXIgPSBuZXcgUm91dGVyKHRoaXMpO1xuICAgICAgICB0aGlzLmFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzID0gT2JqZWN0LmFzc2lnbih7fSwgZGVmYXVsdEFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzKTtcbiAgICB9XG4gICAgc3RhdGljIHN0YXJ0KGVsZW1lbnQsIHNjaGVtYSkge1xuICAgICAgICBjb25zdCBhcHBsaWNhdGlvbiA9IG5ldyB0aGlzKGVsZW1lbnQsIHNjaGVtYSk7XG4gICAgICAgIGFwcGxpY2F0aW9uLnN0YXJ0KCk7XG4gICAgICAgIHJldHVybiBhcHBsaWNhdGlvbjtcbiAgICB9XG4gICAgYXN5bmMgc3RhcnQoKSB7XG4gICAgICAgIGF3YWl0IGRvbVJlYWR5KCk7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RhcnRpbmdcIik7XG4gICAgICAgIHRoaXMuZGlzcGF0Y2hlci5zdGFydCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5zdGFydCgpO1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJhcHBsaWNhdGlvblwiLCBcInN0YXJ0XCIpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJhcHBsaWNhdGlvblwiLCBcInN0b3BwaW5nXCIpO1xuICAgICAgICB0aGlzLmRpc3BhdGNoZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5zdG9wKCk7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RvcFwiKTtcbiAgICB9XG4gICAgcmVnaXN0ZXIoaWRlbnRpZmllciwgY29udHJvbGxlckNvbnN0cnVjdG9yKSB7XG4gICAgICAgIHRoaXMubG9hZCh7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXJDb25zdHJ1Y3RvciB9KTtcbiAgICB9XG4gICAgcmVnaXN0ZXJBY3Rpb25PcHRpb24obmFtZSwgZmlsdGVyKSB7XG4gICAgICAgIHRoaXMuYWN0aW9uRGVzY3JpcHRvckZpbHRlcnNbbmFtZV0gPSBmaWx0ZXI7XG4gICAgfVxuICAgIGxvYWQoaGVhZCwgLi4ucmVzdCkge1xuICAgICAgICBjb25zdCBkZWZpbml0aW9ucyA9IEFycmF5LmlzQXJyYXkoaGVhZCkgPyBoZWFkIDogW2hlYWQsIC4uLnJlc3RdO1xuICAgICAgICBkZWZpbml0aW9ucy5mb3JFYWNoKChkZWZpbml0aW9uKSA9PiB7XG4gICAgICAgICAgICBpZiAoZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3Iuc2hvdWxkTG9hZCkge1xuICAgICAgICAgICAgICAgIHRoaXMucm91dGVyLmxvYWREZWZpbml0aW9uKGRlZmluaXRpb24pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICB9XG4gICAgdW5sb2FkKGhlYWQsIC4uLnJlc3QpIHtcbiAgICAgICAgY29uc3QgaWRlbnRpZmllcnMgPSBBcnJheS5pc0FycmF5KGhlYWQpID8gaGVhZCA6IFtoZWFkLCAuLi5yZXN0XTtcbiAgICAgICAgaWRlbnRpZmllcnMuZm9yRWFjaCgoaWRlbnRpZmllcikgPT4gdGhpcy5yb3V0ZXIudW5sb2FkSWRlbnRpZmllcihpZGVudGlmaWVyKSk7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVycygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMucm91dGVyLmNvbnRleHRzLm1hcCgoY29udGV4dCkgPT4gY29udGV4dC5jb250cm9sbGVyKTtcbiAgICB9XG4gICAgZ2V0Q29udHJvbGxlckZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgY29uc3QgY29udGV4dCA9IHRoaXMucm91dGVyLmdldENvbnRleHRGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICAgICAgcmV0dXJuIGNvbnRleHQgPyBjb250ZXh0LmNvbnRyb2xsZXIgOiBudWxsO1xuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZXJyb3IoYCVzXFxuXFxuJW9cXG5cXG4lb2AsIG1lc3NhZ2UsIGVycm9yLCBkZXRhaWwpO1xuICAgICAgICAoX2EgPSB3aW5kb3cub25lcnJvcikgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLmNhbGwod2luZG93LCBtZXNzYWdlLCBcIlwiLCAwLCAwLCBlcnJvcik7XG4gICAgfVxuICAgIGxvZ0Zvcm1hdHRlZE1lc3NhZ2UoaWRlbnRpZmllciwgZnVuY3Rpb25OYW1lLCBkZXRhaWwgPSB7fSkge1xuICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgYXBwbGljYXRpb246IHRoaXMgfSwgZGV0YWlsKTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZ3JvdXBDb2xsYXBzZWQoYCR7aWRlbnRpZmllcn0gIyR7ZnVuY3Rpb25OYW1lfWApO1xuICAgICAgICB0aGlzLmxvZ2dlci5sb2coXCJkZXRhaWxzOlwiLCBPYmplY3QuYXNzaWduKHt9LCBkZXRhaWwpKTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZ3JvdXBFbmQoKTtcbiAgICB9XG59XG5mdW5jdGlvbiBkb21SZWFkeSgpIHtcbiAgICByZXR1cm4gbmV3IFByb21pc2UoKHJlc29sdmUpID0+IHtcbiAgICAgICAgaWYgKGRvY3VtZW50LnJlYWR5U3RhdGUgPT0gXCJsb2FkaW5nXCIpIHtcbiAgICAgICAgICAgIGRvY3VtZW50LmFkZEV2ZW50TGlzdGVuZXIoXCJET01Db250ZW50TG9hZGVkXCIsICgpID0+IHJlc29sdmUoKSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXNvbHZlKCk7XG4gICAgICAgIH1cbiAgICB9KTtcbn1cblxuZnVuY3Rpb24gQ2xhc3NQcm9wZXJ0aWVzQmxlc3NpbmcoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBjbGFzc2VzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwiY2xhc3Nlc1wiKTtcbiAgICByZXR1cm4gY2xhc3Nlcy5yZWR1Y2UoKHByb3BlcnRpZXMsIGNsYXNzRGVmaW5pdGlvbikgPT4ge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihwcm9wZXJ0aWVzLCBwcm9wZXJ0aWVzRm9yQ2xhc3NEZWZpbml0aW9uKGNsYXNzRGVmaW5pdGlvbikpO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JDbGFzc0RlZmluaXRpb24oa2V5KSB7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake2tleX1DbGFzc2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgeyBjbGFzc2VzIH0gPSB0aGlzO1xuICAgICAgICAgICAgICAgIGlmIChjbGFzc2VzLmhhcyhrZXkpKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBjbGFzc2VzLmdldChrZXkpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgY29uc3QgYXR0cmlidXRlID0gY2xhc3Nlcy5nZXRBdHRyaWJ1dGVOYW1lKGtleSk7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyBhdHRyaWJ1dGUgXCIke2F0dHJpYnV0ZX1cImApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtrZXl9Q2xhc3Nlc2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMuY2xhc3Nlcy5nZXRBbGwoa2V5KTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKGtleSl9Q2xhc3NgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLmNsYXNzZXMuaGFzKGtleSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5cbmZ1bmN0aW9uIE91dGxldFByb3BlcnRpZXNCbGVzc2luZyhjb25zdHJ1Y3Rvcikge1xuICAgIGNvbnN0IG91dGxldHMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJvdXRsZXRzXCIpO1xuICAgIHJldHVybiBvdXRsZXRzLnJlZHVjZSgocHJvcGVydGllcywgb3V0bGV0RGVmaW5pdGlvbikgPT4ge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihwcm9wZXJ0aWVzLCBwcm9wZXJ0aWVzRm9yT3V0bGV0RGVmaW5pdGlvbihvdXRsZXREZWZpbml0aW9uKSk7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gZ2V0T3V0bGV0Q29udHJvbGxlcihjb250cm9sbGVyLCBlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgcmV0dXJuIGNvbnRyb2xsZXIuYXBwbGljYXRpb24uZ2V0Q29udHJvbGxlckZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xufVxuZnVuY3Rpb24gZ2V0Q29udHJvbGxlckFuZEVuc3VyZUNvbm5lY3RlZFNjb3BlKGNvbnRyb2xsZXIsIGVsZW1lbnQsIG91dGxldE5hbWUpIHtcbiAgICBsZXQgb3V0bGV0Q29udHJvbGxlciA9IGdldE91dGxldENvbnRyb2xsZXIoY29udHJvbGxlciwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgaWYgKG91dGxldENvbnRyb2xsZXIpXG4gICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xuICAgIGNvbnRyb2xsZXIuYXBwbGljYXRpb24ucm91dGVyLnByb3Bvc2VUb0Nvbm5lY3RTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIG91dGxldENvbnRyb2xsZXIgPSBnZXRPdXRsZXRDb250cm9sbGVyKGNvbnRyb2xsZXIsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICByZXR1cm4gb3V0bGV0Q29udHJvbGxlcjtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JPdXRsZXREZWZpbml0aW9uKG5hbWUpIHtcbiAgICBjb25zdCBjYW1lbGl6ZWROYW1lID0gbmFtZXNwYWNlQ2FtZWxpemUobmFtZSk7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake2NhbWVsaXplZE5hbWV9T3V0bGV0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRFbGVtZW50ID0gdGhpcy5vdXRsZXRzLmZpbmQobmFtZSk7XG4gICAgICAgICAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLm91dGxldHMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmIChvdXRsZXRFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IG91dGxldENvbnRyb2xsZXIgPSBnZXRDb250cm9sbGVyQW5kRW5zdXJlQ29ubmVjdGVkU2NvcGUodGhpcywgb3V0bGV0RWxlbWVudCwgbmFtZSk7XG4gICAgICAgICAgICAgICAgICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICAgICAgICAgICAgICAgICAgcmV0dXJuIG91dGxldENvbnRyb2xsZXI7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgVGhlIHByb3ZpZGVkIG91dGxldCBlbGVtZW50IGlzIG1pc3NpbmcgYW4gb3V0bGV0IGNvbnRyb2xsZXIgXCIke25hbWV9XCIgaW5zdGFuY2UgZm9yIGhvc3QgY29udHJvbGxlciBcIiR7dGhpcy5pZGVudGlmaWVyfVwiYCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyBvdXRsZXQgZWxlbWVudCBcIiR7bmFtZX1cIiBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCIuIFN0aW11bHVzIGNvdWxkbid0IGZpbmQgYSBtYXRjaGluZyBvdXRsZXQgZWxlbWVudCB1c2luZyBzZWxlY3RvciBcIiR7c2VsZWN0b3J9XCIuYCk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRzID0gdGhpcy5vdXRsZXRzLmZpbmRBbGwobmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG91dGxldHMubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gb3V0bGV0c1xuICAgICAgICAgICAgICAgICAgICAgICAgLm1hcCgob3V0bGV0RWxlbWVudCkgPT4ge1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc3Qgb3V0bGV0Q29udHJvbGxlciA9IGdldENvbnRyb2xsZXJBbmRFbnN1cmVDb25uZWN0ZWRTY29wZSh0aGlzLCBvdXRsZXRFbGVtZW50LCBuYW1lKTtcbiAgICAgICAgICAgICAgICAgICAgICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc29sZS53YXJuKGBUaGUgcHJvdmlkZWQgb3V0bGV0IGVsZW1lbnQgaXMgbWlzc2luZyBhbiBvdXRsZXQgY29udHJvbGxlciBcIiR7bmFtZX1cIiBpbnN0YW5jZSBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCJgLCBvdXRsZXRFbGVtZW50KTtcbiAgICAgICAgICAgICAgICAgICAgfSlcbiAgICAgICAgICAgICAgICAgICAgICAgIC5maWx0ZXIoKGNvbnRyb2xsZXIpID0+IGNvbnRyb2xsZXIpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICByZXR1cm4gW107XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRFbGVtZW50YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRFbGVtZW50ID0gdGhpcy5vdXRsZXRzLmZpbmQobmFtZSk7XG4gICAgICAgICAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLm91dGxldHMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmIChvdXRsZXRFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRFbGVtZW50O1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBNaXNzaW5nIG91dGxldCBlbGVtZW50IFwiJHtuYW1lfVwiIGZvciBob3N0IGNvbnRyb2xsZXIgXCIke3RoaXMuaWRlbnRpZmllcn1cIi4gU3RpbXVsdXMgY291bGRuJ3QgZmluZCBhIG1hdGNoaW5nIG91dGxldCBlbGVtZW50IHVzaW5nIHNlbGVjdG9yIFwiJHtzZWxlY3Rvcn1cIi5gKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRFbGVtZW50c2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0cy5maW5kQWxsKG5hbWUpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2BoYXMke2NhcGl0YWxpemUoY2FtZWxpemVkTmFtZSl9T3V0bGV0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXRzLmhhcyhuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbn1cblxuZnVuY3Rpb24gVGFyZ2V0UHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgdGFyZ2V0cyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBcInRhcmdldHNcIik7XG4gICAgcmV0dXJuIHRhcmdldHMucmVkdWNlKChwcm9wZXJ0aWVzLCB0YXJnZXREZWZpbml0aW9uKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JUYXJnZXREZWZpbml0aW9uKHRhcmdldERlZmluaXRpb24pKTtcbiAgICB9LCB7fSk7XG59XG5mdW5jdGlvbiBwcm9wZXJ0aWVzRm9yVGFyZ2V0RGVmaW5pdGlvbihuYW1lKSB7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake25hbWV9VGFyZ2V0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCB0YXJnZXQgPSB0aGlzLnRhcmdldHMuZmluZChuYW1lKTtcbiAgICAgICAgICAgICAgICBpZiAodGFyZ2V0KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiB0YXJnZXQ7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICB0aHJvdyBuZXcgRXJyb3IoYE1pc3NpbmcgdGFyZ2V0IGVsZW1lbnQgXCIke25hbWV9XCIgZm9yIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCIgY29udHJvbGxlcmApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtuYW1lfVRhcmdldHNgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLnRhcmdldHMuZmluZEFsbChuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKG5hbWUpfVRhcmdldGBdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMudGFyZ2V0cy5oYXMobmFtZSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5cbmZ1bmN0aW9uIFZhbHVlUHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgdmFsdWVEZWZpbml0aW9uUGFpcnMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNPYmplY3RQYWlycyhjb25zdHJ1Y3RvciwgXCJ2YWx1ZXNcIik7XG4gICAgY29uc3QgcHJvcGVydHlEZXNjcmlwdG9yTWFwID0ge1xuICAgICAgICB2YWx1ZURlc2NyaXB0b3JNYXA6IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdmFsdWVEZWZpbml0aW9uUGFpcnMucmVkdWNlKChyZXN1bHQsIHZhbHVlRGVmaW5pdGlvblBhaXIpID0+IHtcbiAgICAgICAgICAgICAgICAgICAgY29uc3QgdmFsdWVEZXNjcmlwdG9yID0gcGFyc2VWYWx1ZURlZmluaXRpb25QYWlyKHZhbHVlRGVmaW5pdGlvblBhaXIsIHRoaXMuaWRlbnRpZmllcik7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLmRhdGEuZ2V0QXR0cmlidXRlTmFtZUZvcktleSh2YWx1ZURlc2NyaXB0b3Iua2V5KTtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24ocmVzdWx0LCB7IFthdHRyaWJ1dGVOYW1lXTogdmFsdWVEZXNjcmlwdG9yIH0pO1xuICAgICAgICAgICAgICAgIH0sIHt9KTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbiAgICByZXR1cm4gdmFsdWVEZWZpbml0aW9uUGFpcnMucmVkdWNlKChwcm9wZXJ0aWVzLCB2YWx1ZURlZmluaXRpb25QYWlyKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JWYWx1ZURlZmluaXRpb25QYWlyKHZhbHVlRGVmaW5pdGlvblBhaXIpKTtcbiAgICB9LCBwcm9wZXJ0eURlc2NyaXB0b3JNYXApO1xufVxuZnVuY3Rpb24gcHJvcGVydGllc0ZvclZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpciwgY29udHJvbGxlcikge1xuICAgIGNvbnN0IGRlZmluaXRpb24gPSBwYXJzZVZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpciwgY29udHJvbGxlcik7XG4gICAgY29uc3QgeyBrZXksIG5hbWUsIHJlYWRlcjogcmVhZCwgd3JpdGVyOiB3cml0ZSB9ID0gZGVmaW5pdGlvbjtcbiAgICByZXR1cm4ge1xuICAgICAgICBbbmFtZV06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCB2YWx1ZSA9IHRoaXMuZGF0YS5nZXQoa2V5KTtcbiAgICAgICAgICAgICAgICBpZiAodmFsdWUgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIHJlYWQodmFsdWUpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGRlZmluaXRpb24uZGVmYXVsdFZhbHVlO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBzZXQodmFsdWUpIHtcbiAgICAgICAgICAgICAgICBpZiAodmFsdWUgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRhdGEuZGVsZXRlKGtleSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRhdGEuc2V0KGtleSwgd3JpdGUodmFsdWUpKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYGhhcyR7Y2FwaXRhbGl6ZShuYW1lKX1gXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLmRhdGEuaGFzKGtleSkgfHwgZGVmaW5pdGlvbi5oYXNDdXN0b21EZWZhdWx0VmFsdWU7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5mdW5jdGlvbiBwYXJzZVZhbHVlRGVmaW5pdGlvblBhaXIoW3Rva2VuLCB0eXBlRGVmaW5pdGlvbl0sIGNvbnRyb2xsZXIpIHtcbiAgICByZXR1cm4gdmFsdWVEZXNjcmlwdG9yRm9yVG9rZW5BbmRUeXBlRGVmaW5pdGlvbih7XG4gICAgICAgIGNvbnRyb2xsZXIsXG4gICAgICAgIHRva2VuLFxuICAgICAgICB0eXBlRGVmaW5pdGlvbixcbiAgICB9KTtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQoY29uc3RhbnQpIHtcbiAgICBzd2l0Y2ggKGNvbnN0YW50KSB7XG4gICAgICAgIGNhc2UgQXJyYXk6XG4gICAgICAgICAgICByZXR1cm4gXCJhcnJheVwiO1xuICAgICAgICBjYXNlIEJvb2xlYW46XG4gICAgICAgICAgICByZXR1cm4gXCJib29sZWFuXCI7XG4gICAgICAgIGNhc2UgTnVtYmVyOlxuICAgICAgICAgICAgcmV0dXJuIFwibnVtYmVyXCI7XG4gICAgICAgIGNhc2UgT2JqZWN0OlxuICAgICAgICAgICAgcmV0dXJuIFwib2JqZWN0XCI7XG4gICAgICAgIGNhc2UgU3RyaW5nOlxuICAgICAgICAgICAgcmV0dXJuIFwic3RyaW5nXCI7XG4gICAgfVxufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KGRlZmF1bHRWYWx1ZSkge1xuICAgIHN3aXRjaCAodHlwZW9mIGRlZmF1bHRWYWx1ZSkge1xuICAgICAgICBjYXNlIFwiYm9vbGVhblwiOlxuICAgICAgICAgICAgcmV0dXJuIFwiYm9vbGVhblwiO1xuICAgICAgICBjYXNlIFwibnVtYmVyXCI6XG4gICAgICAgICAgICByZXR1cm4gXCJudW1iZXJcIjtcbiAgICAgICAgY2FzZSBcInN0cmluZ1wiOlxuICAgICAgICAgICAgcmV0dXJuIFwic3RyaW5nXCI7XG4gICAgfVxuICAgIGlmIChBcnJheS5pc0FycmF5KGRlZmF1bHRWYWx1ZSkpXG4gICAgICAgIHJldHVybiBcImFycmF5XCI7XG4gICAgaWYgKE9iamVjdC5wcm90b3R5cGUudG9TdHJpbmcuY2FsbChkZWZhdWx0VmFsdWUpID09PSBcIltvYmplY3QgT2JqZWN0XVwiKVxuICAgICAgICByZXR1cm4gXCJvYmplY3RcIjtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVUeXBlT2JqZWN0KHBheWxvYWQpIHtcbiAgICBjb25zdCB7IGNvbnRyb2xsZXIsIHRva2VuLCB0eXBlT2JqZWN0IH0gPSBwYXlsb2FkO1xuICAgIGNvbnN0IGhhc1R5cGUgPSBpc1NvbWV0aGluZyh0eXBlT2JqZWN0LnR5cGUpO1xuICAgIGNvbnN0IGhhc0RlZmF1bHQgPSBpc1NvbWV0aGluZyh0eXBlT2JqZWN0LmRlZmF1bHQpO1xuICAgIGNvbnN0IGZ1bGxPYmplY3QgPSBoYXNUeXBlICYmIGhhc0RlZmF1bHQ7XG4gICAgY29uc3Qgb25seVR5cGUgPSBoYXNUeXBlICYmICFoYXNEZWZhdWx0O1xuICAgIGNvbnN0IG9ubHlEZWZhdWx0ID0gIWhhc1R5cGUgJiYgaGFzRGVmYXVsdDtcbiAgICBjb25zdCB0eXBlRnJvbU9iamVjdCA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZU9iamVjdC50eXBlKTtcbiAgICBjb25zdCB0eXBlRnJvbURlZmF1bHRWYWx1ZSA9IHBhcnNlVmFsdWVUeXBlRGVmYXVsdChwYXlsb2FkLnR5cGVPYmplY3QuZGVmYXVsdCk7XG4gICAgaWYgKG9ubHlUeXBlKVxuICAgICAgICByZXR1cm4gdHlwZUZyb21PYmplY3Q7XG4gICAgaWYgKG9ubHlEZWZhdWx0KVxuICAgICAgICByZXR1cm4gdHlwZUZyb21EZWZhdWx0VmFsdWU7XG4gICAgaWYgKHR5cGVGcm9tT2JqZWN0ICE9PSB0eXBlRnJvbURlZmF1bHRWYWx1ZSkge1xuICAgICAgICBjb25zdCBwcm9wZXJ0eVBhdGggPSBjb250cm9sbGVyID8gYCR7Y29udHJvbGxlcn0uJHt0b2tlbn1gIDogdG9rZW47XG4gICAgICAgIHRocm93IG5ldyBFcnJvcihgVGhlIHNwZWNpZmllZCBkZWZhdWx0IHZhbHVlIGZvciB0aGUgU3RpbXVsdXMgVmFsdWUgXCIke3Byb3BlcnR5UGF0aH1cIiBtdXN0IG1hdGNoIHRoZSBkZWZpbmVkIHR5cGUgXCIke3R5cGVGcm9tT2JqZWN0fVwiLiBUaGUgcHJvdmlkZWQgZGVmYXVsdCB2YWx1ZSBvZiBcIiR7dHlwZU9iamVjdC5kZWZhdWx0fVwiIGlzIG9mIHR5cGUgXCIke3R5cGVGcm9tRGVmYXVsdFZhbHVlfVwiLmApO1xuICAgIH1cbiAgICBpZiAoZnVsbE9iamVjdClcbiAgICAgICAgcmV0dXJuIHR5cGVGcm9tT2JqZWN0O1xufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVEZWZpbml0aW9uKHBheWxvYWQpIHtcbiAgICBjb25zdCB7IGNvbnRyb2xsZXIsIHRva2VuLCB0eXBlRGVmaW5pdGlvbiB9ID0gcGF5bG9hZDtcbiAgICBjb25zdCB0eXBlT2JqZWN0ID0geyBjb250cm9sbGVyLCB0b2tlbiwgdHlwZU9iamVjdDogdHlwZURlZmluaXRpb24gfTtcbiAgICBjb25zdCB0eXBlRnJvbU9iamVjdCA9IHBhcnNlVmFsdWVUeXBlT2JqZWN0KHR5cGVPYmplY3QpO1xuICAgIGNvbnN0IHR5cGVGcm9tRGVmYXVsdFZhbHVlID0gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KHR5cGVEZWZpbml0aW9uKTtcbiAgICBjb25zdCB0eXBlRnJvbUNvbnN0YW50ID0gcGFyc2VWYWx1ZVR5cGVDb25zdGFudCh0eXBlRGVmaW5pdGlvbik7XG4gICAgY29uc3QgdHlwZSA9IHR5cGVGcm9tT2JqZWN0IHx8IHR5cGVGcm9tRGVmYXVsdFZhbHVlIHx8IHR5cGVGcm9tQ29uc3RhbnQ7XG4gICAgaWYgKHR5cGUpXG4gICAgICAgIHJldHVybiB0eXBlO1xuICAgIGNvbnN0IHByb3BlcnR5UGF0aCA9IGNvbnRyb2xsZXIgPyBgJHtjb250cm9sbGVyfS4ke3R5cGVEZWZpbml0aW9ufWAgOiB0b2tlbjtcbiAgICB0aHJvdyBuZXcgRXJyb3IoYFVua25vd24gdmFsdWUgdHlwZSBcIiR7cHJvcGVydHlQYXRofVwiIGZvciBcIiR7dG9rZW59XCIgdmFsdWVgKTtcbn1cbmZ1bmN0aW9uIGRlZmF1bHRWYWx1ZUZvckRlZmluaXRpb24odHlwZURlZmluaXRpb24pIHtcbiAgICBjb25zdCBjb25zdGFudCA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZURlZmluaXRpb24pO1xuICAgIGlmIChjb25zdGFudClcbiAgICAgICAgcmV0dXJuIGRlZmF1bHRWYWx1ZXNCeVR5cGVbY29uc3RhbnRdO1xuICAgIGNvbnN0IGhhc0RlZmF1bHQgPSBoYXNQcm9wZXJ0eSh0eXBlRGVmaW5pdGlvbiwgXCJkZWZhdWx0XCIpO1xuICAgIGNvbnN0IGhhc1R5cGUgPSBoYXNQcm9wZXJ0eSh0eXBlRGVmaW5pdGlvbiwgXCJ0eXBlXCIpO1xuICAgIGNvbnN0IHR5cGVPYmplY3QgPSB0eXBlRGVmaW5pdGlvbjtcbiAgICBpZiAoaGFzRGVmYXVsdClcbiAgICAgICAgcmV0dXJuIHR5cGVPYmplY3QuZGVmYXVsdDtcbiAgICBpZiAoaGFzVHlwZSkge1xuICAgICAgICBjb25zdCB7IHR5cGUgfSA9IHR5cGVPYmplY3Q7XG4gICAgICAgIGNvbnN0IGNvbnN0YW50RnJvbVR5cGUgPSBwYXJzZVZhbHVlVHlwZUNvbnN0YW50KHR5cGUpO1xuICAgICAgICBpZiAoY29uc3RhbnRGcm9tVHlwZSlcbiAgICAgICAgICAgIHJldHVybiBkZWZhdWx0VmFsdWVzQnlUeXBlW2NvbnN0YW50RnJvbVR5cGVdO1xuICAgIH1cbiAgICByZXR1cm4gdHlwZURlZmluaXRpb247XG59XG5mdW5jdGlvbiB2YWx1ZURlc2NyaXB0b3JGb3JUb2tlbkFuZFR5cGVEZWZpbml0aW9uKHBheWxvYWQpIHtcbiAgICBjb25zdCB7IHRva2VuLCB0eXBlRGVmaW5pdGlvbiB9ID0gcGF5bG9hZDtcbiAgICBjb25zdCBrZXkgPSBgJHtkYXNoZXJpemUodG9rZW4pfS12YWx1ZWA7XG4gICAgY29uc3QgdHlwZSA9IHBhcnNlVmFsdWVUeXBlRGVmaW5pdGlvbihwYXlsb2FkKTtcbiAgICByZXR1cm4ge1xuICAgICAgICB0eXBlLFxuICAgICAgICBrZXksXG4gICAgICAgIG5hbWU6IGNhbWVsaXplKGtleSksXG4gICAgICAgIGdldCBkZWZhdWx0VmFsdWUoKSB7XG4gICAgICAgICAgICByZXR1cm4gZGVmYXVsdFZhbHVlRm9yRGVmaW5pdGlvbih0eXBlRGVmaW5pdGlvbik7XG4gICAgICAgIH0sXG4gICAgICAgIGdldCBoYXNDdXN0b21EZWZhdWx0VmFsdWUoKSB7XG4gICAgICAgICAgICByZXR1cm4gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KHR5cGVEZWZpbml0aW9uKSAhPT0gdW5kZWZpbmVkO1xuICAgICAgICB9LFxuICAgICAgICByZWFkZXI6IHJlYWRlcnNbdHlwZV0sXG4gICAgICAgIHdyaXRlcjogd3JpdGVyc1t0eXBlXSB8fCB3cml0ZXJzLmRlZmF1bHQsXG4gICAgfTtcbn1cbmNvbnN0IGRlZmF1bHRWYWx1ZXNCeVR5cGUgPSB7XG4gICAgZ2V0IGFycmF5KCkge1xuICAgICAgICByZXR1cm4gW107XG4gICAgfSxcbiAgICBib29sZWFuOiBmYWxzZSxcbiAgICBudW1iZXI6IDAsXG4gICAgZ2V0IG9iamVjdCgpIHtcbiAgICAgICAgcmV0dXJuIHt9O1xuICAgIH0sXG4gICAgc3RyaW5nOiBcIlwiLFxufTtcbmNvbnN0IHJlYWRlcnMgPSB7XG4gICAgYXJyYXkodmFsdWUpIHtcbiAgICAgICAgY29uc3QgYXJyYXkgPSBKU09OLnBhcnNlKHZhbHVlKTtcbiAgICAgICAgaWYgKCFBcnJheS5pc0FycmF5KGFycmF5KSkge1xuICAgICAgICAgICAgdGhyb3cgbmV3IFR5cGVFcnJvcihgZXhwZWN0ZWQgdmFsdWUgb2YgdHlwZSBcImFycmF5XCIgYnV0IGluc3RlYWQgZ290IHZhbHVlIFwiJHt2YWx1ZX1cIiBvZiB0eXBlIFwiJHtwYXJzZVZhbHVlVHlwZURlZmF1bHQoYXJyYXkpfVwiYCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGFycmF5O1xuICAgIH0sXG4gICAgYm9vbGVhbih2YWx1ZSkge1xuICAgICAgICByZXR1cm4gISh2YWx1ZSA9PSBcIjBcIiB8fCBTdHJpbmcodmFsdWUpLnRvTG93ZXJDYXNlKCkgPT0gXCJmYWxzZVwiKTtcbiAgICB9LFxuICAgIG51bWJlcih2YWx1ZSkge1xuICAgICAgICByZXR1cm4gTnVtYmVyKHZhbHVlLnJlcGxhY2UoL18vZywgXCJcIikpO1xuICAgIH0sXG4gICAgb2JqZWN0KHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IG9iamVjdCA9IEpTT04ucGFyc2UodmFsdWUpO1xuICAgICAgICBpZiAob2JqZWN0ID09PSBudWxsIHx8IHR5cGVvZiBvYmplY3QgIT0gXCJvYmplY3RcIiB8fCBBcnJheS5pc0FycmF5KG9iamVjdCkpIHtcbiAgICAgICAgICAgIHRocm93IG5ldyBUeXBlRXJyb3IoYGV4cGVjdGVkIHZhbHVlIG9mIHR5cGUgXCJvYmplY3RcIiBidXQgaW5zdGVhZCBnb3QgdmFsdWUgXCIke3ZhbHVlfVwiIG9mIHR5cGUgXCIke3BhcnNlVmFsdWVUeXBlRGVmYXVsdChvYmplY3QpfVwiYCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIG9iamVjdDtcbiAgICB9LFxuICAgIHN0cmluZyh2YWx1ZSkge1xuICAgICAgICByZXR1cm4gdmFsdWU7XG4gICAgfSxcbn07XG5jb25zdCB3cml0ZXJzID0ge1xuICAgIGRlZmF1bHQ6IHdyaXRlU3RyaW5nLFxuICAgIGFycmF5OiB3cml0ZUpTT04sXG4gICAgb2JqZWN0OiB3cml0ZUpTT04sXG59O1xuZnVuY3Rpb24gd3JpdGVKU09OKHZhbHVlKSB7XG4gICAgcmV0dXJuIEpTT04uc3RyaW5naWZ5KHZhbHVlKTtcbn1cbmZ1bmN0aW9uIHdyaXRlU3RyaW5nKHZhbHVlKSB7XG4gICAgcmV0dXJuIGAke3ZhbHVlfWA7XG59XG5cbmNsYXNzIENvbnRyb2xsZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICB9XG4gICAgc3RhdGljIGdldCBzaG91bGRMb2FkKCkge1xuICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICB9XG4gICAgc3RhdGljIGFmdGVyTG9hZChfaWRlbnRpZmllciwgX2FwcGxpY2F0aW9uKSB7XG4gICAgICAgIHJldHVybjtcbiAgICB9XG4gICAgZ2V0IGFwcGxpY2F0aW9uKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgc2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NvcGU7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHRhcmdldHMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLnRhcmdldHM7XG4gICAgfVxuICAgIGdldCBvdXRsZXRzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5vdXRsZXRzO1xuICAgIH1cbiAgICBnZXQgY2xhc3NlcygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuY2xhc3NlcztcbiAgICB9XG4gICAgZ2V0IGRhdGEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmRhdGE7XG4gICAgfVxuICAgIGluaXRpYWxpemUoKSB7XG4gICAgfVxuICAgIGNvbm5lY3QoKSB7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3QoKSB7XG4gICAgfVxuICAgIGRpc3BhdGNoKGV2ZW50TmFtZSwgeyB0YXJnZXQgPSB0aGlzLmVsZW1lbnQsIGRldGFpbCA9IHt9LCBwcmVmaXggPSB0aGlzLmlkZW50aWZpZXIsIGJ1YmJsZXMgPSB0cnVlLCBjYW5jZWxhYmxlID0gdHJ1ZSwgfSA9IHt9KSB7XG4gICAgICAgIGNvbnN0IHR5cGUgPSBwcmVmaXggPyBgJHtwcmVmaXh9OiR7ZXZlbnROYW1lfWAgOiBldmVudE5hbWU7XG4gICAgICAgIGNvbnN0IGV2ZW50ID0gbmV3IEN1c3RvbUV2ZW50KHR5cGUsIHsgZGV0YWlsLCBidWJibGVzLCBjYW5jZWxhYmxlIH0pO1xuICAgICAgICB0YXJnZXQuZGlzcGF0Y2hFdmVudChldmVudCk7XG4gICAgICAgIHJldHVybiBldmVudDtcbiAgICB9XG59XG5Db250cm9sbGVyLmJsZXNzaW5ncyA9IFtcbiAgICBDbGFzc1Byb3BlcnRpZXNCbGVzc2luZyxcbiAgICBUYXJnZXRQcm9wZXJ0aWVzQmxlc3NpbmcsXG4gICAgVmFsdWVQcm9wZXJ0aWVzQmxlc3NpbmcsXG4gICAgT3V0bGV0UHJvcGVydGllc0JsZXNzaW5nLFxuXTtcbkNvbnRyb2xsZXIudGFyZ2V0cyA9IFtdO1xuQ29udHJvbGxlci5vdXRsZXRzID0gW107XG5Db250cm9sbGVyLnZhbHVlcyA9IHt9O1xuXG5leHBvcnQgeyBBcHBsaWNhdGlvbiwgQXR0cmlidXRlT2JzZXJ2ZXIsIENvbnRleHQsIENvbnRyb2xsZXIsIEVsZW1lbnRPYnNlcnZlciwgSW5kZXhlZE11bHRpbWFwLCBNdWx0aW1hcCwgU2VsZWN0b3JPYnNlcnZlciwgU3RyaW5nTWFwT2JzZXJ2ZXIsIFRva2VuTGlzdE9ic2VydmVyLCBWYWx1ZUxpc3RPYnNlcnZlciwgYWRkLCBkZWZhdWx0U2NoZW1hLCBkZWwsIGZldGNoLCBwcnVuZSB9O1xuIiwgImltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tIFwiQGhvdHdpcmVkL3N0aW11bHVzXCJcblxuLyoqXG4gKiBTdGltdWx1cyBDb250cm9sbGVyIGZvciBTdHJpcGUgUGF5bWVudCBFbGVtZW50IG9uIE9yZGVyIFBhZ2VcbiAqXG4gKiBIYW5kbGVzIFN0cmlwZSBwYXltZW50IGZvcm0gaW5pdGlhbGl6YXRpb24gYW5kIHN1Ym1pc3Npb24gb24gdGhlIG9yZGVyIGNvbmZpcm1hdGlvbiBwYWdlXG4gKlxuICogVXNhZ2UgaW4gVHdpZzpcbiAqIDxkaXYgZGF0YS1jb250cm9sbGVyPVwic3RyaXBlLW9yZGVyXCJcbiAqICAgICAgZGF0YS1zdHJpcGUtb3JkZXItcHVibGlzaGFibGUta2V5LXZhbHVlPVwicGtfLi4uXCJcbiAqICAgICAgZGF0YS1zdHJpcGUtb3JkZXItY2xpZW50LXNlY3JldC12YWx1ZT1cInBpXy4uLl9zZWNyZXRfLi4uXCI+XG4gKiAgIDxkaXYgaWQ9XCJwYXltZW50LWVsZW1lbnRcIj48L2Rpdj5cbiAqICAgPGRpdiBpZD1cInBheW1lbnQtZXJyb3JzXCIgc3R5bGU9XCJkaXNwbGF5Om5vbmVcIj5cbiAqICAgICA8c3BhbiBkYXRhLXN0cmlwZS1vcmRlci10YXJnZXQ9XCJlcnJvck1lc3NhZ2VcIj48L3NwYW4+XG4gKiAgIDwvZGl2PlxuICogPC9kaXY+XG4gKi9cbmV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgQ29udHJvbGxlciB7XG4gIHN0YXRpYyB2YWx1ZXMgPSB7XG4gICAgcHVibGlzaGFibGVLZXk6IFN0cmluZyxcbiAgICBjbGllbnRTZWNyZXQ6IFN0cmluZ1xuICB9XG5cbiAgc3RhdGljIHRhcmdldHMgPSBbXCJlcnJvck1lc3NhZ2VcIiwgXCJsb2FkaW5nXCJdXG5cbiAgY29ubmVjdCgpIHtcbiAgICBjb25zb2xlLmxvZygnU3RyaXBlIE9yZGVyIGNvbnRyb2xsZXIgY29ubmVjdGVkJywge1xuICAgICAgaGFzUHVibGlzaGFibGVLZXk6ICEhdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlLFxuICAgICAgcHVibGlzaGFibGVLZXk6IHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSA/IHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZS5zdWJzdHJpbmcoMCwgMTApICsgJy4uLicgOiAnbWlzc2luZycsXG4gICAgfSlcblxuICAgIC8vIEdldCBkZWJ1ZyBpbmZvIGZyb20gZWxlbWVudFxuICAgIGNvbnN0IGRlYnVnSW5mbyA9IHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUoJ2RhdGEtZGVidWctaW5mbycpXG4gICAgaWYgKGRlYnVnSW5mbykge1xuICAgICAgY29uc29sZS5sb2coJ0RlYnVnIGluZm86JywgZGVidWdJbmZvKVxuICAgIH1cblxuICAgIC8vIFZhbGlkYXRlIHJlcXVpcmVkIGNvbmZpZ3VyYXRpb25cbiAgICBpZiAoIXRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSkge1xuICAgICAgY29uc29sZS5lcnJvcignU3RyaXBlIHB1Ymxpc2hhYmxlIGtleSBub3QgY29uZmlndXJlZCcpXG4gICAgICB0aGlzLnNob3dFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uQ09ORklHX0VSUk9SIHx8ICdTdHJpcGUgY29uZmlndXJhdGlvbiBlcnJvci4gUGxlYXNlIGNvbnRhY3Qgc3VwcG9ydC4nKVxuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgLy8gV2FpdCBmb3IgU3RyaXBlLmpzIHRvIGxvYWRcbiAgICB0aGlzLmluaXRpYWxpemVTdHJpcGUoKVxuICB9XG5cbiAgZGlzY29ubmVjdCgpIHtcbiAgICAvLyBDbGVhbnVwIGlmIG5lZWRlZFxuICAgIGlmICh0aGlzLnBheW1lbnRFbGVtZW50KSB7XG4gICAgICB0aGlzLnBheW1lbnRFbGVtZW50LnVubW91bnQoKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBJbml0aWFsaXplIFN0cmlwZSBhbmQgbW91bnQgUGF5bWVudCBFbGVtZW50XG4gICAqL1xuICBhc3luYyBpbml0aWFsaXplU3RyaXBlKCkge1xuICAgIC8vIFdhaXQgZm9yIFN0cmlwZS5qcyB0byBiZSBhdmFpbGFibGVcbiAgICBpZiAodHlwZW9mIFN0cmlwZSA9PT0gJ3VuZGVmaW5lZCcpIHtcbiAgICAgIGNvbnNvbGUubG9nKCdXYWl0aW5nIGZvciBTdHJpcGUuanMgdG8gbG9hZC4uLicpXG4gICAgICBhd2FpdCB0aGlzLndhaXRGb3JTdHJpcGUoKVxuICAgIH1cblxuICAgIHRyeSB7XG4gICAgICAvLyBJbml0aWFsaXplIFN0cmlwZVxuICAgICAgdGhpcy5zdHJpcGUgPSBTdHJpcGUodGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlKVxuXG4gICAgICAvLyBDcmVhdGUgRWxlbWVudHMgd2l0aCBzdHlsaW5nXG4gICAgICBjb25zdCBhcHBlYXJhbmNlID0ge1xuICAgICAgICB0aGVtZTogJ3N0cmlwZScsXG4gICAgICAgIHZhcmlhYmxlczoge1xuICAgICAgICAgIGNvbG9yUHJpbWFyeTogJyMwNTcwZGUnLFxuICAgICAgICAgIGNvbG9yQmFja2dyb3VuZDogJyNmZmZmZmYnLFxuICAgICAgICAgIGNvbG9yVGV4dDogJyMzMDMxM2QnLFxuICAgICAgICAgIGZvbnRGYW1pbHk6ICdzeXN0ZW0tdWksIHNhbnMtc2VyaWYnLFxuICAgICAgICAgIGJvcmRlclJhZGl1czogJzRweCdcbiAgICAgICAgfVxuICAgICAgfVxuXG4gICAgICB0aGlzLmVsZW1lbnRzID0gdGhpcy5zdHJpcGUuZWxlbWVudHMoe1xuICAgICAgICBhcHBlYXJhbmNlOiBhcHBlYXJhbmNlXG4gICAgICB9KVxuXG4gICAgICB0aGlzLmNhcmQgPSB0aGlzLmVsZW1lbnRzLmNyZWF0ZSgnY2FyZCcpO1xuICAgICAgdGhpcy5jYXJkLm1vdW50KCcjY2FyZC1lbGVtZW50Jyk7XG5cbiAgICAgIGNvbnNvbGUubG9nKCdTdHJpcGUgUGF5bWVudCBFbGVtZW50IGluaXRpYWxpemVkIHN1Y2Nlc3NmdWxseScpXG5cbiAgICB9IGNhdGNoIChlcnJvcikge1xuICAgICAgY29uc29sZS5lcnJvcignRmFpbGVkIHRvIGluaXRpYWxpemUgU3RyaXBlOicsIGVycm9yKVxuICAgICAgdGhpcy5zaG93RXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LklOSVRfRkFJTEVEIHx8ICdGYWlsZWQgdG8gaW5pdGlhbGl6ZSBwYXltZW50IGZvcm0uIFBsZWFzZSByZWZyZXNoIHRoZSBwYWdlLicpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFdhaXQgZm9yIFN0cmlwZS5qcyBsaWJyYXJ5IHRvIGxvYWRcbiAgICogQHJldHVybnMge1Byb21pc2V9XG4gICAqL1xuICB3YWl0Rm9yU3RyaXBlKCkge1xuICAgIHJldHVybiBuZXcgUHJvbWlzZSgocmVzb2x2ZSkgPT4ge1xuICAgICAgY29uc3QgY2hlY2tTdHJpcGUgPSAoKSA9PiB7XG4gICAgICAgIGlmICh0eXBlb2YgU3RyaXBlICE9PSAndW5kZWZpbmVkJykge1xuICAgICAgICAgIHJlc29sdmUoKVxuICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgIHNldFRpbWVvdXQoY2hlY2tTdHJpcGUsIDEwMClcbiAgICAgICAgfVxuICAgICAgfVxuICAgICAgY2hlY2tTdHJpcGUoKVxuICAgIH0pXG4gIH1cblxuICAvKipcbiAgICogU2hvdyBsb2FkaW5nIGluZGljYXRvclxuICAgKi9cbiAgc2hvd0xvYWRpbmcoKSB7XG4gICAgaWYgKHRoaXMuaGFzTG9hZGluZ1RhcmdldCkge1xuICAgICAgdGhpcy5sb2FkaW5nVGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFNob3cgZXJyb3IgbWVzc2FnZVxuICAgKiBAcGFyYW0ge1N0cmluZ30gbWVzc2FnZVxuICAgKi9cbiAgc2hvd0Vycm9yKG1lc3NhZ2UpIHtcbiAgICBjb25zdCBlcnJvckRpdiA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdwYXltZW50LWVycm9ycycpXG4gICAgaWYgKGVycm9yRGl2ICYmIHRoaXMuaGFzRXJyb3JNZXNzYWdlVGFyZ2V0KSB7XG4gICAgICBlcnJvckRpdi5zdHlsZS5kaXNwbGF5ID0gJ2Jsb2NrJ1xuICAgICAgdGhpcy5lcnJvck1lc3NhZ2VUYXJnZXQudGV4dENvbnRlbnQgPSBtZXNzYWdlXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhpZGUgZXJyb3IgbWVzc2FnZVxuICAgKi9cbiAgaGlkZUVycm9yKCkge1xuICAgIGNvbnN0IGVycm9yRGl2ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ3BheW1lbnQtZXJyb3JzJylcbiAgICBpZiAoZXJyb3JEaXYpIHtcbiAgICAgIGVycm9yRGl2LnN0eWxlLmRpc3BsYXkgPSAnbm9uZSdcbiAgICAgIGlmICh0aGlzLmhhc0Vycm9yTWVzc2FnZVRhcmdldCkge1xuICAgICAgICB0aGlzLmVycm9yTWVzc2FnZVRhcmdldC50ZXh0Q29udGVudCA9ICcnXG4gICAgICB9XG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhpZGUgbG9hZGluZyBpbmRpY2F0b3JcbiAgICovXG4gIGhpZGVMb2FkaW5nKCkge1xuICAgIGlmICh0aGlzLmhhc0xvYWRpbmdUYXJnZXQpIHtcbiAgICAgIHRoaXMubG9hZGluZ1RhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ25vbmUnXG4gICAgfVxuICB9XG5cbn1cbiIsICJpbXBvcnQgeyBDb250cm9sbGVyIH0gZnJvbSBcIkBob3R3aXJlZC9zdGltdWx1c1wiXG5cbi8qKlxuICogU3RpbXVsdXMgQ29udHJvbGxlciBmb3IgT3JkZXIgU3VibWl0IEJ1dHRvblxuICpcbiAqIEhhbmRsZXMgb3JkZXIgc3VibWlzc2lvbiBvbiB0aGUgY2hlY2tvdXQgb3JkZXIgcGFnZS5cbiAqIFN1cHBvcnRzIHR3byBwYXltZW50IGZsb3dzOlxuICogMS4gU3RyaXBlIENoZWNrb3V0IChob3N0ZWQgcGFnZSkgLSBmb3Igd2FsbGV0IHBheW1lbnRzXG4gKiAyLiBQYXltZW50IEludGVudCAoY2FyZCBlbGVtZW50KSAtIGZvciBjYXJkIHBheW1lbnRzXG4gKlxuICogVXNhZ2UgaW4gVHdpZzpcbiAqIDxidXR0b24gZGF0YS1jb250cm9sbGVyPVwib3JkZXItc3VibWl0XCJcbiAqICAgICAgICAgZGF0YS1hY3Rpb249XCJjbGljay0+b3JkZXItc3VibWl0I2hhbmRsZVN1Ym1pdFwiXG4gKiAgICAgICAgIGRhdGEtb3JkZXItc3VibWl0LXVybC12YWx1ZT1cIi4uLlwiXG4gKiAgICAgICAgIGRhdGEtb3JkZXItc3VibWl0LXBheW1lbnQtdHlwZS12YWx1ZT1cIndhbGxldHxjYXJkXCJcbiAqICAgICAgICAgdHlwZT1cImJ1dHRvblwiPlxuICogICBTdWJtaXQgT3JkZXJcbiAqIDwvYnV0dG9uPlxuICovXG5leHBvcnQgZGVmYXVsdCBjbGFzcyBleHRlbmRzIENvbnRyb2xsZXIge1xuICBzdGF0aWMgdGFyZ2V0cyA9IFtcInN0YXR1c1wiXVxuICBzdGF0aWMgdmFsdWVzID0ge1xuICAgIHVybDogU3RyaW5nLFxuICAgIHBheW1lbnRUeXBlOiBTdHJpbmcsXG4gICAgcHVibGlzaGFibGVLZXk6IFN0cmluZ1xuICB9XG5cbiAgLyoqXG4gICAqIENhbGxlZCB3aGVuIGNvbnRyb2xsZXIgaXMgY29ubmVjdGVkIHRvIERPTVxuICAgKi9cbiAgY29ubmVjdCgpIHtcbiAgICBjb25zb2xlLmxvZygnT3JkZXIgU3VibWl0IGNvbnRyb2xsZXIgY29ubmVjdGVkJylcbiAgICBjb25zb2xlLmxvZygnQnV0dG9uIGVsZW1lbnQ6JywgdGhpcy5lbGVtZW50KVxuICB9XG5cbiAgLyoqXG4gICAqIENhbGxlZCB3aGVuIGNvbnRyb2xsZXIgaXMgZGlzY29ubmVjdGVkIGZyb20gRE9NXG4gICAqL1xuICBkaXNjb25uZWN0KCkge1xuICAgIGNvbnNvbGUubG9nKCdPcmRlciBTdWJtaXQgY29udHJvbGxlciBkaXNjb25uZWN0ZWQnKVxuICB9XG5cbiAgLyoqXG4gICAqIEdldCB0aGUgc3RyaXBlLW9yZGVyIGNvbnRyb2xsZXIgaW5zdGFuY2VcbiAgICogQHJldHVybnMge0NvbnRyb2xsZXJ8bnVsbH1cbiAgICovXG4gIGdldFN0cmlwZU9yZGVyQ29udHJvbGxlcigpIHtcbiAgICBjb25zdCBjYXJkRWxlbWVudCA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdjYXJkLWVsZW1lbnQnKVxuICAgIGlmICghY2FyZEVsZW1lbnQpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ0NhcmQgZWxlbWVudCBub3QgZm91bmQnKVxuICAgICAgcmV0dXJuIG51bGxcbiAgICB9XG5cbiAgICBjb25zdCBjb250cm9sbGVyID0gdGhpcy5hcHBsaWNhdGlvbi5nZXRDb250cm9sbGVyRm9yRWxlbWVudEFuZElkZW50aWZpZXIoXG4gICAgICBjYXJkRWxlbWVudCxcbiAgICAgICdzdHJpcGUtb3JkZXInXG4gICAgKVxuXG4gICAgaWYgKCFjb250cm9sbGVyKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdTdHJpcGUgb3JkZXIgY29udHJvbGxlciBub3QgZm91bmQgb24gY2FyZCBlbGVtZW50JylcbiAgICAgIHJldHVybiBudWxsXG4gICAgfVxuXG4gICAgY29uc29sZS5sb2coJ0ZvdW5kIHN0cmlwZS1vcmRlciBjb250cm9sbGVyOicsIGNvbnRyb2xsZXIpXG4gICAgcmV0dXJuIGNvbnRyb2xsZXJcbiAgfVxuXG4gIC8qKlxuICAgKiBIYW5kbGUgb3JkZXIgc3VibWl0IGJ1dHRvbiBjbGlja1xuICAgKiBSb3V0ZXMgdG8gYXBwcm9wcmlhdGUgcGF5bWVudCBmbG93IGJhc2VkIG9uIHBheW1lbnQgdHlwZVxuICAgKiBAcGFyYW0ge0V2ZW50fSBldmVudCAtIFRoZSBjbGljayBldmVudFxuICAgKi9cbiAgYXN5bmMgaGFuZGxlU3VibWl0KGV2ZW50KSB7XG4gICAgZXZlbnQucHJldmVudERlZmF1bHQoKVxuXG4gICAgY29uc29sZS5sb2coJ09yZGVyIHN1Ym1pdCBidXR0b24gY2xpY2tlZCcsIHtcbiAgICAgIGJ1dHRvbklkOiB0aGlzLmVsZW1lbnQuaWQsXG4gICAgICBwYXltZW50VHlwZTogdGhpcy5wYXltZW50VHlwZVZhbHVlLFxuICAgICAgdGltZXN0YW1wOiBuZXcgRGF0ZSgpLnRvSVNPU3RyaW5nKClcbiAgICB9KVxuXG4gICAgdGhpcy5zaG93TG9hZGluZygpXG5cbiAgICB0cnkge1xuICAgICAgLy8gUm91dGUgdG8gYXBwcm9wcmlhdGUgcGF5bWVudCBmbG93XG4gICAgICBpZiAodGhpcy5wYXltZW50VHlwZVZhbHVlID09PSAnd2FsbGV0Jykge1xuICAgICAgICBhd2FpdCB0aGlzLmhhbmRsZVN0cmlwZUNoZWNrb3V0KClcbiAgICAgIH0gZWxzZSB7XG4gICAgICAgIGF3YWl0IHRoaXMuaGFuZGxlUGF5bWVudEludGVudCgpXG4gICAgICB9XG4gICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ09yZGVyIHN1Ym1pc3Npb24gZmFpbGVkJywgZXJyb3IpXG4gICAgICB0aGlzLnNob3dFcnJvcihlcnJvci5tZXNzYWdlIHx8IHdpbmRvdy5vU3RyaXBlPy5pMThuPy5QQVlNRU5UX0ZBSUxFRCB8fCAnUGF5bWVudCBwcm9jZXNzaW5nIGZhaWxlZCcpXG4gICAgfSBmaW5hbGx5IHtcbiAgICAgIHRoaXMuaGlkZUxvYWRpbmcoKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBIYW5kbGUgU3RyaXBlIENoZWNrb3V0IGZsb3cgKGhvc3RlZCBwYXltZW50IHBhZ2UpXG4gICAqIFVzZWQgZm9yIHdhbGxldCBwYXltZW50cyAoQXBwbGUgUGF5LCBHb29nbGUgUGF5KVxuICAgKi9cbiAgYXN5bmMgaGFuZGxlU3RyaXBlQ2hlY2tvdXQoKSB7XG4gICAgaWYgKCF3aW5kb3cuU3RyaXBlKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LkpTX05PVF9MT0FERUQgfHwgJ1N0cmlwZS5qcyBub3QgbG9hZGVkJylcbiAgICB9XG5cbiAgICAvLyBHZXQgU3RyaXBlIHB1Ymxpc2hhYmxlIGtleSBmcm9tIFN0aW11bHVzIHZhbHVlXG4gICAgaWYgKCF0aGlzLmhhc1B1Ymxpc2hhYmxlS2V5VmFsdWUgfHwgIXRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSkge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5LRVlfTk9UX0NPTkZJR1VSRUQgfHwgJ1N0cmlwZSBwdWJsaXNoYWJsZSBrZXkgbm90IGNvbmZpZ3VyZWQnKVxuICAgIH1cblxuICAgIGNvbnN0IHN0cmlwZSA9IFN0cmlwZSh0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUpXG5cbiAgICB0aGlzLnNldFN0YXR1cyh3aW5kb3cub1N0cmlwZT8uaTE4bj8uQ1JFQVRJTkdfU0VTU0lPTiB8fCAnQ3JlYXRpbmcgY2hlY2tvdXQgc2Vzc2lvbi4uLicpXG5cbiAgICAvLyBDcmVhdGUgQ2hlY2tvdXQgU2Vzc2lvblxuICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2godGhpcy51cmxWYWx1ZSwge1xuICAgICAgbWV0aG9kOiAnUE9TVCcsXG4gICAgICBoZWFkZXJzOiB7XG4gICAgICAgICdDb250ZW50LVR5cGUnOiAnYXBwbGljYXRpb24vanNvbidcbiAgICAgIH0sXG4gICAgICBib2R5OiBKU09OLnN0cmluZ2lmeSh7XG4gICAgICAgIGNhcHR1cmU6ICdhdXRvbWF0aWMnIC8vIENhbiBiZSBtYWRlIGNvbmZpZ3VyYWJsZVxuICAgICAgfSksXG4gICAgICBjcmVkZW50aWFsczogJ3NhbWUtb3JpZ2luJ1xuICAgIH0pXG5cbiAgICBpZiAoIXJlc3BvbnNlLm9rKSB7XG4gICAgICBjb25zdCBlcnJvckRhdGEgPSBhd2FpdCByZXNwb25zZS5qc29uKCkuY2F0Y2goKCkgPT4gKHt9KSlcbiAgICAgIHRocm93IG5ldyBFcnJvcihlcnJvckRhdGEuZXJyb3IgfHwgd2luZG93Lm9TdHJpcGU/LmkxOG4/LlNFU1NJT05fRkFJTEVEIHx8ICdGYWlsZWQgdG8gY3JlYXRlIGNoZWNrb3V0IHNlc3Npb24nKVxuICAgIH1cblxuICAgIGNvbnN0IGRhdGEgPSBhd2FpdCByZXNwb25zZS5qc29uKClcblxuICAgIGlmICghZGF0YS5pZCkge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5TRVNTSU9OX0lOVkFMSUQgfHwgJ0ludmFsaWQgY2hlY2tvdXQgc2Vzc2lvbiByZXNwb25zZScpXG4gICAgfVxuXG4gICAgY29uc29sZS5sb2coJ0NoZWNrb3V0IFNlc3Npb24gY3JlYXRlZDonLCBkYXRhLmlkLCAnVVJMOicsIGRhdGEudXJsKVxuICAgIGNvbnNvbGUubG9nKCdEZWJ1ZyBpbmZvOicsIGRhdGEuX2RlYnVnKVxuXG4gICAgLy8gUmVkaXJlY3QgdG8gU3RyaXBlIENoZWNrb3V0IHVzaW5nIGRpcmVjdCBVUkwgKG1vcmUgcmVsaWFibGUpXG4gICAgaWYgKGRhdGEudXJsKSB7XG4gICAgICB3aW5kb3cubG9jYXRpb24uaHJlZiA9IGRhdGEudXJsXG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICAvLyBGYWxsYmFjayB0byByZWRpcmVjdFRvQ2hlY2tvdXQgaWYgVVJMIG5vdCBhdmFpbGFibGVcbiAgICBjb25zdCB7IGVycm9yIH0gPSBhd2FpdCBzdHJpcGUucmVkaXJlY3RUb0NoZWNrb3V0KHtcbiAgICAgIHNlc3Npb25JZDogZGF0YS5pZFxuICAgIH0pXG5cbiAgICBpZiAoZXJyb3IpIHtcbiAgICAgIHRocm93IGVycm9yXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBQYXltZW50IEludGVudCBmbG93IChjYXJkIGVsZW1lbnQpXG4gICAqIFVzZWQgZm9yIGNhcmQgcGF5bWVudHNcbiAgICovXG4gIGFzeW5jIGhhbmRsZVBheW1lbnRJbnRlbnQoKSB7XG4gICAgLy8gR2V0IHN0cmlwZS1vcmRlciBjb250cm9sbGVyIGluc3RhbmNlXG4gICAgY29uc3Qgc3RyaXBlT3JkZXJDb250cm9sbGVyID0gdGhpcy5nZXRTdHJpcGVPcmRlckNvbnRyb2xsZXIoKVxuXG4gICAgaWYgKCFzdHJpcGVPcmRlckNvbnRyb2xsZXIpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uQ09OVFJPTExFUl9OT1RfRk9VTkQgfHwgJ1N0cmlwZSBwYXltZW50IGNvbnRyb2xsZXIgbm90IGZvdW5kLiBQbGVhc2UgcmVmcmVzaCB0aGUgcGFnZS4nKVxuICAgIH1cblxuICAgIC8vIFZlcmlmeSBjYXJkIGVsZW1lbnQgYW5kIHN0cmlwZSBhcmUgYXZhaWxhYmxlXG4gICAgaWYgKCFzdHJpcGVPcmRlckNvbnRyb2xsZXIuY2FyZCB8fCAhc3RyaXBlT3JkZXJDb250cm9sbGVyLnN0cmlwZSkge1xuICAgICAgY29uc29sZS5lcnJvcignUGF5bWVudCBmb3JtIG5vdCByZWFkeTonLCB7XG4gICAgICAgIGhhc0NhcmQ6ICEhc3RyaXBlT3JkZXJDb250cm9sbGVyLmNhcmQsXG4gICAgICAgIGhhc1N0cmlwZTogISFzdHJpcGVPcmRlckNvbnRyb2xsZXIuc3RyaXBlXG4gICAgICB9KVxuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5GT1JNX05PVF9SRUFEWSB8fCAnUGF5bWVudCBmb3JtIG5vdCBpbml0aWFsaXplZC4gUGxlYXNlIHJlZnJlc2ggdGhlIHBhZ2UuJylcbiAgICB9XG5cbiAgICBjb25zb2xlLmxvZygnU3RyaXBlIGNvbnRyb2xsZXIgcmVhZHk6Jywge1xuICAgICAgaGFzQ2FyZDogISFzdHJpcGVPcmRlckNvbnRyb2xsZXIuY2FyZCxcbiAgICAgIGhhc1N0cmlwZTogISFzdHJpcGVPcmRlckNvbnRyb2xsZXIuc3RyaXBlXG4gICAgfSlcblxuICAgIGNvbnN0IHBheW1lbnRJbnRlbnRSZXNwb25zZSA9IGF3YWl0IHRoaXMuaGFuZGxlUGF5bWVudCgpXG4gICAgY29uc3QgY2xpZW50U2VjcmV0ID0gcGF5bWVudEludGVudFJlc3BvbnNlLmNsaWVudFNlY3JldFxuXG4gICAgY29uc3QgY29uZmlybVBheW1lbnRSZXNwb25zZSA9IGF3YWl0IHN0cmlwZU9yZGVyQ29udHJvbGxlci5zdHJpcGUuY29uZmlybUNhcmRQYXltZW50KGNsaWVudFNlY3JldCwge1xuICAgICAgcGF5bWVudF9tZXRob2Q6IHtcbiAgICAgICAgY2FyZDogc3RyaXBlT3JkZXJDb250cm9sbGVyLmNhcmRcbiAgICAgIH1cbiAgICB9KTtcblxuICAgIGlmIChjb25maXJtUGF5bWVudFJlc3BvbnNlLmVycm9yKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IoY29uZmlybVBheW1lbnRSZXNwb25zZS5lcnJvci5tZXNzYWdlKVxuICAgIH0gZWxzZSBpZiAoY29uZmlybVBheW1lbnRSZXNwb25zZS5wYXltZW50SW50ZW50ICYmIGNvbmZpcm1QYXltZW50UmVzcG9uc2UucGF5bWVudEludGVudC5zdGF0dXMgPT09ICdzdWNjZWVkZWQnKSB7XG4gICAgICBjb25zb2xlLmxvZygnUGF5bWVudCBzdWNjZWVkZWQnLCBjb25maXJtUGF5bWVudFJlc3BvbnNlLnBheW1lbnRJbnRlbnQpXG4gICAgICAvLyBUT0RPOiBTdWJtaXQgZmluYWwgb3JkZXIgdG8gYmFja2VuZFxuICAgIH0gZWxzZSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LlBBWU1FTlRfTk9UX0NPTVBMRVRFRCB8fCAnUGF5bWVudCBub3QgY29tcGxldGVkJylcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogRmV0Y2ggcGF5bWVudCBpbnRlbnQgY3JlYXRpb24gVVJMIGFuZCByZXR1cm4gcmVzcG9uc2VcbiAgICogQHJldHVybnMge1Byb21pc2U8T2JqZWN0Pn0gUGF5bWVudCBpbnRlbnQgcmVzcG9uc2Ugd2l0aCBjbGllbnRTZWNyZXQsIGFtb3VudCwgY3VycmVuY3lcbiAgICogQHRocm93cyB7RXJyb3J9IElmIGZldGNoIGZhaWxzIG9yIHJlc3BvbnNlIGlzIG5vdCBva1xuICAgKi9cbiAgYXN5bmMgaGFuZGxlUGF5bWVudCgpIHtcbiAgICBpZiAoIXRoaXMuaGFzVXJsVmFsdWUpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uVVJMX05PVF9DT05GSUdVUkVEIHx8ICdQYXltZW50IFVSTCBpcyBub3QgY29uZmlndXJlZCcpXG4gICAgfVxuXG4gICAgY29uc29sZS5sb2coJ0NyZWF0aW5nIHBheW1lbnQgaW50ZW50IHZpYSBVUkw6JywgdGhpcy51cmxWYWx1ZSlcblxuICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2godGhpcy51cmxWYWx1ZSwge1xuICAgICAgbWV0aG9kOiAnUE9TVCcsXG4gICAgICBoZWFkZXJzOiB7XG4gICAgICAgICdDb250ZW50LVR5cGUnOiAnYXBwbGljYXRpb24vanNvbidcbiAgICAgIH0sXG4gICAgICBjcmVkZW50aWFsczogJ3NhbWUtb3JpZ2luJ1xuICAgIH0pXG5cbiAgICBpZiAoIXJlc3BvbnNlLm9rKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IoYEhUVFAgZXJyb3IhIHN0YXR1czogJHtyZXNwb25zZS5zdGF0dXN9YClcbiAgICB9XG5cbiAgICBjb25zdCByZXNwb25zZURhdGEgPSBhd2FpdCByZXNwb25zZS5qc29uKClcblxuICAgIGlmIChyZXNwb25zZURhdGEuZXJyb3IpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcihyZXNwb25zZURhdGEuZXJyb3IpXG4gICAgfVxuXG4gICAgaWYgKCFyZXNwb25zZURhdGEuc3VjY2VzcyB8fCAhcmVzcG9uc2VEYXRhLmNsaWVudFNlY3JldCkge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5JTlRFTlRfSU5WQUxJRCB8fCAnSW52YWxpZCBwYXltZW50IGludGVudCByZXNwb25zZScpXG4gICAgfVxuXG4gICAgcmV0dXJuIHJlc3BvbnNlRGF0YVxuICB9XG5cbiAgLyoqXG4gICAqIFNob3cgbG9hZGluZyBzdGF0ZSBvbiBidXR0b25cbiAgICovXG4gIHNob3dMb2FkaW5nKCkge1xuICAgIHRoaXMuZWxlbWVudC5kaXNhYmxlZCA9IHRydWVcbiAgICB0aGlzLm9yaWdpbmFsVGV4dCA9IHRoaXMuZWxlbWVudC50ZXh0Q29udGVudFxuICAgIHRoaXMuZWxlbWVudC50ZXh0Q29udGVudCA9IHdpbmRvdy5vU3RyaXBlPy5pMThuPy5QUk9DRVNTSU5HIHx8ICdQcm9jZXNzaW5nLi4uJ1xuICB9XG5cbiAgLyoqXG4gICAqIEhpZGUgbG9hZGluZyBzdGF0ZSBvbiBidXR0b25cbiAgICovXG4gIGhpZGVMb2FkaW5nKCkge1xuICAgIHRoaXMuZWxlbWVudC5kaXNhYmxlZCA9IGZhbHNlXG4gICAgaWYgKHRoaXMub3JpZ2luYWxUZXh0KSB7XG4gICAgICB0aGlzLmVsZW1lbnQudGV4dENvbnRlbnQgPSB0aGlzLm9yaWdpbmFsVGV4dFxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBTZXQgc3RhdHVzIG1lc3NhZ2VcbiAgICogQHBhcmFtIHtzdHJpbmd9IG1lc3NhZ2UgLSBTdGF0dXMgbWVzc2FnZSB0byBkaXNwbGF5XG4gICAqL1xuICBzZXRTdGF0dXMobWVzc2FnZSkge1xuICAgIGlmICh0aGlzLmhhc1N0YXR1c1RhcmdldCkge1xuICAgICAgdGhpcy5zdGF0dXNUYXJnZXQudGV4dENvbnRlbnQgPSBtZXNzYWdlXG4gICAgICB0aGlzLnN0YXR1c1RhcmdldC5jbGFzc05hbWUgPSAnbXQtMiB0ZXh0LWNlbnRlciB0ZXh0LW11dGVkJ1xuICAgIH1cbiAgICBjb25zb2xlLmxvZygnU3RhdHVzOicsIG1lc3NhZ2UpXG4gIH1cblxuICAvKipcbiAgICogU2hvdyBlcnJvciBtZXNzYWdlXG4gICAqIEBwYXJhbSB7c3RyaW5nfSBtZXNzYWdlIC0gRXJyb3IgbWVzc2FnZSB0byBkaXNwbGF5XG4gICAqL1xuICBzaG93RXJyb3IobWVzc2FnZSkge1xuICAgIGlmICh0aGlzLmhhc1N0YXR1c1RhcmdldCkge1xuICAgICAgdGhpcy5zdGF0dXNUYXJnZXQudGV4dENvbnRlbnQgPSBtZXNzYWdlXG4gICAgICB0aGlzLnN0YXR1c1RhcmdldC5jbGFzc05hbWUgPSAnbXQtMiB0ZXh0LWNlbnRlciB0ZXh0LWRhbmdlcidcbiAgICB9IGVsc2Uge1xuICAgICAgYWxlcnQoJ0Vycm9yOiAnICsgbWVzc2FnZSlcbiAgICB9XG4gIH1cbn1cbiIsICJpbXBvcnQgeyBDb250cm9sbGVyIH0gZnJvbSAnQGhvdHdpcmVkL3N0aW11bHVzJ1xuXG4vKipcbiAqIFN0aW11bHVzIGNvbnRyb2xsZXIgZm9yIEFHQiAoVGVybXMgYW5kIENvbmRpdGlvbnMpIGNoZWNrYm94IHZhbGlkYXRpb25cbiAqXG4gKiBUaGlzIGNvbnRyb2xsZXIgaGFuZGxlcyB0aGUgdmFsaWRhdGlvbiBvZiB0aGUgQUdCIGNoZWNrYm94IG9uIHRoZSBvcmRlciBwYWdlLlxuICogV2hlbiBibENvbmZpcm1BR0IgaXMgZW5hYmxlZCwgaXQgcHJldmVudHMgb3JkZXIgc3VibWlzc2lvbiB1bnRpbCB0aGUgY2hlY2tib3ggaXMgY2hlY2tlZC5cbiAqXG4gKiBVc2FnZSBpbiB0ZW1wbGF0ZTpcbiAqIDxkaXYgZGF0YS1jb250cm9sbGVyPVwiYWdiLXZhbGlkYXRpb25cIiBkYXRhLWFnYi12YWxpZGF0aW9uLWVuYWJsZWQtdmFsdWU9XCJ0cnVlXCI+XG4gKiAgIDxpbnB1dCB0eXBlPVwiY2hlY2tib3hcIiBkYXRhLWFnYi12YWxpZGF0aW9uLXRhcmdldD1cImNoZWNrYm94XCIgLz5cbiAqICAgPGJ1dHRvbiBkYXRhLWFnYi12YWxpZGF0aW9uLXRhcmdldD1cInN1Ym1pdEJ1dHRvblwiPk9yZGVyPC9idXR0b24+XG4gKiA8L2Rpdj5cbiAqL1xuZXhwb3J0IGRlZmF1bHQgY2xhc3MgZXh0ZW5kcyBDb250cm9sbGVyIHtcbiAgc3RhdGljIHRhcmdldHMgPSBbJ2NoZWNrYm94JywgJ3N1Ym1pdEJ1dHRvbiddXG4gIHN0YXRpYyB2YWx1ZXMgPSB7XG4gICAgZW5hYmxlZDogQm9vbGVhblxuICB9XG5cbiAgLyoqXG4gICAqIEluaXRpYWxpemUgdGhlIGNvbnRyb2xsZXIgd2hlbiBjb25uZWN0ZWQgdG8gdGhlIERPTVxuICAgKi9cbiAgY29ubmVjdCgpIHtcbiAgICBjb25zb2xlLmxvZygnQUdCIFZhbGlkYXRpb24gY29udHJvbGxlciBjb25uZWN0ZWQnLCB7XG4gICAgICBlbmFibGVkOiB0aGlzLmVuYWJsZWRWYWx1ZSxcbiAgICAgIGhhc0NoZWNrYm94OiB0aGlzLmhhc0NoZWNrYm94VGFyZ2V0LFxuICAgICAgaGFzU3VibWl0QnV0dG9uczogdGhpcy5oYXNTdWJtaXRCdXR0b25UYXJnZXRcbiAgICB9KVxuXG4gICAgLy8gT25seSBhcHBseSB2YWxpZGF0aW9uIGlmIGJsQ29uZmlybUFHQiBpcyBlbmFibGVkXG4gICAgaWYgKHRoaXMuZW5hYmxlZFZhbHVlKSB7XG4gICAgICB0aGlzLnVwZGF0ZUJ1dHRvblN0YXRlcygpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBjaGVja2JveCBzdGF0ZSBjaGFuZ2VzXG4gICAqL1xuICBjaGVja2JveENoYW5nZWQoKSB7XG4gICAgaWYgKHRoaXMuZW5hYmxlZFZhbHVlKSB7XG4gICAgICB0aGlzLnVwZGF0ZUJ1dHRvblN0YXRlcygpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFVwZGF0ZSB0aGUgZGlzYWJsZWQgc3RhdGUgb2YgYWxsIHN1Ym1pdCBidXR0b25zIGJhc2VkIG9uIGNoZWNrYm94IHN0YXRlXG4gICAqL1xuICB1cGRhdGVCdXR0b25TdGF0ZXMoKSB7XG4gICAgaWYgKCF0aGlzLmhhc0NoZWNrYm94VGFyZ2V0IHx8ICF0aGlzLmhhc1N1Ym1pdEJ1dHRvblRhcmdldCkge1xuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgY29uc3QgaXNDaGVja2VkID0gdGhpcy5jaGVja2JveFRhcmdldC5jaGVja2VkXG5cbiAgICAvLyBVcGRhdGUgYWxsIHN1Ym1pdCBidXR0b25zXG4gICAgdGhpcy5zdWJtaXRCdXR0b25UYXJnZXRzLmZvckVhY2goYnV0dG9uID0+IHtcbiAgICAgIGJ1dHRvbi5kaXNhYmxlZCA9ICFpc0NoZWNrZWRcblxuICAgICAgLy8gQWRkIHZpc3VhbCBmZWVkYmFja1xuICAgICAgaWYgKGlzQ2hlY2tlZCkge1xuICAgICAgICBidXR0b24uY2xhc3NMaXN0LnJlbW92ZSgnZGlzYWJsZWQnKVxuICAgICAgICBidXR0b24ucmVtb3ZlQXR0cmlidXRlKCd0aXRsZScpXG4gICAgICB9IGVsc2Uge1xuICAgICAgICBidXR0b24uY2xhc3NMaXN0LmFkZCgnZGlzYWJsZWQnKVxuICAgICAgICBidXR0b24uc2V0QXR0cmlidXRlKCd0aXRsZScsIHdpbmRvdy5vU3RyaXBlPy5pMThuPy5BR0JfUkVRVUlSRUQgfHwgJ1BsZWFzZSBhY2NlcHQgdGhlIHRlcm1zIGFuZCBjb25kaXRpb25zJylcbiAgICAgIH1cbiAgICB9KVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBmb3JtIHN1Ym1pc3Npb24gYXR0ZW1wdHNcbiAgICogQHBhcmFtIHtFdmVudH0gZXZlbnQgLSBUaGUgc3VibWl0IGV2ZW50XG4gICAqL1xuICBoYW5kbGVTdWJtaXQoZXZlbnQpIHtcbiAgICBpZiAoIXRoaXMuZW5hYmxlZFZhbHVlKSB7XG4gICAgICByZXR1cm4gdHJ1ZVxuICAgIH1cblxuICAgIGlmICghdGhpcy5oYXNDaGVja2JveFRhcmdldCB8fCAhdGhpcy5jaGVja2JveFRhcmdldC5jaGVja2VkKSB7XG4gICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpXG4gICAgICBldmVudC5zdG9wUHJvcGFnYXRpb24oKVxuXG4gICAgICAvLyBTaG93IHZpc3VhbCBmZWVkYmFja1xuICAgICAgaWYgKHRoaXMuaGFzQ2hlY2tib3hUYXJnZXQpIHtcbiAgICAgICAgY29uc3QgY2hlY2tib3hXcmFwcGVyID0gdGhpcy5jaGVja2JveFRhcmdldC5jbG9zZXN0KCcuZm9ybS1jaGVjaycpXG4gICAgICAgIGlmIChjaGVja2JveFdyYXBwZXIpIHtcbiAgICAgICAgICBjaGVja2JveFdyYXBwZXIuY2xhc3NMaXN0LmFkZCgnYm9yZGVyJywgJ2JvcmRlci1kYW5nZXInLCAncC0yJywgJ3JvdW5kZWQnKVxuXG4gICAgICAgICAgLy8gUmVtb3ZlIHRoZSBoaWdobGlnaHQgYWZ0ZXIgMyBzZWNvbmRzXG4gICAgICAgICAgc2V0VGltZW91dCgoKSA9PiB7XG4gICAgICAgICAgICBjaGVja2JveFdyYXBwZXIuY2xhc3NMaXN0LnJlbW92ZSgnYm9yZGVyJywgJ2JvcmRlci1kYW5nZXInLCAncC0yJywgJ3JvdW5kZWQnKVxuICAgICAgICAgIH0sIDMwMDApXG4gICAgICAgIH1cbiAgICAgIH1cblxuICAgICAgcmV0dXJuIGZhbHNlXG4gICAgfVxuXG4gICAgcmV0dXJuIHRydWVcbiAgfVxufVxuIiwgIi8qKlxuICogRXZlbnRCdXMgLSBDZW50cmFsbmEgc3p5bmEgZXZlbnRvd2EgZGxhIGFwbGlrYWNqaSBPbmUtUGFnZSBDaGVja291dFxuICpcbiAqIFByb2JsZW06XG4gKiBLb250cm9sZXJ5IGRpc3BhdGNodWpcdTAxMDUgZXZlbnR5IG5hIHJcdTAwRjNcdTAxN0NueWNoIHRhcmdldGFjaCAoZG9jdW1lbnQsIHRoaXMuZWxlbWVudCwgd2luZG93KSxcbiAqIGNvIHBvd29kdWplIHByb2JsZW15IHogdGltaW5nJ2llbSBpIHRydWRub1x1MDE1QmNpIHcgdGVzdG93YW5pdS5cbiAqXG4gKiBSb3p3aVx1MDEwNXphbmllOlxuICogU2luZ2xldG9uIEV2ZW50QnVzIHphcGV3bmlhIGplZGVuIGNlbnRyYWxueSBwdW5rdCBrb211bmlrYWNqaS5cbiAqIFdzenlzdGtpZSBldmVudHkgcHJ6ZWNob2R6XHUwMTA1IHByemV6IHRcdTAxMTkgc3p5blx1MDExOSwgY28gdVx1MDE0MmF0d2lhOlxuICogLSBEZWJ1Z293YW5pZSAod3N6eXN0a2llIGV2ZW50eSB3IGplZG55bSBtaWVqc2N1KVxuICogLSBUZXN0b3dhbmllIChtb1x1MDE3Q25hIG1vY2tvd2FcdTAxMDcgRXZlbnRCdXMpXG4gKiAtIEtvbnRyb2xcdTAxMTkgKG1vXHUwMTdDbmEgbG9nb3dhXHUwMTA3LCBmaWx0cm93YVx1MDEwNywgdHJhbnNmb3Jtb3dhXHUwMTA3IGV2ZW50eSlcbiAqXG4gKiBVXHUwMTdDeWNpZSB3IGtvbnRyb2xlcmFjaDpcbiAqXG4gKiBpbXBvcnQgeyBldmVudEJ1cyB9IGZyb20gJy4uL3V0aWxzL2V2ZW50X2J1cy5qcydcbiAqXG4gKiAvLyBOYXNcdTAxNDJ1Y2hpd2FuaWVcbiAqIGV2ZW50QnVzLm9uKCdvZTpiYXNrZXQ6dXBkYXRlZCcsIChkYXRhKSA9PiB7XG4gKiAgIGNvbnNvbGUubG9nKCdCYXNrZXQgdXBkYXRlZDonLCBkYXRhKVxuICogfSlcbiAqXG4gKiAvLyBFbWlzamFcbiAqIGV2ZW50QnVzLmVtaXQoJ29lOmJhc2tldDp1cGRhdGVkJywgeyBpdGVtczogWy4uLl0sIHRvdGFsOiAxMDAgfSlcbiAqXG4gKiAvLyBKZWRub3Jhem93ZSBuYXNcdTAxNDJ1Y2hpd2FuaWVcbiAqIGV2ZW50QnVzLm9uY2UoJ29lOmNoZWNrb3V0OmNvbXBsZXRlJywgKGRhdGEpID0+IHtcbiAqICAgY29uc29sZS5sb2coJ0NoZWNrb3V0IGNvbXBsZXRlOicsIGRhdGEpXG4gKiB9KVxuICpcbiAqIC8vIFVzdW5pXHUwMTE5Y2llIGxpc3RlbmVyYSAod2FcdTAxN0NuZSBkbGEgY2xlYW51cCEpXG4gKiBjb25zdCBoYW5kbGVyID0gKGRhdGEpID0+IGNvbnNvbGUubG9nKGRhdGEpXG4gKiBldmVudEJ1cy5vbignZXZlbnQnLCBoYW5kbGVyKVxuICogZXZlbnRCdXMub2ZmKCdldmVudCcsIGhhbmRsZXIpXG4gKi9cblxuY2xhc3MgRXZlbnRCdXMge1xuICBjb25zdHJ1Y3RvcigpIHtcbiAgICAvLyBTaW5nbGV0b24gcGF0dGVyblxuICAgIGlmIChFdmVudEJ1cy5pbnN0YW5jZSkge1xuICAgICAgcmV0dXJuIEV2ZW50QnVzLmluc3RhbmNlXG4gICAgfVxuXG4gICAgdGhpcy5saXN0ZW5lcnMgPSBuZXcgTWFwKCkgLy8gZXZlbnROYW1lIC0+IFNldCBvZiBoYW5kbGVyc1xuICAgIHRoaXMuZGVidWcgPSBmYWxzZVxuICAgIHRoaXMuZXZlbnRIaXN0b3J5ID0gW10gLy8gRm9yIGRlYnVnZ2luZ1xuICAgIHRoaXMubWF4SGlzdG9yeVNpemUgPSAxMDBcblxuICAgIEV2ZW50QnVzLmluc3RhbmNlID0gdGhpc1xuICB9XG5cbiAgLyoqXG4gICAqIFdcdTAxNDJcdTAxMDVjei93eVx1MDE0Mlx1MDEwNWN6IHRyeWIgZGVidWdcbiAgICovXG4gIHNldERlYnVnKGVuYWJsZWQpIHtcbiAgICB0aGlzLmRlYnVnID0gZW5hYmxlZFxuICB9XG5cbiAgLyoqXG4gICAqIFphcmVqZXN0cnVqIGxpc3RlbmVyIGRsYSBldmVudHVcbiAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dSAobnAuICdvZTpiYXNrZXQ6dXBkYXRlZCcpXG4gICAqIEBwYXJhbSB7ZnVuY3Rpb259IGhhbmRsZXIgLSBGdW5rY2phIGhhbmRsZXJhIChkYXRhKSA9PiB2b2lkXG4gICAqIEByZXR1cm5zIHtmdW5jdGlvbn0gRnVua2NqYSBkbyB1c3VuaVx1MDExOWNpYSBsaXN0ZW5lcmFcbiAgICovXG4gIG9uKGV2ZW50TmFtZSwgaGFuZGxlcikge1xuICAgIGlmICghdGhpcy5saXN0ZW5lcnMuaGFzKGV2ZW50TmFtZSkpIHtcbiAgICAgIHRoaXMubGlzdGVuZXJzLnNldChldmVudE5hbWUsIG5ldyBTZXQoKSlcbiAgICB9XG5cbiAgICB0aGlzLmxpc3RlbmVycy5nZXQoZXZlbnROYW1lKS5hZGQoaGFuZGxlcilcblxuICAgIGlmICh0aGlzLmRlYnVnKSB7XG4gICAgICBjb25zb2xlLmxvZyhgW0V2ZW50QnVzXSBSZWdpc3RlcmVkIGxpc3RlbmVyIGZvciBcIiR7ZXZlbnROYW1lfVwiYCwge1xuICAgICAgICBsaXN0ZW5lcnNDb3VudDogdGhpcy5saXN0ZW5lcnMuZ2V0KGV2ZW50TmFtZSkuc2l6ZVxuICAgICAgfSlcbiAgICB9XG5cbiAgICAvLyBad3JcdTAwRjNcdTAxMDcgZnVua2NqXHUwMTE5IGRvIHVzdW5pXHUwMTE5Y2lhIGxpc3RlbmVyYVxuICAgIHJldHVybiAoKSA9PiB0aGlzLm9mZihldmVudE5hbWUsIGhhbmRsZXIpXG4gIH1cblxuICAvKipcbiAgICogWmFyZWplc3RydWogbGlzdGVuZXIsIGt0XHUwMEYzcnkgd3lrb25hIHNpXHUwMTE5IHR5bGtvIHJhelxuICAgKiBAcGFyYW0ge3N0cmluZ30gZXZlbnROYW1lIC0gTmF6d2EgZXZlbnR1XG4gICAqIEBwYXJhbSB7ZnVuY3Rpb259IGhhbmRsZXIgLSBGdW5rY2phIGhhbmRsZXJhXG4gICAqIEByZXR1cm5zIHtmdW5jdGlvbn0gRnVua2NqYSBkbyB1c3VuaVx1MDExOWNpYSBsaXN0ZW5lcmFcbiAgICovXG4gIG9uY2UoZXZlbnROYW1lLCBoYW5kbGVyKSB7XG4gICAgY29uc3Qgb25jZUhhbmRsZXIgPSAoZGF0YSkgPT4ge1xuICAgICAgaGFuZGxlcihkYXRhKVxuICAgICAgdGhpcy5vZmYoZXZlbnROYW1lLCBvbmNlSGFuZGxlcilcbiAgICB9XG5cbiAgICByZXR1cm4gdGhpcy5vbihldmVudE5hbWUsIG9uY2VIYW5kbGVyKVxuICB9XG5cbiAgLyoqXG4gICAqIFVzdVx1MDE0NCBsaXN0ZW5lclxuICAgKiBAcGFyYW0ge3N0cmluZ30gZXZlbnROYW1lIC0gTmF6d2EgZXZlbnR1XG4gICAqIEBwYXJhbSB7ZnVuY3Rpb259IGhhbmRsZXIgLSBGdW5rY2phIGhhbmRsZXJhIGRvIHVzdW5pXHUwMTE5Y2lhXG4gICAqL1xuICBvZmYoZXZlbnROYW1lLCBoYW5kbGVyKSB7XG4gICAgaWYgKCF0aGlzLmxpc3RlbmVycy5oYXMoZXZlbnROYW1lKSkge1xuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgY29uc3QgaGFuZGxlcnMgPSB0aGlzLmxpc3RlbmVycy5nZXQoZXZlbnROYW1lKVxuICAgIGhhbmRsZXJzLmRlbGV0ZShoYW5kbGVyKVxuXG4gICAgLy8gVXN1XHUwMTQ0IGV2ZW50IHogbWFweSBqZVx1MDE1QmxpIG5pZSBtYSBqdVx1MDE3QyBsaXN0ZW5lclx1MDBGM3dcbiAgICBpZiAoaGFuZGxlcnMuc2l6ZSA9PT0gMCkge1xuICAgICAgdGhpcy5saXN0ZW5lcnMuZGVsZXRlKGV2ZW50TmFtZSlcbiAgICB9XG5cbiAgICBpZiAodGhpcy5kZWJ1Zykge1xuICAgICAgY29uc29sZS5sb2coYFtFdmVudEJ1c10gUmVtb3ZlZCBsaXN0ZW5lciBmb3IgXCIke2V2ZW50TmFtZX1cImAsIHtcbiAgICAgICAgbGlzdGVuZXJzQ291bnQ6IGhhbmRsZXJzLnNpemVcbiAgICAgIH0pXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFVzdVx1MDE0NCB3c3p5c3RraWUgbGlzdGVuZXJ5IGRsYSBkYW5lZ28gZXZlbnR1XG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICovXG4gIG9mZkFsbChldmVudE5hbWUpIHtcbiAgICBpZiAodGhpcy5saXN0ZW5lcnMuaGFzKGV2ZW50TmFtZSkpIHtcbiAgICAgIHRoaXMubGlzdGVuZXJzLmRlbGV0ZShldmVudE5hbWUpXG5cbiAgICAgIGlmICh0aGlzLmRlYnVnKSB7XG4gICAgICAgIGNvbnNvbGUubG9nKGBbRXZlbnRCdXNdIFJlbW92ZWQgYWxsIGxpc3RlbmVycyBmb3IgXCIke2V2ZW50TmFtZX1cImApXG4gICAgICB9XG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFd5ZW1pdHVqIGV2ZW50XG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICogQHBhcmFtIHsqfSBkYXRhIC0gRGFuZSBkbyBwcnpla2F6YW5pYVxuICAgKi9cbiAgZW1pdChldmVudE5hbWUsIGRhdGEgPSB7fSkge1xuICAgIGNvbnN0IHRpbWVzdGFtcCA9IERhdGUubm93KClcblxuICAgIC8vIFphcGlzeiBkbyBoaXN0b3JpaVxuICAgIHRoaXMuZXZlbnRIaXN0b3J5LnB1c2goe1xuICAgICAgZXZlbnROYW1lLFxuICAgICAgZGF0YSxcbiAgICAgIHRpbWVzdGFtcCxcbiAgICAgIGxpc3RlbmVyc0NvdW50OiB0aGlzLmxpc3RlbmVycy5oYXMoZXZlbnROYW1lKSA/IHRoaXMubGlzdGVuZXJzLmdldChldmVudE5hbWUpLnNpemUgOiAwXG4gICAgfSlcblxuICAgIC8vIE9ncmFuaWN6IHJvem1pYXIgaGlzdG9yaWlcbiAgICBpZiAodGhpcy5ldmVudEhpc3RvcnkubGVuZ3RoID4gdGhpcy5tYXhIaXN0b3J5U2l6ZSkge1xuICAgICAgdGhpcy5ldmVudEhpc3Rvcnkuc2hpZnQoKVxuICAgIH1cblxuICAgIGlmICh0aGlzLmRlYnVnKSB7XG4gICAgICBjb25zb2xlLmxvZyhgW0V2ZW50QnVzXSBFdmVudCBlbWl0dGVkOiBcIiR7ZXZlbnROYW1lfVwiYCwge1xuICAgICAgICBkYXRhLFxuICAgICAgICBsaXN0ZW5lcnNDb3VudDogdGhpcy5saXN0ZW5lcnMuaGFzKGV2ZW50TmFtZSkgPyB0aGlzLmxpc3RlbmVycy5nZXQoZXZlbnROYW1lKS5zaXplIDogMCxcbiAgICAgICAgdGltZXN0YW1wOiBuZXcgRGF0ZSh0aW1lc3RhbXApLnRvSVNPU3RyaW5nKClcbiAgICAgIH0pXG4gICAgfVxuXG4gICAgLy8gV3l3b1x1MDE0MmFqIHdzenlzdGtpZSBsaXN0ZW5lcnlcbiAgICBpZiAodGhpcy5saXN0ZW5lcnMuaGFzKGV2ZW50TmFtZSkpIHtcbiAgICAgIGNvbnN0IGhhbmRsZXJzID0gQXJyYXkuZnJvbSh0aGlzLmxpc3RlbmVycy5nZXQoZXZlbnROYW1lKSlcblxuICAgICAgaGFuZGxlcnMuZm9yRWFjaChoYW5kbGVyID0+IHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICBoYW5kbGVyKGRhdGEpXG4gICAgICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgY29uc29sZS5lcnJvcihgW0V2ZW50QnVzXSBFcnJvciBpbiBoYW5kbGVyIGZvciBcIiR7ZXZlbnROYW1lfVwiOmAsIGVycm9yKVxuICAgICAgICAgIC8vIE5pZSBwcnplcnl3YWogd3lrb255d2FuaWEgaW5ueWNoIGhhbmRsZXJcdTAwRjN3XG4gICAgICAgIH1cbiAgICAgIH0pXG4gICAgfSBlbHNlIGlmICh0aGlzLmRlYnVnKSB7XG4gICAgICBjb25zb2xlLndhcm4oYFtFdmVudEJ1c10gTm8gbGlzdGVuZXJzIGZvciBcIiR7ZXZlbnROYW1lfVwiYClcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogV3llbWl0dWogZXZlbnQgYXN5bmNocm9uaWN6bmllIChuYXN0XHUwMTE5cG55IHRpY2spXG4gICAqIFByenlkYXRuZSBnZHkgY2hjZW15IHBvendvbGlcdTAxMDcgVUkgc2lcdTAxMTkgd3lyZW5kZXJvd2FcdTAxMDcgcHJ6ZWQgaGFuZGxlcmVtXG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICogQHBhcmFtIHsqfSBkYXRhIC0gRGFuZSBkbyBwcnpla2F6YW5pYVxuICAgKiBAcmV0dXJucyB7UHJvbWlzZX0gUHJvbWlzZSBrdFx1MDBGM3J5IHJlc29sdmUndWplIHNpXHUwMTE5IHBvIGVtaXNqaVxuICAgKi9cbiAgYXN5bmMgZW1pdEFzeW5jKGV2ZW50TmFtZSwgZGF0YSA9IHt9KSB7XG4gICAgcmV0dXJuIG5ldyBQcm9taXNlKChyZXNvbHZlKSA9PiB7XG4gICAgICBzZXRUaW1lb3V0KCgpID0+IHtcbiAgICAgICAgdGhpcy5lbWl0KGV2ZW50TmFtZSwgZGF0YSlcbiAgICAgICAgcmVzb2x2ZSgpXG4gICAgICB9LCAwKVxuICAgIH0pXG4gIH1cblxuICAvKipcbiAgICogV3llbWl0dWogZXZlbnQgeiBvcFx1MDBGM1x1MDE3QW5pZW5pZW1cbiAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgKiBAcGFyYW0geyp9IGRhdGEgLSBEYW5lIGRvIHByemVrYXphbmlhXG4gICAqIEBwYXJhbSB7bnVtYmVyfSBkZWxheSAtIE9wXHUwMEYzXHUwMTdBbmllbmllIHcgbXNcbiAgICogQHJldHVybnMge251bWJlcn0gVGltZXIgSUQgKGRvIGNsZWFyVGltZW91dClcbiAgICovXG4gIGVtaXREZWxheWVkKGV2ZW50TmFtZSwgZGF0YSA9IHt9LCBkZWxheSA9IDApIHtcbiAgICByZXR1cm4gc2V0VGltZW91dCgoKSA9PiB7XG4gICAgICB0aGlzLmVtaXQoZXZlbnROYW1lLCBkYXRhKVxuICAgIH0sIGRlbGF5KVxuICB9XG5cbiAgLyoqXG4gICAqIFBvY3pla2FqIG5hIGV2ZW50ICh6d3JhY2EgUHJvbWlzZSlcbiAgICogUHJ6eWRhdG5lIHcgdGVzdGFjaCBpIGFzeW5jIGZsb3dzXG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICogQHBhcmFtIHtudW1iZXJ9IHRpbWVvdXQgLSBUaW1lb3V0IHcgbXMgKG9wY2pvbmFsbnkpXG4gICAqIEByZXR1cm5zIHtQcm9taXNlfSBQcm9taXNlIGt0XHUwMEYzcnkgcmVzb2x2ZSd1amUgc2lcdTAxMTkgeiBkYW55bWkgZXZlbnR1XG4gICAqL1xuICB3YWl0Rm9yKGV2ZW50TmFtZSwgdGltZW91dCA9IDUwMDApIHtcbiAgICByZXR1cm4gbmV3IFByb21pc2UoKHJlc29sdmUsIHJlamVjdCkgPT4ge1xuICAgICAgY29uc3QgdGltZXIgPSB0aW1lb3V0ID4gMCA/IHNldFRpbWVvdXQoKCkgPT4ge1xuICAgICAgICB0aGlzLm9mZihldmVudE5hbWUsIGhhbmRsZXIpXG4gICAgICAgIHJlamVjdChuZXcgRXJyb3IoYFtFdmVudEJ1c10gVGltZW91dCB3YWl0aW5nIGZvciBldmVudCBcIiR7ZXZlbnROYW1lfVwiYCkpXG4gICAgICB9LCB0aW1lb3V0KSA6IG51bGxcblxuICAgICAgY29uc3QgaGFuZGxlciA9IChkYXRhKSA9PiB7XG4gICAgICAgIGlmICh0aW1lcikgY2xlYXJUaW1lb3V0KHRpbWVyKVxuICAgICAgICByZXNvbHZlKGRhdGEpXG4gICAgICB9XG5cbiAgICAgIHRoaXMub25jZShldmVudE5hbWUsIGhhbmRsZXIpXG4gICAgfSlcbiAgfVxuXG4gIC8qKlxuICAgKiBTcHJhd2RcdTAxN0EgY3p5IHNcdTAxMDUgbGlzdGVuZXJ5IGRsYSBkYW5lZ28gZXZlbnR1XG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICogQHJldHVybnMge2Jvb2xlYW59XG4gICAqL1xuICBoYXNMaXN0ZW5lcnMoZXZlbnROYW1lKSB7XG4gICAgcmV0dXJuIHRoaXMubGlzdGVuZXJzLmhhcyhldmVudE5hbWUpICYmIHRoaXMubGlzdGVuZXJzLmdldChldmVudE5hbWUpLnNpemUgPiAwXG4gIH1cblxuICAvKipcbiAgICogUG9iaWVyeiBsaWN6Ylx1MDExOSBsaXN0ZW5lclx1MDBGM3cgZGxhIGV2ZW50dVxuICAgKiBAcGFyYW0ge3N0cmluZ30gZXZlbnROYW1lIC0gTmF6d2EgZXZlbnR1XG4gICAqIEByZXR1cm5zIHtudW1iZXJ9XG4gICAqL1xuICBnZXRMaXN0ZW5lcnNDb3VudChldmVudE5hbWUpIHtcbiAgICByZXR1cm4gdGhpcy5saXN0ZW5lcnMuaGFzKGV2ZW50TmFtZSkgPyB0aGlzLmxpc3RlbmVycy5nZXQoZXZlbnROYW1lKS5zaXplIDogMFxuICB9XG5cbiAgLyoqXG4gICAqIFBvYmllcnogd3N6eXN0a2llIHphcmVqZXN0cm93YW5lIGV2ZW50eVxuICAgKiBAcmV0dXJucyB7c3RyaW5nW119XG4gICAqL1xuICBnZXRSZWdpc3RlcmVkRXZlbnRzKCkge1xuICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMubGlzdGVuZXJzLmtleXMoKSlcbiAgfVxuXG4gIC8qKlxuICAgKiBQb2JpZXJ6IGhpc3RvcmlcdTAxMTkgZXZlbnRcdTAwRjN3XG4gICAqIEBwYXJhbSB7bnVtYmVyfSBsaW1pdCAtIExpbWl0IGV2ZW50XHUwMEYzdyBkbyB6d3JcdTAwRjNjZW5pYSAob3Bjam9uYWxueSlcbiAgICogQHJldHVybnMge0FycmF5fVxuICAgKi9cbiAgZ2V0RXZlbnRIaXN0b3J5KGxpbWl0ID0gNTApIHtcbiAgICByZXR1cm4gdGhpcy5ldmVudEhpc3Rvcnkuc2xpY2UoLWxpbWl0KVxuICB9XG5cbiAgLyoqXG4gICAqIFd5Y3p5XHUwMTVCXHUwMTA3IGhpc3RvcmlcdTAxMTkgZXZlbnRcdTAwRjN3XG4gICAqL1xuICBjbGVhckhpc3RvcnkoKSB7XG4gICAgdGhpcy5ldmVudEhpc3RvcnkgPSBbXVxuICB9XG5cbiAgLyoqXG4gICAqIFd5Y3p5XHUwMTVCXHUwMTA3IHdzenlzdGtpZSBsaXN0ZW5lcnkgKHVcdTAxN0N5aiBvc3Ryb1x1MDE3Q25pZSEpXG4gICAqL1xuICBjbGVhckFsbCgpIHtcbiAgICB0aGlzLmxpc3RlbmVycy5jbGVhcigpXG5cbiAgICBpZiAodGhpcy5kZWJ1Zykge1xuICAgICAgY29uc29sZS5sb2coJ1tFdmVudEJ1c10gQWxsIGxpc3RlbmVycyBjbGVhcmVkJylcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogV3lwaXN6IHN0YXR5c3R5a2kgRXZlbnRCdXNcbiAgICovXG4gIHByaW50U3RhdHMoKSB7XG4gICAgY29uc29sZS5ncm91cCgnW0V2ZW50QnVzXSBTdGF0aXN0aWNzJylcbiAgICBjb25zb2xlLmxvZygnUmVnaXN0ZXJlZCBldmVudHM6JywgdGhpcy5nZXRSZWdpc3RlcmVkRXZlbnRzKCkpXG4gICAgY29uc29sZS5sb2coJ1RvdGFsIGxpc3RlbmVyczonLCBBcnJheS5mcm9tKHRoaXMubGlzdGVuZXJzLnZhbHVlcygpKS5yZWR1Y2UoKHN1bSwgc2V0KSA9PiBzdW0gKyBzZXQuc2l6ZSwgMCkpXG4gICAgY29uc29sZS5sb2coJ0V2ZW50IGhpc3Rvcnkgc2l6ZTonLCB0aGlzLmV2ZW50SGlzdG9yeS5sZW5ndGgpXG5cbiAgICBjb25zb2xlLmdyb3VwKCdMaXN0ZW5lcnMgcGVyIGV2ZW50OicpXG4gICAgdGhpcy5saXN0ZW5lcnMuZm9yRWFjaCgoaGFuZGxlcnMsIGV2ZW50TmFtZSkgPT4ge1xuICAgICAgY29uc29sZS5sb2coYCAgJHtldmVudE5hbWV9OiAke2hhbmRsZXJzLnNpemV9YClcbiAgICB9KVxuICAgIGNvbnNvbGUuZ3JvdXBFbmQoKVxuXG4gICAgY29uc29sZS5ncm91cCgnUmVjZW50IGV2ZW50czonKVxuICAgIHRoaXMuZ2V0RXZlbnRIaXN0b3J5KDEwKS5mb3JFYWNoKGV2ZW50ID0+IHtcbiAgICAgIGNvbnNvbGUubG9nKGAgICR7ZXZlbnQuZXZlbnROYW1lfSAoJHtldmVudC5saXN0ZW5lcnNDb3VudH0gbGlzdGVuZXJzKSAtICR7bmV3IERhdGUoZXZlbnQudGltZXN0YW1wKS50b0xvY2FsZVRpbWVTdHJpbmcoKX1gKVxuICAgIH0pXG4gICAgY29uc29sZS5ncm91cEVuZCgpXG5cbiAgICBjb25zb2xlLmdyb3VwRW5kKClcbiAgfVxufVxuXG4vLyBFa3Nwb3J0dWogc2luZ2xldG9uIGluc3RhbmNlIC0gdVx1MDE3Q3l3YWogZ2xvYmFsbmVnbyBqZVx1MDE1QmxpIGlzdG5pZWplIVxuLy8gV0FcdTAxN0JORTogTW9kdVx1MDE0MiBTdHJpcGUgXHUwMTQyYWR1amUgc2lcdTAxMTkgcG8gb25lcGFnZS1jaGVja291dCwgd2lcdTAxMTljIG11c2lteSB1XHUwMTdDeVx1MDEwN1xuLy8gaXN0bmllalx1MDEwNWNlaiBpbnN0YW5jamkgRXZlbnRCdXMgeiB3aW5kb3cuZXZlbnRCdXMgemFtaWFzdCB0d29yenlcdTAxMDcgbm93XHUwMTA1LlxubGV0IGV2ZW50QnVzXG5cbmlmICh0eXBlb2Ygd2luZG93ICE9PSAndW5kZWZpbmVkJyAmJiB3aW5kb3cuZXZlbnRCdXMpIHtcbiAgLy8gVVx1MDE3Q3lqIGlzdG5pZWpcdTAxMDVjZWogZ2xvYmFsbmVqIGluc3RhbmNqaVxuICBjb25zb2xlLmxvZygnW1N0cmlwZSBFdmVudEJ1c10gVXNpbmcgZXhpc3RpbmcgZ2xvYmFsIEV2ZW50QnVzIGZyb20gd2luZG93LmV2ZW50QnVzJylcbiAgZXZlbnRCdXMgPSB3aW5kb3cuZXZlbnRCdXNcbn0gZWxzZSB7XG4gIC8vIFV0d1x1MDBGM3J6IG5vd1x1MDEwNSBpbnN0YW5jalx1MDExOSAoZmFsbGJhY2spXG4gIGNvbnNvbGUubG9nKCdbU3RyaXBlIEV2ZW50QnVzXSBDcmVhdGluZyBuZXcgRXZlbnRCdXMgaW5zdGFuY2UnKVxuICBldmVudEJ1cyA9IG5ldyBFdmVudEJ1cygpXG5cbiAgLy8gT3Bjam9uYWxuaWU6IHdcdTAxNDJcdTAxMDVjeiBkZWJ1ZyB3IGRldiBtb2RlXG4gIGlmICh0eXBlb2Ygd2luZG93ICE9PSAndW5kZWZpbmVkJyAmJiB3aW5kb3cubG9jYXRpb24/Lmhvc3RuYW1lID09PSAnbG9jYWxob3N0Jykge1xuICAgIGV2ZW50QnVzLnNldERlYnVnKHRydWUpXG4gIH1cblxuICAvLyBVZG9zdFx1MDExOXBuaWogZ2xvYmFsbmllIGRsYSBcdTAxNDJhdHdlZ28gZGVidWdvd2FuaWEgdyBrb25zb2xpXG4gIGlmICh0eXBlb2Ygd2luZG93ICE9PSAndW5kZWZpbmVkJykge1xuICAgIHdpbmRvdy5ldmVudEJ1cyA9IGV2ZW50QnVzXG4gIH1cbn1cblxuZXhwb3J0IHsgZXZlbnRCdXMgfVxuZXhwb3J0IGRlZmF1bHQgZXZlbnRCdXNcbiIsICIvKipcbiAqIEV2ZW50QnVzIE1peGluIGRsYSBTdGltdWx1cyBDb250cm9sbGVyc1xuICpcbiAqIERvZGFqZSBtZXRvZHkgZG8gXHUwMTQyYXR3ZWdvIGtvcnp5c3RhbmlhIHogRXZlbnRCdXMgdyBrb250cm9sZXJhY2ggU3RpbXVsdXMuXG4gKlxuICogVVx1MDE3Q3ljaWU6XG4gKlxuICogaW1wb3J0IHsgQ29udHJvbGxlciB9IGZyb20gXCJAaG90d2lyZWQvc3RpbXVsdXNcIlxuICogaW1wb3J0IHsgd2l0aEV2ZW50QnVzIH0gZnJvbSBcIi4uL21peGlucy9ldmVudF9idXNfbWl4aW4uanNcIlxuICpcbiAqIGV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgd2l0aEV2ZW50QnVzKENvbnRyb2xsZXIpIHtcbiAqICAgY29ubmVjdCgpIHtcbiAqICAgICAvLyBOYXNcdTAxNDJ1Y2h1aiBldmVudHVcbiAqICAgICB0aGlzLmxpc3Rlbignb2U6YmFza2V0OnVwZGF0ZWQnLCB0aGlzLmhhbmRsZUJhc2tldFVwZGF0ZSlcbiAqXG4gKiAgICAgLy8gbHViIHogYXV0by1jbGVhbnVwOlxuICogICAgIHRoaXMubGlzdGVuKCdvZTpiYXNrZXQ6dXBkYXRlZCcsIChkYXRhKSA9PiB7XG4gKiAgICAgICBjb25zb2xlLmxvZygnQmFza2V0IHVwZGF0ZWQ6JywgZGF0YSlcbiAqICAgICB9KVxuICogICB9XG4gKlxuICogICBoYW5kbGVCYXNrZXRVcGRhdGUoZGF0YSkge1xuICogICAgIGNvbnNvbGUubG9nKCdCYXNrZXQgdXBkYXRlZDonLCBkYXRhKVxuICogICB9XG4gKlxuICogICBzb21lQWN0aW9uKCkge1xuICogICAgIC8vIFd5ZW1pdHVqIGV2ZW50XG4gKiAgICAgdGhpcy5icm9hZGNhc3QoJ29lOmJhc2tldDppdGVtLWFkZGVkJywgeyBpdGVtSWQ6IDEyMyB9KVxuICogICB9XG4gKlxuICogICAvLyBkaXNjb25uZWN0KCkgYXV0b21hdHljem5pZSBjenlcdTAxNUJjaSB3c3p5c3RraWUgbGlzdGVuZXJ5IVxuICogfVxuICpcbiAqIEtvcnp5XHUwMTVCY2k6XG4gKiAtIEF1dG9tYXR5Y3puZSBjenlzemN6ZW5pZSBsaXN0ZW5lclx1MDBGM3cgdyBkaXNjb25uZWN0KClcbiAqIC0gS3JcdTAwRjN0c3plIEFQSTogbGlzdGVuKCksIGJyb2FkY2FzdCgpXG4gKiAtIFphY2hvd2FuaWUga29udGVrc3R1ICh0aGlzKSB3IGhhbmRsZXJhY2hcbiAqIC0gRGVidWcgaW5mbyB6IG5hendcdTAxMDUga29udHJvbGVyYVxuICovXG5cbmltcG9ydCB7IGV2ZW50QnVzIH0gZnJvbSAnLi4vdXRpbHMvZXZlbnRfYnVzLmpzJ1xuXG5leHBvcnQgZnVuY3Rpb24gd2l0aEV2ZW50QnVzKEJhc2VDb250cm9sbGVyKSB7XG4gIHJldHVybiBjbGFzcyBleHRlbmRzIEJhc2VDb250cm9sbGVyIHtcbiAgICBjb25zdHJ1Y3RvciguLi5hcmdzKSB7XG4gICAgICBzdXBlciguLi5hcmdzKVxuXG4gICAgICAvLyBQcnplY2hvd3VqIHJlZmVyZW5jamUgZG8gbGlzdGVuZXJcdTAwRjN3IGRsYSBjbGVhbnVwXG4gICAgICB0aGlzLl9ldmVudEJ1c0xpc3RlbmVycyA9IFtdXG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogTmFzXHUwMTQydWNodWogZXZlbnR1IHByemV6IEV2ZW50QnVzXG4gICAgICogQXV0b21hdHljem5pZSB1c3V3YSBsaXN0ZW5lcmEgdyBkaXNjb25uZWN0KClcbiAgICAgKlxuICAgICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICAgKiBAcGFyYW0ge2Z1bmN0aW9ufSBoYW5kbGVyIC0gSGFuZGxlciBmdW5jdGlvblxuICAgICAqIEBwYXJhbSB7b2JqZWN0fSBvcHRpb25zIC0gT3BjamVcbiAgICAgKiBAcGFyYW0ge2Jvb2xlYW59IG9wdGlvbnMub25jZSAtIEN6eSB3eWtvbmFcdTAxMDcgdHlsa28gcmF6IChkZWZhdWx0OiBmYWxzZSlcbiAgICAgKiBAcmV0dXJucyB7ZnVuY3Rpb259IEZ1bmtjamEgZG8gbWFudWFsbmVnbyB1c3VuaVx1MDExOWNpYSBsaXN0ZW5lcmFcbiAgICAgKi9cbiAgICBsaXN0ZW4oZXZlbnROYW1lLCBoYW5kbGVyLCBvcHRpb25zID0ge30pIHtcbiAgICAgIGNvbnN0IHsgb25jZSA9IGZhbHNlIH0gPSBvcHRpb25zXG5cbiAgICAgIC8vIEJpbmQgaGFuZGxlciBkbyB0aGlzIGtvbnRyb2xlcmFcbiAgICAgIGNvbnN0IGJvdW5kSGFuZGxlciA9IGhhbmRsZXIuYmluZCh0aGlzKVxuXG4gICAgICAvLyBEb2RhaiBwcmVmaXggeiBuYXp3XHUwMTA1IGtvbnRyb2xlcmEgZGxhIGRlYnVnb3dhbmlhXG4gICAgICBjb25zdCBjb250cm9sbGVyTmFtZSA9IHRoaXMuaWRlbnRpZmllciB8fCB0aGlzLmNvbnN0cnVjdG9yLm5hbWVcbiAgICAgIGNvbnN0IGRlYnVnSGFuZGxlciA9IChkYXRhKSA9PiB7XG4gICAgICAgIGlmIChldmVudEJ1cy5kZWJ1Zykge1xuICAgICAgICAgIGNvbnNvbGUubG9nKGBbJHtjb250cm9sbGVyTmFtZX1dIFJlY2VpdmVkIGV2ZW50IFwiJHtldmVudE5hbWV9XCJgLCBkYXRhKVxuICAgICAgICB9XG4gICAgICAgIGJvdW5kSGFuZGxlcihkYXRhKVxuICAgICAgfVxuXG4gICAgICAvLyBaYXJlamVzdHJ1aiBsaXN0ZW5lclxuICAgICAgY29uc3QgcmVtb3ZlTGlzdGVuZXIgPSBvbmNlXG4gICAgICAgID8gZXZlbnRCdXMub25jZShldmVudE5hbWUsIGRlYnVnSGFuZGxlcilcbiAgICAgICAgOiBldmVudEJ1cy5vbihldmVudE5hbWUsIGRlYnVnSGFuZGxlcilcblxuICAgICAgLy8gWmFjaG93YWogcmVmZXJlbmNqXHUwMTE5IGRvIGNsZWFudXBcbiAgICAgIHRoaXMuX2V2ZW50QnVzTGlzdGVuZXJzLnB1c2goeyBldmVudE5hbWUsIGhhbmRsZXI6IGRlYnVnSGFuZGxlciwgcmVtb3ZlTGlzdGVuZXIgfSlcblxuICAgICAgLy8gWndyXHUwMEYzXHUwMTA3IGZ1bmtjalx1MDExOSBkbyBtYW51YWxuZWdvIHVzdW5pXHUwMTE5Y2lhXG4gICAgICByZXR1cm4gcmVtb3ZlTGlzdGVuZXJcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBOYXNcdTAxNDJ1Y2h1aiBldmVudHUgdHlsa28gcmF6XG4gICAgICogU2hvcnRoYW5kIGRsYSBsaXN0ZW4oZXZlbnROYW1lLCBoYW5kbGVyLCB7IG9uY2U6IHRydWUgfSlcbiAgICAgKlxuICAgICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICAgKiBAcGFyYW0ge2Z1bmN0aW9ufSBoYW5kbGVyIC0gSGFuZGxlciBmdW5jdGlvblxuICAgICAqIEByZXR1cm5zIHtmdW5jdGlvbn0gRnVua2NqYSBkbyBtYW51YWxuZWdvIHVzdW5pXHUwMTE5Y2lhIGxpc3RlbmVyYVxuICAgICAqL1xuICAgIGxpc3Rlbk9uY2UoZXZlbnROYW1lLCBoYW5kbGVyKSB7XG4gICAgICByZXR1cm4gdGhpcy5saXN0ZW4oZXZlbnROYW1lLCBoYW5kbGVyLCB7IG9uY2U6IHRydWUgfSlcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBXeWVtaXR1aiBldmVudCBwcnpleiBFdmVudEJ1c1xuICAgICAqXG4gICAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgICAqIEBwYXJhbSB7Kn0gZGF0YSAtIERhbmUgZG8gcHJ6ZWthemFuaWFcbiAgICAgKi9cbiAgICBicm9hZGNhc3QoZXZlbnROYW1lLCBkYXRhID0ge30pIHtcbiAgICAgIGNvbnN0IGNvbnRyb2xsZXJOYW1lID0gdGhpcy5pZGVudGlmaWVyIHx8IHRoaXMuY29uc3RydWN0b3IubmFtZVxuXG4gICAgICBpZiAoZXZlbnRCdXMuZGVidWcpIHtcbiAgICAgICAgY29uc29sZS5sb2coYFske2NvbnRyb2xsZXJOYW1lfV0gQnJvYWRjYXN0aW5nIGV2ZW50IFwiJHtldmVudE5hbWV9XCJgLCBkYXRhKVxuICAgICAgfVxuXG4gICAgICBldmVudEJ1cy5lbWl0KGV2ZW50TmFtZSwgZGF0YSlcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBXeWVtaXR1aiBldmVudCBhc3luY2hyb25pY3puaWVcbiAgICAgKlxuICAgICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICAgKiBAcGFyYW0geyp9IGRhdGEgLSBEYW5lIGRvIHByemVrYXphbmlhXG4gICAgICogQHJldHVybnMge1Byb21pc2V9XG4gICAgICovXG4gICAgYXN5bmMgYnJvYWRjYXN0QXN5bmMoZXZlbnROYW1lLCBkYXRhID0ge30pIHtcbiAgICAgIHJldHVybiBldmVudEJ1cy5lbWl0QXN5bmMoZXZlbnROYW1lLCBkYXRhKVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIFBvY3pla2FqIG5hIGV2ZW50XG4gICAgICogUHJ6eWRhdG5lIHcgYXN5bmMgZmxvd3NcbiAgICAgKlxuICAgICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICAgKiBAcGFyYW0ge251bWJlcn0gdGltZW91dCAtIFRpbWVvdXQgdyBtc1xuICAgICAqIEByZXR1cm5zIHtQcm9taXNlfVxuICAgICAqL1xuICAgIGFzeW5jIHdhaXRGb3JFdmVudChldmVudE5hbWUsIHRpbWVvdXQgPSA1MDAwKSB7XG4gICAgICByZXR1cm4gZXZlbnRCdXMud2FpdEZvcihldmVudE5hbWUsIHRpbWVvdXQpXG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogVXN1XHUwMTQ0IGtvbmtyZXRueSBsaXN0ZW5lclxuICAgICAqXG4gICAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgICAqIEBwYXJhbSB7ZnVuY3Rpb259IGhhbmRsZXIgLSBIYW5kbGVyIGRvIHVzdW5pXHUwMTE5Y2lhXG4gICAgICovXG4gICAgc3RvcExpc3RlbmluZyhldmVudE5hbWUsIGhhbmRsZXIpIHtcbiAgICAgIGV2ZW50QnVzLm9mZihldmVudE5hbWUsIGhhbmRsZXIpXG5cbiAgICAgIC8vIFVzdVx1MDE0NCB6IG5hc3plaiBsaXN0eVxuICAgICAgdGhpcy5fZXZlbnRCdXNMaXN0ZW5lcnMgPSB0aGlzLl9ldmVudEJ1c0xpc3RlbmVycy5maWx0ZXIoXG4gICAgICAgIGxpc3RlbmVyID0+ICEobGlzdGVuZXIuZXZlbnROYW1lID09PSBldmVudE5hbWUgJiYgbGlzdGVuZXIuaGFuZGxlciA9PT0gaGFuZGxlcilcbiAgICAgIClcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBVc3VcdTAxNDQgd3N6eXN0a2llIGxpc3RlbmVyeSB0ZWdvIGtvbnRyb2xlcmFcbiAgICAgKiBBdXRvbWF0eWN6bmllIHd5d29cdTAxNDJ5d2FuZSB3IGRpc2Nvbm5lY3QoKVxuICAgICAqL1xuICAgIHN0b3BMaXN0ZW5pbmdBbGwoKSB7XG4gICAgICB0aGlzLl9ldmVudEJ1c0xpc3RlbmVycy5mb3JFYWNoKCh7IHJlbW92ZUxpc3RlbmVyIH0pID0+IHtcbiAgICAgICAgcmVtb3ZlTGlzdGVuZXIoKVxuICAgICAgfSlcblxuICAgICAgdGhpcy5fZXZlbnRCdXNMaXN0ZW5lcnMgPSBbXVxuXG4gICAgICBpZiAoZXZlbnRCdXMuZGVidWcpIHtcbiAgICAgICAgY29uc3QgY29udHJvbGxlck5hbWUgPSB0aGlzLmlkZW50aWZpZXIgfHwgdGhpcy5jb25zdHJ1Y3Rvci5uYW1lXG4gICAgICAgIGNvbnNvbGUubG9nKGBbJHtjb250cm9sbGVyTmFtZX1dIEFsbCBFdmVudEJ1cyBsaXN0ZW5lcnMgcmVtb3ZlZGApXG4gICAgICB9XG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogT3ZlcnJpZGUgZGlzY29ubmVjdCgpIFx1MDE3Q2VieSBhdXRvbWF0eWN6bmllIGN6eVx1MDE1QmNpXHUwMTA3IGxpc3RlbmVyeVxuICAgICAqL1xuICAgIGRpc2Nvbm5lY3QoKSB7XG4gICAgICB0aGlzLnN0b3BMaXN0ZW5pbmdBbGwoKVxuXG4gICAgICAvLyBXeXdvXHUwMTQyYWogb3J5Z2luYWxueSBkaXNjb25uZWN0IGplXHUwMTVCbGkgaXN0bmllamVcbiAgICAgIGlmIChzdXBlci5kaXNjb25uZWN0KSB7XG4gICAgICAgIHN1cGVyLmRpc2Nvbm5lY3QoKVxuICAgICAgfVxuICAgIH1cbiAgfVxufVxuXG5leHBvcnQgZGVmYXVsdCB3aXRoRXZlbnRCdXNcbiIsICIvKipcbiAqIE9uZS1QYWdlIENoZWNrb3V0IFN0cmlwZSBJbnRlZ3JhdGlvbiBDb250cm9sbGVyXG4gKlxuICogSW50ZWdyYXRlcyBTdHJpcGUgcGF5bWVudHMgd2l0aCB0aGUgb25lLXBhZ2UgY2hlY2tvdXQgbW9kdWxlIHZpYSBFdmVudEJ1cy5cbiAqIEltcGxlbWVudHMgdGhlIGV2ZW50IGNvbnRyYWN0IGRlZmluZWQgaW4gb25lLXBhZ2UgY2hlY2tvdXQgZG9jdW1lbnRhdGlvbi5cbiAqXG4gKiBSZXF1aXJlZCBFdmVudHMgdG8gTGlzdGVuOlxuICogLSBvZTpwYXltZW50Om1ldGhvZC1zZWxlY3RlZCAtIFVzZXIgc2VsZWN0cyBwYXltZW50IG1ldGhvZFxuICogLSBvZTpwYXltZW50OmNvbmZpcm0tcmVxdWVzdGVkIC0gQ29yZSByZXF1ZXN0cyBwYXltZW50IGNvbmZpcm1hdGlvblxuICpcbiAqIFJlcXVpcmVkIEV2ZW50cyB0byBFbWl0OlxuICogLSBvZTpwYXltZW50OmNvbmZpcm1lZCAtIFBheW1lbnQgc3VjY2Vzc2Z1bGx5IGNvbmZpcm1lZFxuICogLSBvZTpwYXltZW50OmZhaWxlZCAtIFBheW1lbnQgZmFpbGVkXG4gKlxuICogQHNlZSBkb2NzL1BBWU1FTlRfUFJPVklERVJfSU5URUdSQVRJT05fR1VJREUubWRcbiAqIEBzZWUgZG9jcy9kaWFncmFtcy9wYXltZW50LXByb3ZpZGVyLWludGVncmF0aW9uLzAzLWV2ZW50LWNvbnRyYWN0LWRldGFpbHMucHVtbFxuICovXG5cbmltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tIFwiQGhvdHdpcmVkL3N0aW11bHVzXCJcbmltcG9ydCB7IHdpdGhFdmVudEJ1cyB9IGZyb20gXCIuLi9taXhpbnMvZXZlbnRfYnVzX21peGluLmpzXCJcblxuZXhwb3J0IGRlZmF1bHQgY2xhc3MgZXh0ZW5kcyB3aXRoRXZlbnRCdXMoQ29udHJvbGxlcikge1xuICBzdGF0aWMgdmFsdWVzID0ge1xuICAgIHB1Ymxpc2hhYmxlS2V5OiBTdHJpbmcsXG4gICAgbW9kZTogU3RyaW5nLFxuICAgIHJldHVyblVybDogU3RyaW5nLFxuICB9XG5cbiAgc3RhdGljIHRhcmdldHMgPSBbXCJlbGVtZW50XCIsIFwibG9hZGVyXCIsIFwiZXJyb3JcIl1cblxuICAvKipcbiAgICogU3RpbXVsdXMgbGlmZWN5Y2xlOiBDb250cm9sbGVyIGNvbm5lY3RlZCB0byBET01cbiAgICovXG4gIGNvbm5lY3QoKSB7XG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gQ29ubmVjdGVkJylcblxuICAgIC8vIFJlZ2lzdGVyIEV2ZW50QnVzIGxpc3RlbmVycyAoYXV0b21hdGljIGNsZWFudXAgdmlhIHdpdGhFdmVudEJ1cyBtaXhpbilcbiAgICB0aGlzLmxpc3Rlbignb2U6cGF5bWVudDptZXRob2Qtc2VsZWN0ZWQnLCB0aGlzLmhhbmRsZU1ldGhvZFNlbGVjdGVkLmJpbmQodGhpcykpXG4gICAgdGhpcy5saXN0ZW4oJ29lOnBheW1lbnQ6Y29uZmlybS1yZXF1ZXN0ZWQnLCB0aGlzLmhhbmRsZUNvbmZpcm1SZXF1ZXN0LmJpbmQodGhpcykpXG4gICAgdGhpcy5saXN0ZW4oJ29lOmZvb3RlcjpzdWJtaXQtY2xpY2tlZCcsIHRoaXMuaGFuZGxlRm9vdGVyU3VibWl0LmJpbmQodGhpcykpXG5cbiAgICAvLyBJbml0aWFsaXplIHN0YXRlXG4gICAgdGhpcy5zdHJpcGUgPSBudWxsXG4gICAgdGhpcy5lbGVtZW50cyA9IG51bGxcbiAgICB0aGlzLnBheW1lbnRFbGVtZW50ID0gbnVsbFxuICAgIHRoaXMuY3VycmVudENvbnRyYWN0SWQgPSBudWxsXG4gICAgdGhpcy5jdXJyZW50T3JkZXJJZCA9IG51bGxcbiAgfVxuXG4gIC8qKlxuICAgKiBTdGltdWx1cyBsaWZlY3ljbGU6IENvbnRyb2xsZXIgZGlzY29ubmVjdGVkIGZyb20gRE9NXG4gICAqL1xuICBkaXNjb25uZWN0KCkge1xuICAgIGNvbnNvbGUubG9nKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIERpc2Nvbm5lY3RlZCcpXG5cbiAgICAvLyBFdmVudEJ1cyBsaXN0ZW5lcnMgYXJlIGF1dG9tYXRpY2FsbHkgY2xlYW5lZCB1cCBieSB3aXRoRXZlbnRCdXMgbWl4aW5cblxuICAgIC8vIENsZWFudXAgU3RyaXBlIHJlc291cmNlc1xuICAgIGlmICh0aGlzLnBheW1lbnRFbGVtZW50KSB7XG4gICAgICB0aGlzLnBheW1lbnRFbGVtZW50LmRlc3Ryb3koKVxuICAgICAgdGhpcy5wYXltZW50RWxlbWVudCA9IG51bGxcbiAgICB9XG4gICAgdGhpcy5lbGVtZW50cyA9IG51bGxcbiAgICB0aGlzLnN0cmlwZSA9IG51bGxcbiAgfVxuXG4gIC8qKlxuICAgKiBIYW5kbGUgb2U6cGF5bWVudDptZXRob2Qtc2VsZWN0ZWQgZXZlbnRcbiAgICpcbiAgICogRXZlbnQgRGV0YWlsOlxuICAgKiB7XG4gICAqICAgcGF5bWVudE1ldGhvZElkOiBzdHJpbmcsICAvLyBlLmcuLCAnb3hpZHN0cmlwZScsICdwYXlwYWwnXG4gICAqICAgcGF5bWVudE1ldGhvZFRpdGxlOiBzdHJpbmcgLy8gZS5nLiwgJ0NyZWRpdCBDYXJkIChTdHJpcGUpJ1xuICAgKiB9XG4gICAqXG4gICAqIFJlc3BvbnNpYmlsaXR5OlxuICAgKiAtIENoZWNrIGlmIHBheW1lbnRNZXRob2RJZCBtYXRjaGVzIFN0cmlwZVxuICAgKiAtIFNob3cgU3RyaXBlIFVJIGlmIG1hdGNoXG4gICAqIC0gSGlkZSBTdHJpcGUgVUkgaWYgbm8gbWF0Y2hcbiAgICovXG4gIGFzeW5jIGhhbmRsZU1ldGhvZFNlbGVjdGVkKGV2ZW50KSB7XG4gICAgY29uc3QgeyBwYXltZW50TWV0aG9kSWQgfSA9IGV2ZW50LmRldGFpbFxuXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gUGF5bWVudCBtZXRob2Qgc2VsZWN0ZWQ6JywgcGF5bWVudE1ldGhvZElkKVxuXG4gICAgaWYgKCF0aGlzLmlzU3RyaXBlTWV0aG9kKHBheW1lbnRNZXRob2RJZCkpIHtcbiAgICAgIHRoaXMuaGlkZVN0cmlwZVVJKClcbiAgICAgIHJldHVyblxuICAgIH1cblxuICAgIC8vIFNob3cgU3RyaXBlIFVJXG4gICAgdGhpcy5zaG93U3RyaXBlVUkoKVxuXG4gICAgLy8gTG9hZCBTdHJpcGUuanMgU0RLIGlmIG5vdCBsb2FkZWRcbiAgICBpZiAoIXRoaXMuc3RyaXBlKSB7XG4gICAgICBhd2FpdCB0aGlzLmxvYWRTdHJpcGVTREsoKVxuICAgIH1cblxuICAgIC8vIEluaXRpYWxpemUgUGF5bWVudCBFbGVtZW50XG4gICAgYXdhaXQgdGhpcy5pbml0aWFsaXplUGF5bWVudEVsZW1lbnQoKVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBvZTpmb290ZXI6c3VibWl0LWNsaWNrZWQgZXZlbnRcbiAgICpcbiAgICogRXZlbnQgRGV0YWlsOlxuICAgKiB7XG4gICAqICAgcGF5bWVudE1ldGhvZDogc3RyaW5nLFxuICAgKiAgIGJhc2tldElkOiBzdHJpbmcsXG4gICAqICAgdG90YWxQcmljZTogbnVtYmVyLFxuICAgKiAgIGN1cnJlbmN5OiBzdHJpbmcsXG4gICAqICAgY29uZmlybWVkOiBib29sZWFuXG4gICAqIH1cbiAgICpcbiAgICogUmVzcG9uc2liaWxpdHk6XG4gICAqIC0gVHJpZ2dlciBwYXltZW50IGNvbmZpcm1hdGlvbiByZXF1ZXN0XG4gICAqIC0gQnJvYWRjYXN0IG9lOnBheW1lbnQ6Y29uZmlybS1yZXF1ZXN0ZWQgZm9yIGNoZWNrb3V0IGxpZmVjeWNsZVxuICAgKi9cbiAgYXN5bmMgaGFuZGxlRm9vdGVyU3VibWl0KGV2ZW50KSB7XG4gICAgY29uc3QgeyBwYXltZW50TWV0aG9kLCBiYXNrZXRJZCwgdG90YWxQcmljZSwgY3VycmVuY3kgfSA9IGV2ZW50LmRldGFpbFxuXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gRm9vdGVyIHN1Ym1pdCBjbGlja2VkOicsIHtcbiAgICAgIHBheW1lbnRNZXRob2QsXG4gICAgICBiYXNrZXRJZCxcbiAgICAgIHRvdGFsUHJpY2UsXG4gICAgICBjdXJyZW5jeVxuICAgIH0pXG5cbiAgICBpZiAoIXRoaXMuaXNTdHJpcGVNZXRob2QocGF5bWVudE1ldGhvZCkpIHtcbiAgICAgIHJldHVybiAvLyBOb3QgU3RyaXBlIHBheW1lbnRcbiAgICB9XG5cbiAgICAvLyBCcm9hZGNhc3QgcGF5bWVudCBjb25maXJtYXRpb24gcmVxdWVzdFxuICAgIC8vIFRoaXMgd2lsbCB0cmlnZ2VyIHRoZSBjaGVja291dCBsaWZlY3ljbGUgdG8gY2FsbCBvdXIgaGFuZGxlQ29uZmlybVJlcXVlc3RcbiAgICB0aGlzLmJyb2FkY2FzdCgnb2U6cGF5bWVudDpjb25maXJtLXJlcXVlc3RlZCcsIHtcbiAgICAgIHBheW1lbnRNZXRob2RJZDogcGF5bWVudE1ldGhvZCxcbiAgICAgIGJhc2tldElkOiBiYXNrZXRJZCxcbiAgICAgIHRvdGFsUHJpY2U6IHRvdGFsUHJpY2UsXG4gICAgICBjdXJyZW5jeTogY3VycmVuY3lcbiAgICB9KVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBvZTpwYXltZW50OmNvbmZpcm0tcmVxdWVzdGVkIGV2ZW50XG4gICAqXG4gICAqIEV2ZW50IERldGFpbDpcbiAgICoge1xuICAgKiAgIGNvbnRyYWN0SWQ6IHN0cmluZywgICAgICAgLy8gUGF5bWVudENvbnRyYWN0IElEXG4gICAqICAgY2xpZW50U2VjcmV0OiBzdHJpbmcsICAgICAvLyBTdHJpcGUgY2xpZW50IHNlY3JldCAoZnJvbSBQYXltZW50SW50ZW50KVxuICAgKiAgIHBheW1lbnRNZXRob2RJZDogc3RyaW5nLCAgLy8gZS5nLiwgJ294aWRzdHJpcGUnXG4gICAqICAgcmV0dXJuVXJsOiBzdHJpbmcgICAgICAgICAvLyBVUkwgdG8gcmVkaXJlY3QgYWZ0ZXIgU0NBXG4gICAqIH1cbiAgICpcbiAgICogUmVzcG9uc2liaWxpdHk6XG4gICAqIC0gQ2hlY2sgaWYgcGF5bWVudE1ldGhvZElkIG1hdGNoZXMgU3RyaXBlXG4gICAqIC0gUHJvY2VzcyBwYXltZW50IHdpdGggU3RyaXBlIFNES1xuICAgKiAtIEVtaXQgb2U6cGF5bWVudDpjb25maXJtZWQgb3Igb2U6cGF5bWVudDpmYWlsZWRcbiAgICovXG4gIGFzeW5jIGhhbmRsZUNvbmZpcm1SZXF1ZXN0KGV2ZW50KSB7XG4gICAgY29uc3QgeyBwYXltZW50TWV0aG9kSWQsIGNsaWVudFNlY3JldCwgY29udHJhY3RJZCwgb3JkZXJJZCB9ID0gZXZlbnQuZGV0YWlsXG5cbiAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBDb25maXJtIHJlcXVlc3Q6Jywge1xuICAgICAgcGF5bWVudE1ldGhvZElkLFxuICAgICAgY2xpZW50U2VjcmV0OiBjbGllbnRTZWNyZXQgPyAnKioqJyA6ICdtaXNzaW5nJyxcbiAgICAgIGNvbnRyYWN0SWQsXG4gICAgICBvcmRlcklkXG4gICAgfSlcblxuICAgIGlmICghdGhpcy5pc1N0cmlwZU1ldGhvZChwYXltZW50TWV0aG9kSWQpKSB7XG4gICAgICByZXR1cm4gLy8gTm90IG15IHJlc3BvbnNpYmlsaXR5XG4gICAgfVxuXG4gICAgLy8gU2F2ZSBzdGF0ZVxuICAgIHRoaXMuY3VycmVudENvbnRyYWN0SWQgPSBjb250cmFjdElkXG4gICAgdGhpcy5jdXJyZW50T3JkZXJJZCA9IG9yZGVySWRcblxuICAgIC8vIFNob3cgbG9hZGVyXG4gICAgdGhpcy5zaG93TG9hZGVyKClcbiAgICB0aGlzLmhpZGVFcnJvcigpXG5cbiAgICB0cnkge1xuICAgICAgLy8gQ29uZmlybSBwYXltZW50IHdpdGggU3RyaXBlXG4gICAgICBjb25zdCByZXN1bHQgPSBhd2FpdCB0aGlzLmNvbmZpcm1QYXltZW50KGNsaWVudFNlY3JldClcblxuICAgICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gUGF5bWVudCBjb25maXJtZWQ6JywgcmVzdWx0KVxuXG4gICAgICAvLyBFbWl0IHN1Y2Nlc3MgZXZlbnRcbiAgICAgIHRoaXMuYnJvYWRjYXN0UGF5bWVudENvbmZpcm1lZChyZXN1bHQpXG4gICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gUGF5bWVudCBmYWlsZWQ6JywgZXJyb3IpXG5cbiAgICAgIC8vIFNob3cgZXJyb3JcbiAgICAgIHRoaXMuc2hvd0Vycm9yKGVycm9yLm1lc3NhZ2UpXG5cbiAgICAgIC8vIEVtaXQgZmFpbHVyZSBldmVudFxuICAgICAgdGhpcy5icm9hZGNhc3RQYXltZW50RmFpbGVkKGVycm9yKVxuICAgIH0gZmluYWxseSB7XG4gICAgICB0aGlzLmhpZGVMb2FkZXIoKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBDaGVjayBpZiBwYXltZW50IG1ldGhvZCBJRCBiZWxvbmdzIHRvIFN0cmlwZVxuICAgKi9cbiAgaXNTdHJpcGVNZXRob2QocGF5bWVudE1ldGhvZElkKSB7XG4gICAgaWYgKCFwYXltZW50TWV0aG9kSWQpIHtcbiAgICAgIHJldHVybiBmYWxzZVxuICAgIH1cblxuICAgIGNvbnN0IHN0cmlwZVBheW1lbnRNZXRob2RzID0gW1xuICAgICAgJ294aWRzdHJpcGUnLFxuICAgICAgJ294aWRzdHJpcGVfY2FyZCcsXG4gICAgICAnb3hpZHN0cmlwZV93YWxsZXQnLFxuICAgIF1cblxuICAgIHJldHVybiBzdHJpcGVQYXltZW50TWV0aG9kcy5zb21lKG1ldGhvZCA9PlxuICAgICAgcGF5bWVudE1ldGhvZElkLnRvTG93ZXJDYXNlKCkuaW5jbHVkZXMobWV0aG9kLnRvTG93ZXJDYXNlKCkpXG4gICAgKVxuICB9XG5cbiAgLyoqXG4gICAqIExvYWQgU3RyaXBlLmpzIFNESyBkeW5hbWljYWxseVxuICAgKi9cbiAgYXN5bmMgbG9hZFN0cmlwZVNESygpIHtcbiAgICBpZiAod2luZG93LlN0cmlwZSkge1xuICAgICAgdGhpcy5zdHJpcGUgPSB3aW5kb3cuU3RyaXBlKHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSlcbiAgICAgIHJldHVyblxuICAgIH1cblxuICAgIGNvbnNvbGUubG9nKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIExvYWRpbmcgU3RyaXBlLmpzIFNESy4uLicpXG5cbiAgICAvLyBMb2FkIFN0cmlwZS5qcyBzY3JpcHRcbiAgICBhd2FpdCBuZXcgUHJvbWlzZSgocmVzb2x2ZSwgcmVqZWN0KSA9PiB7XG4gICAgICBjb25zdCBzY3JpcHQgPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdzY3JpcHQnKVxuICAgICAgc2NyaXB0LnNyYyA9ICdodHRwczovL2pzLnN0cmlwZS5jb20vdjMvJ1xuICAgICAgc2NyaXB0LmFzeW5jID0gdHJ1ZVxuICAgICAgc2NyaXB0Lm9ubG9hZCA9IHJlc29sdmVcbiAgICAgIHNjcmlwdC5vbmVycm9yID0gcmVqZWN0XG4gICAgICBkb2N1bWVudC5oZWFkLmFwcGVuZENoaWxkKHNjcmlwdClcbiAgICB9KVxuXG4gICAgdGhpcy5zdHJpcGUgPSB3aW5kb3cuU3RyaXBlKHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSlcbiAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBTdHJpcGUuanMgU0RLIGxvYWRlZCcpXG4gIH1cblxuICAvKipcbiAgICogSW5pdGlhbGl6ZSBTdHJpcGUgUGF5bWVudCBFbGVtZW50XG4gICAqL1xuICBhc3luYyBpbml0aWFsaXplUGF5bWVudEVsZW1lbnQoKSB7XG4gICAgaWYgKCF0aGlzLnN0cmlwZSkge1xuICAgICAgY29uc29sZS5lcnJvcignW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBTdHJpcGUgU0RLIG5vdCBsb2FkZWQnKVxuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgaWYgKHRoaXMucGF5bWVudEVsZW1lbnQpIHtcbiAgICAgIC8vIEFscmVhZHkgaW5pdGlhbGl6ZWRcbiAgICAgIHJldHVyblxuICAgIH1cblxuICAgIGNvbnNvbGUubG9nKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIEluaXRpYWxpemluZyBQYXltZW50IEVsZW1lbnQuLi4nKVxuXG4gICAgLy8gQ3JlYXRlIEVsZW1lbnRzIGluc3RhbmNlICh3aWxsIGJlIGNvbmZpZ3VyZWQgd2l0aCBjbGllbnQgc2VjcmV0IGxhdGVyKVxuICAgIHRoaXMuZWxlbWVudHMgPSB0aGlzLnN0cmlwZS5lbGVtZW50cyh7XG4gICAgICBtb2RlOiAncGF5bWVudCcsXG4gICAgICBhbW91bnQ6IDEwMDAsIC8vIFBsYWNlaG9sZGVyLCB3aWxsIGJlIHVwZGF0ZWQgd2l0aCByZWFsIGNsaWVudCBzZWNyZXRcbiAgICAgIGN1cnJlbmN5OiAnZXVyJyxcbiAgICAgIGFwcGVhcmFuY2U6IHtcbiAgICAgICAgdGhlbWU6ICdzdHJpcGUnLFxuICAgICAgfSxcbiAgICB9KVxuXG4gICAgLy8gQ3JlYXRlIGFuZCBtb3VudCBQYXltZW50IEVsZW1lbnRcbiAgICB0aGlzLnBheW1lbnRFbGVtZW50ID0gdGhpcy5lbGVtZW50cy5jcmVhdGUoJ3BheW1lbnQnKVxuICAgIHRoaXMucGF5bWVudEVsZW1lbnQubW91bnQodGhpcy5lbGVtZW50VGFyZ2V0KVxuXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gUGF5bWVudCBFbGVtZW50IGluaXRpYWxpemVkJylcbiAgfVxuXG4gIC8qKlxuICAgKiBDb25maXJtIHBheW1lbnQgd2l0aCBTdHJpcGUgU0RLXG4gICAqXG4gICAqIEBwYXJhbSB7c3RyaW5nfSBjbGllbnRTZWNyZXQgLSBTdHJpcGUgUGF5bWVudEludGVudCBjbGllbnQgc2VjcmV0XG4gICAqIEByZXR1cm5zIHtQcm9taXNlPE9iamVjdD59IC0gUGF5bWVudCByZXN1bHRcbiAgICovXG4gIGFzeW5jIGNvbmZpcm1QYXltZW50KGNsaWVudFNlY3JldCkge1xuICAgIGlmICghdGhpcy5zdHJpcGUgfHwgIXRoaXMuZWxlbWVudHMpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcignU3RyaXBlIFNESyBub3QgaW5pdGlhbGl6ZWQnKVxuICAgIH1cblxuICAgIGNvbnNvbGUubG9nKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIENvbmZpcm1pbmcgcGF5bWVudCB3aXRoIFN0cmlwZS4uLicpXG5cbiAgICAvLyBVcGRhdGUgZWxlbWVudHMgd2l0aCBjbGllbnQgc2VjcmV0XG4gICAgdGhpcy5lbGVtZW50cy51cGRhdGUoe1xuICAgICAgY2xpZW50U2VjcmV0OiBjbGllbnRTZWNyZXQsXG4gICAgfSlcblxuICAgIC8vIENvbmZpcm0gcGF5bWVudFxuICAgIGNvbnN0IHJlc3VsdCA9IGF3YWl0IHRoaXMuc3RyaXBlLmNvbmZpcm1QYXltZW50KHtcbiAgICAgIGVsZW1lbnRzOiB0aGlzLmVsZW1lbnRzLFxuICAgICAgY29uZmlybVBhcmFtczoge1xuICAgICAgICByZXR1cm5fdXJsOiB0aGlzLnJldHVyblVybFZhbHVlIHx8IHdpbmRvdy5sb2NhdGlvbi5vcmlnaW4gKyAnL29yZGVyJyxcbiAgICAgIH0sXG4gICAgICByZWRpcmVjdDogJ2lmX3JlcXVpcmVkJywgLy8gT25seSByZWRpcmVjdCBpZiAzRCBTZWN1cmUgaXMgbmVlZGVkXG4gICAgfSlcblxuICAgIC8vIEhhbmRsZSByZXN1bHRcbiAgICBpZiAocmVzdWx0LmVycm9yKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IocmVzdWx0LmVycm9yLm1lc3NhZ2UgfHwgJ1BheW1lbnQgY29uZmlybWF0aW9uIGZhaWxlZCcpXG4gICAgfVxuXG4gICAgaWYgKHJlc3VsdC5wYXltZW50SW50ZW50Py5zdGF0dXMgPT09ICdzdWNjZWVkZWQnKSB7XG4gICAgICByZXR1cm4ge1xuICAgICAgICBwYXltZW50SW50ZW50SWQ6IHJlc3VsdC5wYXltZW50SW50ZW50LmlkLFxuICAgICAgICBzdGF0dXM6IHJlc3VsdC5wYXltZW50SW50ZW50LnN0YXR1cyxcbiAgICAgICAgYW1vdW50OiByZXN1bHQucGF5bWVudEludGVudC5hbW91bnQsXG4gICAgICAgIGN1cnJlbmN5OiByZXN1bHQucGF5bWVudEludGVudC5jdXJyZW5jeSxcbiAgICAgIH1cbiAgICB9XG5cbiAgICAvLyBQYXltZW50IG5vdCBzdWNjZWVkZWQgeWV0IChlLmcuLCByZXF1aXJlcyBhY3Rpb24pXG4gICAgdGhyb3cgbmV3IEVycm9yKGBQYXltZW50IG5vdCBjb25maXJtZWQuIFN0YXR1czogJHtyZXN1bHQucGF5bWVudEludGVudD8uc3RhdHVzIHx8ICd1bmtub3duJ31gKVxuICB9XG5cbiAgLyoqXG4gICAqIEJyb2FkY2FzdCBvZTpwYXltZW50OmNvbmZpcm1lZCBldmVudFxuICAgKi9cbiAgYnJvYWRjYXN0UGF5bWVudENvbmZpcm1lZChwYXltZW50UmVzdWx0KSB7XG4gICAgdGhpcy5icm9hZGNhc3QoJ29lOnBheW1lbnQ6Y29uZmlybWVkJywge1xuICAgICAgcHJvdmlkZXI6ICdzdHJpcGUnLFxuICAgICAgY29udHJhY3RJZDogdGhpcy5jdXJyZW50Q29udHJhY3RJZCxcbiAgICAgIG9yZGVySWQ6IHRoaXMuY3VycmVudE9yZGVySWQsXG4gICAgICB0cmFuc2FjdGlvbklkOiBwYXltZW50UmVzdWx0LnBheW1lbnRJbnRlbnRJZCxcbiAgICAgIG1ldGFkYXRhOiBwYXltZW50UmVzdWx0LFxuICAgIH0pXG5cbiAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBQYXltZW50IGNvbmZpcm1lZCBldmVudCBkaXNwYXRjaGVkJylcbiAgfVxuXG4gIC8qKlxuICAgKiBCcm9hZGNhc3Qgb2U6cGF5bWVudDpmYWlsZWQgZXZlbnRcbiAgICovXG4gIGJyb2FkY2FzdFBheW1lbnRGYWlsZWQoZXJyb3IpIHtcbiAgICB0aGlzLmJyb2FkY2FzdCgnb2U6cGF5bWVudDpmYWlsZWQnLCB7XG4gICAgICBwcm92aWRlcjogJ3N0cmlwZScsXG4gICAgICBjb250cmFjdElkOiB0aGlzLmN1cnJlbnRDb250cmFjdElkLFxuICAgICAgb3JkZXJJZDogdGhpcy5jdXJyZW50T3JkZXJJZCxcbiAgICAgIGVycm9yOiBlcnJvci5tZXNzYWdlIHx8ICdQYXltZW50IGZhaWxlZCcsXG4gICAgICBlcnJvckNvZGU6IGVycm9yLmNvZGUgfHwgJ1NUUklQRV9FUlJPUicsXG4gICAgfSlcblxuICAgIGNvbnNvbGUubG9nKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIFBheW1lbnQgZmFpbGVkIGV2ZW50IGRpc3BhdGNoZWQnKVxuICB9XG5cbiAgLyoqXG4gICAqIFVJIEhlbHBlcjogU2hvdyBTdHJpcGUgVUlcbiAgICogU2hvd3MgdGhlIGVudGlyZSBTdHJpcGUgcHJvdmlkZXIgd3JhcHBlciAobm90IGp1c3QgdGhlIHBheW1lbnQgZWxlbWVudClcbiAgICovXG4gIHNob3dTdHJpcGVVSSgpIHtcbiAgICAvLyBTaG93IHRoZSB3cmFwcGVyIChjb250cm9sbGVyIGVsZW1lbnQpXG4gICAgdGhpcy5lbGVtZW50LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG5cbiAgICAvLyBBbHNvIHNob3cgdGhlIHBheW1lbnQgZWxlbWVudCBjb250YWluZXIgaWYgaXQgZXhpc3RzXG4gICAgaWYgKHRoaXMuaGFzRWxlbWVudFRhcmdldCkge1xuICAgICAgdGhpcy5lbGVtZW50VGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFVJIEhlbHBlcjogSGlkZSBTdHJpcGUgVUlcbiAgICogSGlkZXMgdGhlIGVudGlyZSBTdHJpcGUgcHJvdmlkZXIgd3JhcHBlclxuICAgKi9cbiAgaGlkZVN0cmlwZVVJKCkge1xuICAgIC8vIEhpZGUgdGhlIHdyYXBwZXIgKGNvbnRyb2xsZXIgZWxlbWVudClcbiAgICB0aGlzLmVsZW1lbnQuc3R5bGUuZGlzcGxheSA9ICdub25lJ1xuICB9XG5cbiAgLyoqXG4gICAqIFVJIEhlbHBlcjogU2hvdyBsb2FkZXJcbiAgICovXG4gIHNob3dMb2FkZXIoKSB7XG4gICAgaWYgKHRoaXMuaGFzTG9hZGVyVGFyZ2V0KSB7XG4gICAgICB0aGlzLmxvYWRlclRhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ2Jsb2NrJ1xuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBVSSBIZWxwZXI6IEhpZGUgbG9hZGVyXG4gICAqL1xuICBoaWRlTG9hZGVyKCkge1xuICAgIGlmICh0aGlzLmhhc0xvYWRlclRhcmdldCkge1xuICAgICAgdGhpcy5sb2FkZXJUYXJnZXQuc3R5bGUuZGlzcGxheSA9ICdub25lJ1xuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBVSSBIZWxwZXI6IFNob3cgZXJyb3IgbWVzc2FnZVxuICAgKi9cbiAgc2hvd0Vycm9yKG1lc3NhZ2UpIHtcbiAgICBpZiAodGhpcy5oYXNFcnJvclRhcmdldCkge1xuICAgICAgdGhpcy5lcnJvclRhcmdldC50ZXh0Q29udGVudCA9IG1lc3NhZ2VcbiAgICAgIHRoaXMuZXJyb3JUYXJnZXQuc3R5bGUuZGlzcGxheSA9ICdibG9jaydcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogVUkgSGVscGVyOiBIaWRlIGVycm9yIG1lc3NhZ2VcbiAgICovXG4gIGhpZGVFcnJvcigpIHtcbiAgICBpZiAodGhpcy5oYXNFcnJvclRhcmdldCkge1xuICAgICAgdGhpcy5lcnJvclRhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ25vbmUnXG4gICAgICB0aGlzLmVycm9yVGFyZ2V0LnRleHRDb250ZW50ID0gJydcbiAgICB9XG4gIH1cbn0iLCAiaW1wb3J0IHsgQ29udHJvbGxlciB9IGZyb20gXCJAaG90d2lyZWQvc3RpbXVsdXNcIlxuaW1wb3J0IHsgd2l0aEV2ZW50QnVzIH0gZnJvbSBcIi4uL21peGlucy9ldmVudF9idXNfbWl4aW4uanNcIlxuXG4vKipcbiAqIFN0cmlwZSBDaGVja291dCBGb290ZXIgQ29udHJvbGxlclxuICpcbiAqIE1hbmFnZXMgU3RyaXBlLXNwZWNpZmljIGZvb3RlciBiZWhhdmlvciBpbiBvbmUtcGFnZSBjaGVja291dDpcbiAqIC0gVGVybXMgdmFsaWRhdGlvbiBhbmQgc3RhdGUgbWFuYWdlbWVudFxuICogLSBQYXltZW50IHByb2Nlc3NpbmcgY29vcmRpbmF0aW9uIHdpdGggcGF5bWVudCBjb250cm9sbGVyXG4gKiAtIEV2ZW50QnVzIGludGVncmF0aW9uIGZvciBzdGF0ZSBzeW5jaHJvbml6YXRpb25cbiAqIC0gTG9hZGluZyBzdGF0ZXMgYW5kIGVycm9yIGhhbmRsaW5nXG4gKiAtIER5bmFtaWMgdG90YWwgcHJpY2UgdXBkYXRlc1xuICpcbiAqIEludGVncmF0aW9uOlxuICogLSBVc2VzIEV2ZW50QnVzIG1peGluIGZvciBhdXRvbWF0aWMgbGlzdGVuZXIgY2xlYW51cFxuICogLSBDb29yZGluYXRlcyB3aXRoIHBheW1lbnQgY29udHJvbGxlciBmb3IgYWN0dWFsIHBheW1lbnQgcHJvY2Vzc2luZ1xuICogLSBSZXNwb25kcyB0byBiYXNrZXQgYW5kIHBheW1lbnQgc3RhdGUgY2hhbmdlc1xuICpcbiAqIEVtaXR0ZWQgRXZlbnRzOlxuICogLSBvZTpmb290ZXI6dGVybXMtYWNjZXB0ZWQgLSBXaGVuIHRlcm1zIGNoZWNrYm94IGlzIGNoZWNrZWRcbiAqIC0gb2U6Zm9vdGVyOnN1Ym1pdC1jbGlja2VkIC0gV2hlbiBzdWJtaXQgYnV0dG9uIGlzIGNsaWNrZWRcbiAqXG4gKiBMaXN0ZW5lZCBFdmVudHM6XG4gKiAtIG9lOmJhc2tldDp1cGRhdGVkIC0gQmFza2V0IGNvbnRlbnRzIGNoYW5nZWRcbiAqIC0gb2U6cGF5bWVudDpwcm9jZXNzaW5nIC0gUGF5bWVudCBwcm9jZXNzaW5nIHN0YXJ0ZWRcbiAqIC0gb2U6cGF5bWVudDpjb21wbGV0ZSAtIFBheW1lbnQgY29tcGxldGVkIHN1Y2Nlc3NmdWxseVxuICogLSBvZTpwYXltZW50OmVycm9yIC0gUGF5bWVudCBwcm9jZXNzaW5nIGZhaWxlZFxuICpcbiAqIEBzZWUgZG9jcy9GT09URVJfV0lER0VUX0FSQ0hJVEVDVFVSRS5tZFxuICogQHNlZSBkb2NzL0VWRU5UX0JVU19HVUlERS5tZFxuICovXG5leHBvcnQgZGVmYXVsdCBjbGFzcyBleHRlbmRzIHdpdGhFdmVudEJ1cyhDb250cm9sbGVyKSB7XG4gICAgc3RhdGljIHRhcmdldHMgPSBbXG4gICAgICAgIFwic3VibWl0QnV0dG9uXCIsICAgICAvLyBNYWluIHN1Ym1pdCBidXR0b25cbiAgICAgICAgXCJsb2FkZXJcIiwgICAgICAgICAgIC8vIExvYWRpbmcgb3ZlcmxheVxuICAgICAgICBcImVycm9yXCIsICAgICAgICAgICAgLy8gRXJyb3IgbWVzc2FnZSBjb250YWluZXJcbiAgICAgICAgXCJlcnJvck1lc3NhZ2VcIiAgICAgIC8vIEVycm9yIG1lc3NhZ2UgdGV4dCBlbGVtZW50XG4gICAgXVxuXG4gICAgc3RhdGljIHZhbHVlcyA9IHtcbiAgICAgICAgYmFza2V0SWQ6IFN0cmluZywgICAgICAgICAgIC8vIEN1cnJlbnQgYmFza2V0IElEXG4gICAgICAgIHBheW1lbnRNZXRob2Q6IFN0cmluZywgICAgICAvLyBQYXltZW50IG1ldGhvZCBJRCAoZS5nLiwgJ294aWRzdHJpcGUnKVxuICAgICAgICB0b3RhbFByaWNlOiBOdW1iZXIsICAgICAgICAgLy8gVG90YWwgb3JkZXIgYW1vdW50XG4gICAgICAgIGN1cnJlbmN5OiBTdHJpbmcsICAgICAgICAgICAvLyBDdXJyZW5jeSBjb2RlIChlLmcuLCAnRVVSJylcbiAgICAgICAgY3NyZlRva2VuOiBTdHJpbmcgICAgICAgICAgLy8gQ1NSRiB0b2tlbiBmb3IgQVBJIGNhbGxzXG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogQ29udHJvbGxlciBpbml0aWFsaXphdGlvblxuICAgICAqL1xuICAgIGNvbm5lY3QoKSB7XG4gICAgICAgIGNvbnNvbGUubG9nKCdbU3RyaXBlQ2hlY2tvdXRGb290ZXJdIENvbm5lY3RlZCcsIHtcbiAgICAgICAgICAgIGJhc2tldElkOiB0aGlzLmJhc2tldElkVmFsdWUsXG4gICAgICAgICAgICBwYXltZW50TWV0aG9kOiB0aGlzLnBheW1lbnRNZXRob2RWYWx1ZSxcbiAgICAgICAgICAgIHRvdGFsUHJpY2U6IHRoaXMudG90YWxQcmljZVZhbHVlLFxuICAgICAgICAgICAgY3VycmVuY3k6IHRoaXMuY3VycmVuY3lWYWx1ZVxuICAgICAgICB9KVxuXG4gICAgICAgIC8vIFJlZ2lzdGVyIEV2ZW50QnVzIGxpc3RlbmVycyAoYXV0b21hdGljIGNsZWFudXAgb24gZGlzY29ubmVjdCEpXG4gICAgICAgIHRoaXMuc2V0dXBFdmVudExpc3RlbmVycygpXG5cbiAgICAgICAgLy8gSW5pdGlhbGl6ZSBidXR0b24gc3RhdGVcbiAgICAgICAgdGhpcy51cGRhdGVCdXR0b25TdGF0ZSgpXG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogU2V0dXAgRXZlbnRCdXMgZXZlbnQgbGlzdGVuZXJzXG4gICAgICpcbiAgICAgKiBVc2VzIEV2ZW50QnVzIG1peGluJ3MgbGlzdGVuKCkgbWV0aG9kIGZvciBhdXRvbWF0aWMgY2xlYW51cFxuICAgICAqL1xuICAgIHNldHVwRXZlbnRMaXN0ZW5lcnMoKSB7XG4gICAgICAgIC8vIExpc3RlbiB0byBiYXNrZXQgdXBkYXRlc1xuICAgICAgICB0aGlzLmxpc3Rlbignb2U6YmFza2V0OnVwZGF0ZWQnLCAoZGF0YSkgPT4ge1xuICAgICAgICAgICAgY29uc29sZS5sb2coJ1tTdHJpcGVDaGVja291dEZvb3Rlcl0gQmFza2V0IHVwZGF0ZWQnLCBkYXRhKVxuICAgICAgICAgICAgdGhpcy5oYW5kbGVCYXNrZXRVcGRhdGUoZGF0YSlcbiAgICAgICAgfSlcblxuICAgICAgICAvLyBMaXN0ZW4gdG8gcGF5bWVudCBsaWZlY3ljbGUgZXZlbnRzXG4gICAgICAgIHRoaXMubGlzdGVuKCdvZTpwYXltZW50OnByb2Nlc3NpbmcnLCAoZGF0YSkgPT4ge1xuICAgICAgICAgICAgY29uc29sZS5sb2coJ1tTdHJpcGVDaGVja291dEZvb3Rlcl0gUGF5bWVudCBwcm9jZXNzaW5nJywgZGF0YSlcbiAgICAgICAgICAgIHRoaXMuc2hvd0xvYWRlcigpXG4gICAgICAgIH0pXG5cbiAgICAgICAgdGhpcy5saXN0ZW4oJ29lOnBheW1lbnQ6Y29tcGxldGUnLCAoZGF0YSkgPT4ge1xuICAgICAgICAgICAgY29uc29sZS5sb2coJ1tTdHJpcGVDaGVja291dEZvb3Rlcl0gUGF5bWVudCBjb21wbGV0ZScsIGRhdGEpXG4gICAgICAgICAgICB0aGlzLmhpZGVMb2FkZXIoKVxuICAgICAgICAgICAgdGhpcy5zaG93U3VjY2VzcygpXG4gICAgICAgIH0pXG5cbiAgICAgICAgdGhpcy5saXN0ZW4oJ29lOnBheW1lbnQ6ZXJyb3InLCAoZGF0YSkgPT4ge1xuICAgICAgICAgICAgY29uc29sZS5sb2coJ1tTdHJpcGVDaGVja291dEZvb3Rlcl0gUGF5bWVudCBlcnJvcicsIGRhdGEpXG4gICAgICAgICAgICB0aGlzLmhpZGVMb2FkZXIoKVxuICAgICAgICAgICAgdGhpcy5zaG93RXJyb3IoZGF0YS5tZXNzYWdlIHx8ICdQYXltZW50IHByb2Nlc3NpbmcgZmFpbGVkJylcbiAgICAgICAgfSlcblxuICAgICAgICAvLyBMaXN0ZW4gdG8gcGF5bWVudCBtZXRob2Qgc2VsZWN0aW9uIGNoYW5nZXNcbiAgICAgICAgdGhpcy5saXN0ZW4oJ29lOnBheW1lbnQ6bWV0aG9kLXNlbGVjdGVkJywgKGRhdGEpID0+IHtcbiAgICAgICAgICAgIGNvbnNvbGUubG9nKCdbU3RyaXBlQ2hlY2tvdXRGb290ZXJdIFBheW1lbnQgbWV0aG9kIHNlbGVjdGVkJywgZGF0YSlcbiAgICAgICAgICAgIHRoaXMuaGFuZGxlUGF5bWVudE1ldGhvZENoYW5nZShkYXRhKVxuICAgICAgICB9KVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIE5PVEU6IFRlcm1zIHZhbGlkYXRpb24gcmVtb3ZlZCAtIGhhbmRsZWQgYnkgY2hlY2tvdXQtZm9vdGVyLW1hbmFnZXJcbiAgICAgKlxuICAgICAqIFRlcm1zIGNoZWNrYm94IGlzIG5vdyBpbiBQYXJ0IDEgKHN0YW5kYXJkIGNvbnNlbnRzKSBvZiBmb290ZXIgYXJjaGl0ZWN0dXJlLlxuICAgICAqIGNoZWNrb3V0LWZvb3Rlci1tYW5hZ2VyIGNvbnRyb2xsZXIgaGFuZGxlcyBhbGwgdGVybXMgdmFsaWRhdGlvbi5cbiAgICAgKi9cblxuICAgIC8qKlxuICAgICAqIEhhbmRsZSBzdWJtaXQgYnV0dG9uIGNsaWNrXG4gICAgICpcbiAgICAgKiBJTVBPUlRBTlQ6IEZvb3RlciB3aWRnZXQgZG9lcyBOT1QgcHJvY2VzcyBwYXltZW50IGRpcmVjdGx5IVxuICAgICAqIEl0IG9ubHkgYnJvYWRjYXN0cyBvZTpmb290ZXI6c3VibWl0LWNsaWNrZWQgZXZlbnQuXG4gICAgICogUGF5bWVudCBwcm9jZXNzaW5nIGlzIGhhbmRsZWQgYnk6XG4gICAgICogMS4gY2hlY2tvdXQtbGlmZWN5Y2xlLWNvbnRyb2xsZXIgXHUyMTkyIGJyb2FkY2FzdHMgb2U6cGF5bWVudDpjb25maXJtLXJlcXVlc3RlZFxuICAgICAqIDIuIG9uZXBhZ2Utc3RyaXBlLWNvbnRyb2xsZXIgXHUyMTkyIGNvbmZpcm1zIHBheW1lbnQgd2l0aCBTdHJpcGVcbiAgICAgKiAzLiBjaGVja291dC1saWZlY3ljbGUtY29udHJvbGxlciBcdTIxOTIgcGxhY2VzIG9yZGVyIHZpYSBBUElcbiAgICAgKlxuICAgICAqIFRoaXMgc2VwYXJhdGlvbiBhbGxvd3MgcGF5bWVudCBwcm92aWRlcnMgdG8gaGFuZGxlIHRoZWlyIG93biBwYXltZW50IGxvZ2ljXG4gICAgICogd2hpbGUgZm9vdGVyIHdpZGdldCByZW1haW5zIGdlbmVyaWMgYW5kIHJldXNhYmxlLlxuICAgICAqL1xuICAgIGFzeW5jIHByb2Nlc3NQYXltZW50KGV2ZW50KSB7XG4gICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KClcblxuICAgICAgICBjb25zb2xlLmxvZygnW1N0cmlwZUNoZWNrb3V0Rm9vdGVyXSBTdWJtaXQgYnV0dG9uIGNsaWNrZWQgLSBicm9hZGNhc3RpbmcgZXZlbnQnKVxuXG4gICAgICAgIC8vIEJyb2FkY2FzdCBmb290ZXIgc3VibWl0IGV2ZW50XG4gICAgICAgIC8vIGNoZWNrb3V0LWxpZmVjeWNsZS1jb250cm9sbGVyIHdpbGwgb3JjaGVzdHJhdGUgdGhlIHBheW1lbnQgZmxvd1xuICAgICAgICB0aGlzLmJyb2FkY2FzdCgnb2U6Zm9vdGVyOnN1Ym1pdC1jbGlja2VkJywge1xuICAgICAgICAgICAgcGF5bWVudE1ldGhvZDogdGhpcy5wYXltZW50TWV0aG9kVmFsdWUsXG4gICAgICAgICAgICBiYXNrZXRJZDogdGhpcy5iYXNrZXRJZFZhbHVlLFxuICAgICAgICAgICAgdG90YWxQcmljZTogdGhpcy50b3RhbFByaWNlVmFsdWUsXG4gICAgICAgICAgICBjdXJyZW5jeTogdGhpcy5jdXJyZW5jeVZhbHVlLFxuICAgICAgICAgICAgY29uZmlybWVkOiB0cnVlIC8vIFRlcm1zIGFscmVhZHkgY29uZmlybWVkIGJ5IGNoZWNrb3V0LWZvb3Rlci1tYW5hZ2VyXG4gICAgICAgIH0pXG5cbiAgICAgICAgY29uc29sZS5sb2coJ1tTdHJpcGVDaGVja291dEZvb3Rlcl0gRXZlbnQgYnJvYWRjYXN0ZWQgLSB3YWl0aW5nIGZvciBjaGVja291dCBsaWZlY3ljbGUnKVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIEhhbmRsZSBiYXNrZXQgdXBkYXRlIGV2ZW50XG4gICAgICpcbiAgICAgKiBVcGRhdGVzIHRvdGFsIHByaWNlIGRpc3BsYXkgYW5kIHZhbGlkYXRlcyBzdGF0ZVxuICAgICAqL1xuICAgIGhhbmRsZUJhc2tldFVwZGF0ZShkYXRhKSB7XG4gICAgICAgIC8vIFVwZGF0ZSB0b3RhbCBwcmljZSBpZiBwcm92aWRlZFxuICAgICAgICBpZiAoZGF0YS50b3RhbFByaWNlICE9PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgIHRoaXMudG90YWxQcmljZVZhbHVlID0gZGF0YS50b3RhbFByaWNlXG4gICAgICAgICAgICB0aGlzLnVwZGF0ZVRvdGFsRGlzcGxheShkYXRhLnRvdGFsUHJpY2UsIGRhdGEuY3VycmVuY3kgfHwgdGhpcy5jdXJyZW5jeVZhbHVlKVxuICAgICAgICB9XG5cbiAgICAgICAgLy8gVXBkYXRlIGJhc2tldCBJRCBpZiBjaGFuZ2VkXG4gICAgICAgIGlmIChkYXRhLmJhc2tldElkKSB7XG4gICAgICAgICAgICB0aGlzLmJhc2tldElkVmFsdWUgPSBkYXRhLmJhc2tldElkXG4gICAgICAgIH1cblxuICAgICAgICAvLyBSZS12YWxpZGF0ZSBidXR0b24gc3RhdGVcbiAgICAgICAgdGhpcy51cGRhdGVCdXR0b25TdGF0ZSgpXG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogSGFuZGxlIHBheW1lbnQgbWV0aG9kIGNoYW5nZSBldmVudFxuICAgICAqXG4gICAgICogU2hvdy9oaWRlIGZvb3RlciBiYXNlZCBvbiBwYXltZW50IG1ldGhvZCBzZWxlY3Rpb25cbiAgICAgKi9cbiAgICBoYW5kbGVQYXltZW50TWV0aG9kQ2hhbmdlKGRhdGEpIHtcbiAgICAgICAgY29uc3QgaXNTdHJpcGUgPSBkYXRhLnBheW1lbnRNZXRob2RJZCA9PT0gdGhpcy5wYXltZW50TWV0aG9kVmFsdWVcblxuICAgICAgICBpZiAoaXNTdHJpcGUpIHtcbiAgICAgICAgICAgIC8vIFNob3cgU3RyaXBlIGZvb3RlclxuICAgICAgICAgICAgdGhpcy5lbGVtZW50LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG4gICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgICAvLyBIaWRlIFN0cmlwZSBmb290ZXIgaWYgZGlmZmVyZW50IHBheW1lbnQgbWV0aG9kIHNlbGVjdGVkXG4gICAgICAgICAgICB0aGlzLmVsZW1lbnQuc3R5bGUuZGlzcGxheSA9ICdub25lJ1xuICAgICAgICB9XG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogVXBkYXRlIHRvdGFsIHByaWNlIGRpc3BsYXkgaW4gc3VibWl0IGJ1dHRvblxuICAgICAqL1xuICAgIHVwZGF0ZVRvdGFsRGlzcGxheSh0b3RhbFByaWNlLCBjdXJyZW5jeSkge1xuICAgICAgICBjb25zdCBhbW91bnRFbGVtZW50ID0gdGhpcy5zdWJtaXRCdXR0b25UYXJnZXQucXVlcnlTZWxlY3RvcignLmJ1dHRvbi1hbW91bnQnKVxuICAgICAgICBpZiAoYW1vdW50RWxlbWVudCkge1xuICAgICAgICAgICAgY29uc3QgZm9ybWF0dGVkUHJpY2UgPSB0aGlzLmZvcm1hdFByaWNlKHRvdGFsUHJpY2UpXG4gICAgICAgICAgICBhbW91bnRFbGVtZW50LnRleHRDb250ZW50ID0gYCR7Zm9ybWF0dGVkUHJpY2V9ICR7Y3VycmVuY3l9YFxuICAgICAgICB9XG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogRm9ybWF0IHByaWNlIHdpdGggcHJvcGVyIGRlY2ltYWwgcGxhY2VzXG4gICAgICovXG4gICAgZm9ybWF0UHJpY2UocHJpY2UpIHtcbiAgICAgICAgcmV0dXJuIHBhcnNlRmxvYXQocHJpY2UpLnRvRml4ZWQoMikucmVwbGFjZSgnLicsICcsJylcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBVcGRhdGUgc3VibWl0IGJ1dHRvbiBzdGF0ZVxuICAgICAqXG4gICAgICogQnV0dG9uIGlzIGVuYWJsZWQgYnkgZGVmYXVsdC4gY2hlY2tvdXQtZm9vdGVyLW1hbmFnZXIgaGFuZGxlcyB0ZXJtcyB2YWxpZGF0aW9uLlxuICAgICAqL1xuICAgIHVwZGF0ZUJ1dHRvblN0YXRlKCkge1xuICAgICAgICAvLyBCdXR0b24gc3RhdGUgaXMgbm93IGNvbnRyb2xsZWQgYnkgY2hlY2tvdXQtZm9vdGVyLW1hbmFnZXIgKFBhcnQgMSlcbiAgICAgICAgLy8gVGhpcyB3aWRnZXQganVzdCBoYW5kbGVzIHBheW1lbnQtc3BlY2lmaWMgVUkgc3RhdGVzXG4gICAgICAgIC8vIEtlZXAgYnV0dG9uIGVuYWJsZWQgdW5sZXNzIGV4cGxpY2l0bHkgZGlzYWJsZWQgYnkgbG9hZGluZyBzdGF0ZVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIFNob3cgbG9hZGluZyBvdmVybGF5XG4gICAgICovXG4gICAgc2hvd0xvYWRlcigpIHtcbiAgICAgICAgY29uc29sZS5sb2coJ1tTdHJpcGVDaGVja291dEZvb3Rlcl0gU2hvd2luZyBsb2FkZXInKVxuXG4gICAgICAgIC8vIFNob3cgc3Bpbm5lciBpbiBidXR0b25cbiAgICAgICAgY29uc3QgYnV0dG9uQ29udGVudCA9IHRoaXMuc3VibWl0QnV0dG9uVGFyZ2V0LnF1ZXJ5U2VsZWN0b3IoJy5idXR0b24tY29udGVudCcpXG4gICAgICAgIGNvbnN0IGJ1dHRvblNwaW5uZXIgPSB0aGlzLnN1Ym1pdEJ1dHRvblRhcmdldC5xdWVyeVNlbGVjdG9yKCcuYnV0dG9uLXNwaW5uZXInKVxuXG4gICAgICAgIGlmIChidXR0b25Db250ZW50KSBidXR0b25Db250ZW50LmNsYXNzTGlzdC5hZGQoJ2Qtbm9uZScpXG4gICAgICAgIGlmIChidXR0b25TcGlubmVyKSBidXR0b25TcGlubmVyLmNsYXNzTGlzdC5yZW1vdmUoJ2Qtbm9uZScpXG5cbiAgICAgICAgLy8gU2hvdyBmdWxsLXNjcmVlbiBvdmVybGF5XG4gICAgICAgIHRoaXMubG9hZGVyVGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnZmxleCdcblxuICAgICAgICAvLyBEaXNhYmxlIGJ1dHRvblxuICAgICAgICB0aGlzLnN1Ym1pdEJ1dHRvblRhcmdldC5kaXNhYmxlZCA9IHRydWVcblxuICAgICAgICAvLyBIaWRlIGFueSBlcnJvcnNcbiAgICAgICAgdGhpcy5oaWRlRXJyb3IoKVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIEhpZGUgbG9hZGluZyBvdmVybGF5XG4gICAgICovXG4gICAgaGlkZUxvYWRlcigpIHtcbiAgICAgICAgY29uc29sZS5sb2coJ1tTdHJpcGVDaGVja291dEZvb3Rlcl0gSGlkaW5nIGxvYWRlcicpXG5cbiAgICAgICAgLy8gSGlkZSBzcGlubmVyIGluIGJ1dHRvblxuICAgICAgICBjb25zdCBidXR0b25Db250ZW50ID0gdGhpcy5zdWJtaXRCdXR0b25UYXJnZXQucXVlcnlTZWxlY3RvcignLmJ1dHRvbi1jb250ZW50JylcbiAgICAgICAgY29uc3QgYnV0dG9uU3Bpbm5lciA9IHRoaXMuc3VibWl0QnV0dG9uVGFyZ2V0LnF1ZXJ5U2VsZWN0b3IoJy5idXR0b24tc3Bpbm5lcicpXG5cbiAgICAgICAgaWYgKGJ1dHRvbkNvbnRlbnQpIGJ1dHRvbkNvbnRlbnQuY2xhc3NMaXN0LnJlbW92ZSgnZC1ub25lJylcbiAgICAgICAgaWYgKGJ1dHRvblNwaW5uZXIpIGJ1dHRvblNwaW5uZXIuY2xhc3NMaXN0LmFkZCgnZC1ub25lJylcblxuICAgICAgICAvLyBIaWRlIGZ1bGwtc2NyZWVuIG92ZXJsYXlcbiAgICAgICAgdGhpcy5sb2FkZXJUYXJnZXQuc3R5bGUuZGlzcGxheSA9ICdub25lJ1xuXG4gICAgICAgIC8vIFJlLWVuYWJsZSBidXR0b24gYmFzZWQgb24gdGVybXNcbiAgICAgICAgdGhpcy51cGRhdGVCdXR0b25TdGF0ZSgpXG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogU2hvdyBlcnJvciBtZXNzYWdlXG4gICAgICovXG4gICAgc2hvd0Vycm9yKG1lc3NhZ2UpIHtcbiAgICAgICAgY29uc29sZS5lcnJvcignW1N0cmlwZUNoZWNrb3V0Rm9vdGVyXSBFcnJvcjonLCBtZXNzYWdlKVxuXG4gICAgICAgIGlmICh0aGlzLmhhc0Vycm9yTWVzc2FnZVRhcmdldCkge1xuICAgICAgICAgICAgdGhpcy5lcnJvck1lc3NhZ2VUYXJnZXQudGV4dENvbnRlbnQgPSBtZXNzYWdlXG4gICAgICAgIH1cblxuICAgICAgICB0aGlzLmVycm9yVGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG5cbiAgICAgICAgLy8gU2Nyb2xsIGVycm9yIGludG8gdmlld1xuICAgICAgICB0aGlzLmVycm9yVGFyZ2V0LnNjcm9sbEludG9WaWV3KHtcbiAgICAgICAgICAgIGJlaGF2aW9yOiAnc21vb3RoJyxcbiAgICAgICAgICAgIGJsb2NrOiAnY2VudGVyJ1xuICAgICAgICB9KVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIEhpZGUgZXJyb3IgbWVzc2FnZVxuICAgICAqL1xuICAgIGhpZGVFcnJvcigpIHtcbiAgICAgICAgdGhpcy5lcnJvclRhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ25vbmUnXG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogU2hvdyBzdWNjZXNzIHN0YXRlIChicmllZmx5IGJlZm9yZSByZWRpcmVjdClcbiAgICAgKi9cbiAgICBzaG93U3VjY2VzcygpIHtcbiAgICAgICAgY29uc29sZS5sb2coJ1tTdHJpcGVDaGVja291dEZvb3Rlcl0gUGF5bWVudCBzdWNjZXNzZnVsJylcblxuICAgICAgICAvLyBVcGRhdGUgYnV0dG9uIHRvIHNob3cgc3VjY2Vzc1xuICAgICAgICBjb25zdCBidXR0b25UZXh0ID0gdGhpcy5zdWJtaXRCdXR0b25UYXJnZXQucXVlcnlTZWxlY3RvcignLmJ1dHRvbi10ZXh0JylcbiAgICAgICAgaWYgKGJ1dHRvblRleHQpIHtcbiAgICAgICAgICAgIGJ1dHRvblRleHQuaW5uZXJIVE1MID0gJzxpIGNsYXNzPVwiZmFzIGZhLWNoZWNrIG1lLTJcIj48L2k+UGF5bWVudCBTdWNjZXNzZnVsJ1xuICAgICAgICB9XG5cbiAgICAgICAgdGhpcy5zdWJtaXRCdXR0b25UYXJnZXQuY2xhc3NMaXN0LnJlbW92ZSgnYnRuLXByaW1hcnknKVxuICAgICAgICB0aGlzLnN1Ym1pdEJ1dHRvblRhcmdldC5jbGFzc0xpc3QuYWRkKCdidG4tc3VjY2VzcycpXG5cbiAgICAgICAgLy8gU3VjY2VzcyBtZXNzYWdlIHdpbGwgYmUgc2hvd24gYnkgcGF5bWVudCBjb250cm9sbGVyXG4gICAgICAgIC8vIFRoaXMgY29udHJvbGxlciBqdXN0IHVwZGF0ZXMgdGhlIGJ1dHRvbiBzdGF0ZVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIENvbnRyb2xsZXIgY2xlYW51cFxuICAgICAqXG4gICAgICogRXZlbnRCdXMgbGlzdGVuZXJzIGFyZSBhdXRvbWF0aWNhbGx5IGNsZWFuZWQgdXAgYnkgd2l0aEV2ZW50QnVzIG1peGluXG4gICAgICovXG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICAgICAgY29uc29sZS5sb2coJ1tTdHJpcGVDaGVja291dEZvb3Rlcl0gRGlzY29ubmVjdGVkJylcblxuICAgICAgICAvLyBNaXhpbiBoYW5kbGVzIEV2ZW50QnVzIGNsZWFudXAgYXV0b21hdGljYWxseVxuICAgICAgICAvLyBObyBtYW51YWwgcmVtb3ZlRXZlbnRMaXN0ZW5lcigpIG5lZWRlZCFcbiAgICB9XG59IiwgIi8qKlxuICogU3RyaXBlIE1vZHVsZSAtIEphdmFTY3JpcHQgRW50cnkgUG9pbnRcbiAqXG4gKiBJbml0aWFsaXplcyBTdGltdWx1cy5qcyBhbmQgcmVnaXN0ZXJzIGFsbCBjb250cm9sbGVyc1xuICovXG5cbmltcG9ydCB7IEFwcGxpY2F0aW9uIH0gZnJvbSBcIkBob3R3aXJlZC9zdGltdWx1c1wiXG5cbi8vIEltcG9ydCBjb250cm9sbGVyc1xuaW1wb3J0IFN0cmlwZU9yZGVyQ29udHJvbGxlciBmcm9tIFwiLi9jb250cm9sbGVycy9zdHJpcGVfb3JkZXJfY29udHJvbGxlclwiXG5pbXBvcnQgT3JkZXJTdWJtaXRDb250cm9sbGVyIGZyb20gXCIuL2NvbnRyb2xsZXJzL29yZGVyX3N1Ym1pdF9jb250cm9sbGVyXCJcbmltcG9ydCBBZ2JWYWxpZGF0aW9uQ29udHJvbGxlciBmcm9tIFwiLi9jb250cm9sbGVycy9hZ2JfdmFsaWRhdGlvbl9jb250cm9sbGVyXCJcbmltcG9ydCBPbmVQYWdlU3RyaXBlQ29udHJvbGxlciBmcm9tIFwiLi9jb250cm9sbGVycy9vbmVwYWdlX3N0cmlwZV9jb250cm9sbGVyXCJcbmltcG9ydCBTdHJpcGVDaGVja291dEZvb3RlckNvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvc3RyaXBlX2NoZWNrb3V0X2Zvb3Rlcl9jb250cm9sbGVyXCJcblxuLy8gU3RhcnQgU3RpbXVsdXMgYXBwbGljYXRpb25cbndpbmRvdy5TdGltdWx1cyA9IEFwcGxpY2F0aW9uLnN0YXJ0KClcblxuLy8gUmVnaXN0ZXIgY29udHJvbGxlcnNcblN0aW11bHVzLnJlZ2lzdGVyKFwic3RyaXBlLW9yZGVyXCIsIFN0cmlwZU9yZGVyQ29udHJvbGxlcilcblN0aW11bHVzLnJlZ2lzdGVyKFwib3JkZXItc3VibWl0XCIsIE9yZGVyU3VibWl0Q29udHJvbGxlcilcblN0aW11bHVzLnJlZ2lzdGVyKFwiYWdiLXZhbGlkYXRpb25cIiwgQWdiVmFsaWRhdGlvbkNvbnRyb2xsZXIpXG5TdGltdWx1cy5yZWdpc3RlcihcIm9uZXBhZ2Utc3RyaXBlXCIsIE9uZVBhZ2VTdHJpcGVDb250cm9sbGVyKVxuU3RpbXVsdXMucmVnaXN0ZXIoXCJzdHJpcGUtY2hlY2tvdXQtZm9vdGVyXCIsIFN0cmlwZUNoZWNrb3V0Rm9vdGVyQ29udHJvbGxlcilcblxuLy8gRGVidWcgbW9kZSBpbiBkZXZlbG9wbWVudFxuaWYgKHByb2Nlc3MuZW52Lk5PREVfRU5WID09PSAnZGV2ZWxvcG1lbnQnKSB7XG4gIFN0aW11bHVzLmRlYnVnID0gdHJ1ZVxuICBjb25zb2xlLmxvZygnU3RyaXBlIE1vZHVsZTogU3RpbXVsdXMgaW5pdGlhbGl6ZWQgd2l0aCBjb250cm9sbGVyczonLCBTdGltdWx1cy5yb3V0ZXIubW9kdWxlc0J5SWRlbnRpZmllcilcbn1cblxuY29uc29sZS5sb2coJ1N0cmlwZSBNb2R1bGU6IEphdmFTY3JpcHQgbG9hZGVkIGFuZCByZWFkeScpXG4iXSwKICAibWFwcGluZ3MiOiAiOzs7Ozs7Ozs7QUFJQSxNQUFNLGdCQUFOLE1BQW9CO0FBQUEsSUFDaEIsWUFBWSxhQUFhLFdBQVcsY0FBYztBQUM5QyxXQUFLLGNBQWM7QUFDbkIsV0FBSyxZQUFZO0FBQ2pCLFdBQUssZUFBZTtBQUNwQixXQUFLLG9CQUFvQixvQkFBSSxJQUFJO0FBQUEsSUFDckM7QUFBQSxJQUNBLFVBQVU7QUFDTixXQUFLLFlBQVksaUJBQWlCLEtBQUssV0FBVyxNQUFNLEtBQUssWUFBWTtBQUFBLElBQzdFO0FBQUEsSUFDQSxhQUFhO0FBQ1QsV0FBSyxZQUFZLG9CQUFvQixLQUFLLFdBQVcsTUFBTSxLQUFLLFlBQVk7QUFBQSxJQUNoRjtBQUFBLElBQ0EsaUJBQWlCLFNBQVM7QUFDdEIsV0FBSyxrQkFBa0IsSUFBSSxPQUFPO0FBQUEsSUFDdEM7QUFBQSxJQUNBLG9CQUFvQixTQUFTO0FBQ3pCLFdBQUssa0JBQWtCLE9BQU8sT0FBTztBQUFBLElBQ3pDO0FBQUEsSUFDQSxZQUFZLE9BQU87QUFDZixZQUFNLGdCQUFnQixZQUFZLEtBQUs7QUFDdkMsaUJBQVcsV0FBVyxLQUFLLFVBQVU7QUFDakMsWUFBSSxjQUFjLDZCQUE2QjtBQUMzQztBQUFBLFFBQ0osT0FDSztBQUNELGtCQUFRLFlBQVksYUFBYTtBQUFBLFFBQ3JDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGNBQWM7QUFDVixhQUFPLEtBQUssa0JBQWtCLE9BQU87QUFBQSxJQUN6QztBQUFBLElBQ0EsSUFBSSxXQUFXO0FBQ1gsYUFBTyxNQUFNLEtBQUssS0FBSyxpQkFBaUIsRUFBRSxLQUFLLENBQUMsTUFBTSxVQUFVO0FBQzVELGNBQU0sWUFBWSxLQUFLLE9BQU8sYUFBYSxNQUFNO0FBQ2pELGVBQU8sWUFBWSxhQUFhLEtBQUssWUFBWSxhQUFhLElBQUk7QUFBQSxNQUN0RSxDQUFDO0FBQUEsSUFDTDtBQUFBLEVBQ0o7QUFDQSxXQUFTLFlBQVksT0FBTztBQUN4QixRQUFJLGlDQUFpQyxPQUFPO0FBQ3hDLGFBQU87QUFBQSxJQUNYLE9BQ0s7QUFDRCxZQUFNLEVBQUUseUJBQXlCLElBQUk7QUFDckMsYUFBTyxPQUFPLE9BQU8sT0FBTztBQUFBLFFBQ3hCLDZCQUE2QjtBQUFBLFFBQzdCLDJCQUEyQjtBQUN2QixlQUFLLDhCQUE4QjtBQUNuQyxtQ0FBeUIsS0FBSyxJQUFJO0FBQUEsUUFDdEM7QUFBQSxNQUNKLENBQUM7QUFBQSxJQUNMO0FBQUEsRUFDSjtBQUVBLE1BQU0sYUFBTixNQUFpQjtBQUFBLElBQ2IsWUFBWSxhQUFhO0FBQ3JCLFdBQUssY0FBYztBQUNuQixXQUFLLG9CQUFvQixvQkFBSSxJQUFJO0FBQ2pDLFdBQUssVUFBVTtBQUFBLElBQ25CO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssU0FBUztBQUNmLGFBQUssVUFBVTtBQUNmLGFBQUssZUFBZSxRQUFRLENBQUMsa0JBQWtCLGNBQWMsUUFBUSxDQUFDO0FBQUEsTUFDMUU7QUFBQSxJQUNKO0FBQUEsSUFDQSxPQUFPO0FBQ0gsVUFBSSxLQUFLLFNBQVM7QUFDZCxhQUFLLFVBQVU7QUFDZixhQUFLLGVBQWUsUUFBUSxDQUFDLGtCQUFrQixjQUFjLFdBQVcsQ0FBQztBQUFBLE1BQzdFO0FBQUEsSUFDSjtBQUFBLElBQ0EsSUFBSSxpQkFBaUI7QUFDakIsYUFBTyxNQUFNLEtBQUssS0FBSyxrQkFBa0IsT0FBTyxDQUFDLEVBQUUsT0FBTyxDQUFDLFdBQVcsUUFBUSxVQUFVLE9BQU8sTUFBTSxLQUFLLElBQUksT0FBTyxDQUFDLENBQUMsR0FBRyxDQUFDLENBQUM7QUFBQSxJQUNoSTtBQUFBLElBQ0EsaUJBQWlCLFNBQVM7QUFDdEIsV0FBSyw2QkFBNkIsT0FBTyxFQUFFLGlCQUFpQixPQUFPO0FBQUEsSUFDdkU7QUFBQSxJQUNBLG9CQUFvQixTQUFTLHNCQUFzQixPQUFPO0FBQ3RELFdBQUssNkJBQTZCLE9BQU8sRUFBRSxvQkFBb0IsT0FBTztBQUN0RSxVQUFJO0FBQ0EsYUFBSyw4QkFBOEIsT0FBTztBQUFBLElBQ2xEO0FBQUEsSUFDQSxZQUFZQSxRQUFPLFNBQVMsU0FBUyxDQUFDLEdBQUc7QUFDckMsV0FBSyxZQUFZLFlBQVlBLFFBQU8sU0FBUyxPQUFPLElBQUksTUFBTTtBQUFBLElBQ2xFO0FBQUEsSUFDQSw4QkFBOEIsU0FBUztBQUNuQyxZQUFNLGdCQUFnQixLQUFLLDZCQUE2QixPQUFPO0FBQy9ELFVBQUksQ0FBQyxjQUFjLFlBQVksR0FBRztBQUM5QixzQkFBYyxXQUFXO0FBQ3pCLGFBQUssNkJBQTZCLE9BQU87QUFBQSxNQUM3QztBQUFBLElBQ0o7QUFBQSxJQUNBLDZCQUE2QixTQUFTO0FBQ2xDLFlBQU0sRUFBRSxhQUFhLFdBQVcsYUFBYSxJQUFJO0FBQ2pELFlBQU0sbUJBQW1CLEtBQUssb0NBQW9DLFdBQVc7QUFDN0UsWUFBTSxXQUFXLEtBQUssU0FBUyxXQUFXLFlBQVk7QUFDdEQsdUJBQWlCLE9BQU8sUUFBUTtBQUNoQyxVQUFJLGlCQUFpQixRQUFRO0FBQ3pCLGFBQUssa0JBQWtCLE9BQU8sV0FBVztBQUFBLElBQ2pEO0FBQUEsSUFDQSw2QkFBNkIsU0FBUztBQUNsQyxZQUFNLEVBQUUsYUFBYSxXQUFXLGFBQWEsSUFBSTtBQUNqRCxhQUFPLEtBQUssbUJBQW1CLGFBQWEsV0FBVyxZQUFZO0FBQUEsSUFDdkU7QUFBQSxJQUNBLG1CQUFtQixhQUFhLFdBQVcsY0FBYztBQUNyRCxZQUFNLG1CQUFtQixLQUFLLG9DQUFvQyxXQUFXO0FBQzdFLFlBQU0sV0FBVyxLQUFLLFNBQVMsV0FBVyxZQUFZO0FBQ3RELFVBQUksZ0JBQWdCLGlCQUFpQixJQUFJLFFBQVE7QUFDakQsVUFBSSxDQUFDLGVBQWU7QUFDaEIsd0JBQWdCLEtBQUssb0JBQW9CLGFBQWEsV0FBVyxZQUFZO0FBQzdFLHlCQUFpQixJQUFJLFVBQVUsYUFBYTtBQUFBLE1BQ2hEO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLG9CQUFvQixhQUFhLFdBQVcsY0FBYztBQUN0RCxZQUFNLGdCQUFnQixJQUFJLGNBQWMsYUFBYSxXQUFXLFlBQVk7QUFDNUUsVUFBSSxLQUFLLFNBQVM7QUFDZCxzQkFBYyxRQUFRO0FBQUEsTUFDMUI7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0Esb0NBQW9DLGFBQWE7QUFDN0MsVUFBSSxtQkFBbUIsS0FBSyxrQkFBa0IsSUFBSSxXQUFXO0FBQzdELFVBQUksQ0FBQyxrQkFBa0I7QUFDbkIsMkJBQW1CLG9CQUFJLElBQUk7QUFDM0IsYUFBSyxrQkFBa0IsSUFBSSxhQUFhLGdCQUFnQjtBQUFBLE1BQzVEO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLFNBQVMsV0FBVyxjQUFjO0FBQzlCLFlBQU0sUUFBUSxDQUFDLFNBQVM7QUFDeEIsYUFBTyxLQUFLLFlBQVksRUFDbkIsS0FBSyxFQUNMLFFBQVEsQ0FBQyxRQUFRO0FBQ2xCLGNBQU0sS0FBSyxHQUFHLGFBQWEsR0FBRyxJQUFJLEtBQUssR0FBRyxHQUFHLEdBQUcsRUFBRTtBQUFBLE1BQ3RELENBQUM7QUFDRCxhQUFPLE1BQU0sS0FBSyxHQUFHO0FBQUEsSUFDekI7QUFBQSxFQUNKO0FBRUEsTUFBTSxpQ0FBaUM7QUFBQSxJQUNuQyxLQUFLLEVBQUUsT0FBTyxNQUFNLEdBQUc7QUFDbkIsVUFBSTtBQUNBLGNBQU0sZ0JBQWdCO0FBQzFCLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxRQUFRLEVBQUUsT0FBTyxNQUFNLEdBQUc7QUFDdEIsVUFBSTtBQUNBLGNBQU0sZUFBZTtBQUN6QixhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsS0FBSyxFQUFFLE9BQU8sT0FBTyxRQUFRLEdBQUc7QUFDNUIsVUFBSSxPQUFPO0FBQ1AsZUFBTyxZQUFZLE1BQU07QUFBQSxNQUM3QixPQUNLO0FBQ0QsZUFBTztBQUFBLE1BQ1g7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUNBLE1BQU0sb0JBQW9CO0FBQzFCLFdBQVMsNEJBQTRCLGtCQUFrQjtBQUNuRCxVQUFNLFNBQVMsaUJBQWlCLEtBQUs7QUFDckMsVUFBTSxVQUFVLE9BQU8sTUFBTSxpQkFBaUIsS0FBSyxDQUFDO0FBQ3BELFFBQUksWUFBWSxRQUFRLENBQUM7QUFDekIsUUFBSSxZQUFZLFFBQVEsQ0FBQztBQUN6QixRQUFJLGFBQWEsQ0FBQyxDQUFDLFdBQVcsU0FBUyxVQUFVLEVBQUUsU0FBUyxTQUFTLEdBQUc7QUFDcEUsbUJBQWEsSUFBSSxTQUFTO0FBQzFCLGtCQUFZO0FBQUEsSUFDaEI7QUFDQSxXQUFPO0FBQUEsTUFDSCxhQUFhLGlCQUFpQixRQUFRLENBQUMsQ0FBQztBQUFBLE1BQ3hDO0FBQUEsTUFDQSxjQUFjLFFBQVEsQ0FBQyxJQUFJLGtCQUFrQixRQUFRLENBQUMsQ0FBQyxJQUFJLENBQUM7QUFBQSxNQUM1RCxZQUFZLFFBQVEsQ0FBQztBQUFBLE1BQ3JCLFlBQVksUUFBUSxDQUFDO0FBQUEsTUFDckIsV0FBVyxRQUFRLENBQUMsS0FBSztBQUFBLElBQzdCO0FBQUEsRUFDSjtBQUNBLFdBQVMsaUJBQWlCLGlCQUFpQjtBQUN2QyxRQUFJLG1CQUFtQixVQUFVO0FBQzdCLGFBQU87QUFBQSxJQUNYLFdBQ1MsbUJBQW1CLFlBQVk7QUFDcEMsYUFBTztBQUFBLElBQ1g7QUFBQSxFQUNKO0FBQ0EsV0FBUyxrQkFBa0IsY0FBYztBQUNyQyxXQUFPLGFBQ0YsTUFBTSxHQUFHLEVBQ1QsT0FBTyxDQUFDLFNBQVMsVUFBVSxPQUFPLE9BQU8sU0FBUyxFQUFFLENBQUMsTUFBTSxRQUFRLE1BQU0sRUFBRSxDQUFDLEdBQUcsQ0FBQyxLQUFLLEtBQUssS0FBSyxFQUFFLENBQUMsR0FBRyxDQUFDLENBQUM7QUFBQSxFQUNoSDtBQUNBLFdBQVMscUJBQXFCLGFBQWE7QUFDdkMsUUFBSSxlQUFlLFFBQVE7QUFDdkIsYUFBTztBQUFBLElBQ1gsV0FDUyxlQUFlLFVBQVU7QUFDOUIsYUFBTztBQUFBLElBQ1g7QUFBQSxFQUNKO0FBRUEsV0FBUyxTQUFTLE9BQU87QUFDckIsV0FBTyxNQUFNLFFBQVEsdUJBQXVCLENBQUMsR0FBRyxTQUFTLEtBQUssWUFBWSxDQUFDO0FBQUEsRUFDL0U7QUFDQSxXQUFTLGtCQUFrQixPQUFPO0FBQzlCLFdBQU8sU0FBUyxNQUFNLFFBQVEsT0FBTyxHQUFHLEVBQUUsUUFBUSxPQUFPLEdBQUcsQ0FBQztBQUFBLEVBQ2pFO0FBQ0EsV0FBUyxXQUFXLE9BQU87QUFDdkIsV0FBTyxNQUFNLE9BQU8sQ0FBQyxFQUFFLFlBQVksSUFBSSxNQUFNLE1BQU0sQ0FBQztBQUFBLEVBQ3hEO0FBQ0EsV0FBUyxVQUFVLE9BQU87QUFDdEIsV0FBTyxNQUFNLFFBQVEsWUFBWSxDQUFDLEdBQUcsU0FBUyxJQUFJLEtBQUssWUFBWSxDQUFDLEVBQUU7QUFBQSxFQUMxRTtBQUNBLFdBQVMsU0FBUyxPQUFPO0FBQ3JCLFdBQU8sTUFBTSxNQUFNLFNBQVMsS0FBSyxDQUFDO0FBQUEsRUFDdEM7QUFFQSxXQUFTLFlBQVksUUFBUTtBQUN6QixXQUFPLFdBQVcsUUFBUSxXQUFXO0FBQUEsRUFDekM7QUFDQSxXQUFTLFlBQVksUUFBUSxVQUFVO0FBQ25DLFdBQU8sT0FBTyxVQUFVLGVBQWUsS0FBSyxRQUFRLFFBQVE7QUFBQSxFQUNoRTtBQUVBLE1BQU0sZUFBZSxDQUFDLFFBQVEsUUFBUSxPQUFPLE9BQU87QUFDcEQsTUFBTSxTQUFOLE1BQWE7QUFBQSxJQUNULFlBQVksU0FBUyxPQUFPLFlBQVksUUFBUTtBQUM1QyxXQUFLLFVBQVU7QUFDZixXQUFLLFFBQVE7QUFDYixXQUFLLGNBQWMsV0FBVyxlQUFlO0FBQzdDLFdBQUssWUFBWSxXQUFXLGFBQWEsOEJBQThCLE9BQU8sS0FBSyxNQUFNLG9CQUFvQjtBQUM3RyxXQUFLLGVBQWUsV0FBVyxnQkFBZ0IsQ0FBQztBQUNoRCxXQUFLLGFBQWEsV0FBVyxjQUFjLE1BQU0sb0JBQW9CO0FBQ3JFLFdBQUssYUFBYSxXQUFXLGNBQWMsTUFBTSxxQkFBcUI7QUFDdEUsV0FBSyxZQUFZLFdBQVcsYUFBYTtBQUN6QyxXQUFLLFNBQVM7QUFBQSxJQUNsQjtBQUFBLElBQ0EsT0FBTyxTQUFTLE9BQU8sUUFBUTtBQUMzQixhQUFPLElBQUksS0FBSyxNQUFNLFNBQVMsTUFBTSxPQUFPLDRCQUE0QixNQUFNLE9BQU8sR0FBRyxNQUFNO0FBQUEsSUFDbEc7QUFBQSxJQUNBLFdBQVc7QUFDUCxZQUFNLGNBQWMsS0FBSyxZQUFZLElBQUksS0FBSyxTQUFTLEtBQUs7QUFDNUQsWUFBTSxjQUFjLEtBQUssa0JBQWtCLElBQUksS0FBSyxlQUFlLEtBQUs7QUFDeEUsYUFBTyxHQUFHLEtBQUssU0FBUyxHQUFHLFdBQVcsR0FBRyxXQUFXLEtBQUssS0FBSyxVQUFVLElBQUksS0FBSyxVQUFVO0FBQUEsSUFDL0Y7QUFBQSxJQUNBLDBCQUEwQixPQUFPO0FBQzdCLFVBQUksQ0FBQyxLQUFLLFdBQVc7QUFDakIsZUFBTztBQUFBLE1BQ1g7QUFDQSxZQUFNLFVBQVUsS0FBSyxVQUFVLE1BQU0sR0FBRztBQUN4QyxVQUFJLEtBQUssc0JBQXNCLE9BQU8sT0FBTyxHQUFHO0FBQzVDLGVBQU87QUFBQSxNQUNYO0FBQ0EsWUFBTSxpQkFBaUIsUUFBUSxPQUFPLENBQUMsUUFBUSxDQUFDLGFBQWEsU0FBUyxHQUFHLENBQUMsRUFBRSxDQUFDO0FBQzdFLFVBQUksQ0FBQyxnQkFBZ0I7QUFDakIsZUFBTztBQUFBLE1BQ1g7QUFDQSxVQUFJLENBQUMsWUFBWSxLQUFLLGFBQWEsY0FBYyxHQUFHO0FBQ2hELGNBQU0sZ0NBQWdDLEtBQUssU0FBUyxFQUFFO0FBQUEsTUFDMUQ7QUFDQSxhQUFPLEtBQUssWUFBWSxjQUFjLEVBQUUsWUFBWSxNQUFNLE1BQU0sSUFBSSxZQUFZO0FBQUEsSUFDcEY7QUFBQSxJQUNBLHVCQUF1QixPQUFPO0FBQzFCLFVBQUksQ0FBQyxLQUFLLFdBQVc7QUFDakIsZUFBTztBQUFBLE1BQ1g7QUFDQSxZQUFNLFVBQVUsQ0FBQyxLQUFLLFNBQVM7QUFDL0IsVUFBSSxLQUFLLHNCQUFzQixPQUFPLE9BQU8sR0FBRztBQUM1QyxlQUFPO0FBQUEsTUFDWDtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxZQUFNLFNBQVMsQ0FBQztBQUNoQixZQUFNLFVBQVUsSUFBSSxPQUFPLFNBQVMsS0FBSyxVQUFVLGdCQUFnQixHQUFHO0FBQ3RFLGlCQUFXLEVBQUUsTUFBTSxNQUFNLEtBQUssTUFBTSxLQUFLLEtBQUssUUFBUSxVQUFVLEdBQUc7QUFDL0QsY0FBTSxRQUFRLEtBQUssTUFBTSxPQUFPO0FBQ2hDLGNBQU0sTUFBTSxTQUFTLE1BQU0sQ0FBQztBQUM1QixZQUFJLEtBQUs7QUFDTCxpQkFBTyxTQUFTLEdBQUcsQ0FBQyxJQUFJLFNBQVMsS0FBSztBQUFBLFFBQzFDO0FBQUEsTUFDSjtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxJQUFJLGtCQUFrQjtBQUNsQixhQUFPLHFCQUFxQixLQUFLLFdBQVc7QUFBQSxJQUNoRDtBQUFBLElBQ0EsSUFBSSxjQUFjO0FBQ2QsYUFBTyxLQUFLLE9BQU87QUFBQSxJQUN2QjtBQUFBLElBQ0Esc0JBQXNCLE9BQU8sU0FBUztBQUNsQyxZQUFNLENBQUMsTUFBTSxNQUFNLEtBQUssS0FBSyxJQUFJLGFBQWEsSUFBSSxDQUFDLGFBQWEsUUFBUSxTQUFTLFFBQVEsQ0FBQztBQUMxRixhQUFPLE1BQU0sWUFBWSxRQUFRLE1BQU0sWUFBWSxRQUFRLE1BQU0sV0FBVyxPQUFPLE1BQU0sYUFBYTtBQUFBLElBQzFHO0FBQUEsRUFDSjtBQUNBLE1BQU0sb0JBQW9CO0FBQUEsSUFDdEIsR0FBRyxNQUFNO0FBQUEsSUFDVCxRQUFRLE1BQU07QUFBQSxJQUNkLE1BQU0sTUFBTTtBQUFBLElBQ1osU0FBUyxNQUFNO0FBQUEsSUFDZixPQUFPLENBQUMsTUFBTyxFQUFFLGFBQWEsTUFBTSxLQUFLLFdBQVcsVUFBVTtBQUFBLElBQzlELFFBQVEsTUFBTTtBQUFBLElBQ2QsVUFBVSxNQUFNO0FBQUEsRUFDcEI7QUFDQSxXQUFTLDhCQUE4QixTQUFTO0FBQzVDLFVBQU0sVUFBVSxRQUFRLFFBQVEsWUFBWTtBQUM1QyxRQUFJLFdBQVcsbUJBQW1CO0FBQzlCLGFBQU8sa0JBQWtCLE9BQU8sRUFBRSxPQUFPO0FBQUEsSUFDN0M7QUFBQSxFQUNKO0FBQ0EsV0FBUyxNQUFNLFNBQVM7QUFDcEIsVUFBTSxJQUFJLE1BQU0sT0FBTztBQUFBLEVBQzNCO0FBQ0EsV0FBUyxTQUFTLE9BQU87QUFDckIsUUFBSTtBQUNBLGFBQU8sS0FBSyxNQUFNLEtBQUs7QUFBQSxJQUMzQixTQUNPLEtBQUs7QUFDUixhQUFPO0FBQUEsSUFDWDtBQUFBLEVBQ0o7QUFFQSxNQUFNLFVBQU4sTUFBYztBQUFBLElBQ1YsWUFBWSxTQUFTLFFBQVE7QUFDekIsV0FBSyxVQUFVO0FBQ2YsV0FBSyxTQUFTO0FBQUEsSUFDbEI7QUFBQSxJQUNBLElBQUksUUFBUTtBQUNSLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksY0FBYztBQUNkLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksZUFBZTtBQUNmLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLFlBQVksT0FBTztBQUNmLFlBQU0sY0FBYyxLQUFLLG1CQUFtQixLQUFLO0FBQ2pELFVBQUksS0FBSyxxQkFBcUIsS0FBSyxLQUFLLEtBQUssb0JBQW9CLFdBQVcsR0FBRztBQUMzRSxhQUFLLGdCQUFnQixXQUFXO0FBQUEsTUFDcEM7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLFlBQVk7QUFDWixhQUFPLEtBQUssT0FBTztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxZQUFNLFNBQVMsS0FBSyxXQUFXLEtBQUssVUFBVTtBQUM5QyxVQUFJLE9BQU8sVUFBVSxZQUFZO0FBQzdCLGVBQU87QUFBQSxNQUNYO0FBQ0EsWUFBTSxJQUFJLE1BQU0sV0FBVyxLQUFLLE1BQU0sa0NBQWtDLEtBQUssVUFBVSxHQUFHO0FBQUEsSUFDOUY7QUFBQSxJQUNBLG9CQUFvQixPQUFPO0FBQ3ZCLFlBQU0sRUFBRSxRQUFRLElBQUksS0FBSztBQUN6QixZQUFNLEVBQUUsd0JBQXdCLElBQUksS0FBSyxRQUFRO0FBQ2pELFlBQU0sRUFBRSxXQUFXLElBQUksS0FBSztBQUM1QixVQUFJLFNBQVM7QUFDYixpQkFBVyxDQUFDLE1BQU0sS0FBSyxLQUFLLE9BQU8sUUFBUSxLQUFLLFlBQVksR0FBRztBQUMzRCxZQUFJLFFBQVEseUJBQXlCO0FBQ2pDLGdCQUFNLFNBQVMsd0JBQXdCLElBQUk7QUFDM0MsbUJBQVMsVUFBVSxPQUFPLEVBQUUsTUFBTSxPQUFPLE9BQU8sU0FBUyxXQUFXLENBQUM7QUFBQSxRQUN6RSxPQUNLO0FBQ0Q7QUFBQSxRQUNKO0FBQUEsTUFDSjtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxtQkFBbUIsT0FBTztBQUN0QixhQUFPLE9BQU8sT0FBTyxPQUFPLEVBQUUsUUFBUSxLQUFLLE9BQU8sT0FBTyxDQUFDO0FBQUEsSUFDOUQ7QUFBQSxJQUNBLGdCQUFnQixPQUFPO0FBQ25CLFlBQU0sRUFBRSxRQUFRLGNBQWMsSUFBSTtBQUNsQyxVQUFJO0FBQ0EsYUFBSyxPQUFPLEtBQUssS0FBSyxZQUFZLEtBQUs7QUFDdkMsYUFBSyxRQUFRLGlCQUFpQixLQUFLLFlBQVksRUFBRSxPQUFPLFFBQVEsZUFBZSxRQUFRLEtBQUssV0FBVyxDQUFDO0FBQUEsTUFDNUcsU0FDT0EsUUFBTztBQUNWLGNBQU0sRUFBRSxZQUFZLFlBQVksU0FBUyxNQUFNLElBQUk7QUFDbkQsY0FBTSxTQUFTLEVBQUUsWUFBWSxZQUFZLFNBQVMsT0FBTyxNQUFNO0FBQy9ELGFBQUssUUFBUSxZQUFZQSxRQUFPLG9CQUFvQixLQUFLLE1BQU0sS0FBSyxNQUFNO0FBQUEsTUFDOUU7QUFBQSxJQUNKO0FBQUEsSUFDQSxxQkFBcUIsT0FBTztBQUN4QixZQUFNLGNBQWMsTUFBTTtBQUMxQixVQUFJLGlCQUFpQixpQkFBaUIsS0FBSyxPQUFPLDBCQUEwQixLQUFLLEdBQUc7QUFDaEYsZUFBTztBQUFBLE1BQ1g7QUFDQSxVQUFJLGlCQUFpQixjQUFjLEtBQUssT0FBTyx1QkFBdUIsS0FBSyxHQUFHO0FBQzFFLGVBQU87QUFBQSxNQUNYO0FBQ0EsVUFBSSxLQUFLLFlBQVksYUFBYTtBQUM5QixlQUFPO0FBQUEsTUFDWCxXQUNTLHVCQUF1QixXQUFXLEtBQUssUUFBUSxTQUFTLFdBQVcsR0FBRztBQUMzRSxlQUFPLEtBQUssTUFBTSxnQkFBZ0IsV0FBVztBQUFBLE1BQ2pELE9BQ0s7QUFDRCxlQUFPLEtBQUssTUFBTSxnQkFBZ0IsS0FBSyxPQUFPLE9BQU87QUFBQSxNQUN6RDtBQUFBLElBQ0o7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksUUFBUTtBQUNSLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxFQUNKO0FBRUEsTUFBTSxrQkFBTixNQUFzQjtBQUFBLElBQ2xCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssdUJBQXVCLEVBQUUsWUFBWSxNQUFNLFdBQVcsTUFBTSxTQUFTLEtBQUs7QUFDL0UsV0FBSyxVQUFVO0FBQ2YsV0FBSyxVQUFVO0FBQ2YsV0FBSyxXQUFXO0FBQ2hCLFdBQUssV0FBVyxvQkFBSSxJQUFJO0FBQ3hCLFdBQUssbUJBQW1CLElBQUksaUJBQWlCLENBQUMsY0FBYyxLQUFLLGlCQUFpQixTQUFTLENBQUM7QUFBQSxJQUNoRztBQUFBLElBQ0EsUUFBUTtBQUNKLFVBQUksQ0FBQyxLQUFLLFNBQVM7QUFDZixhQUFLLFVBQVU7QUFDZixhQUFLLGlCQUFpQixRQUFRLEtBQUssU0FBUyxLQUFLLG9CQUFvQjtBQUNyRSxhQUFLLFFBQVE7QUFBQSxNQUNqQjtBQUFBLElBQ0o7QUFBQSxJQUNBLE1BQU0sVUFBVTtBQUNaLFVBQUksS0FBSyxTQUFTO0FBQ2QsYUFBSyxpQkFBaUIsV0FBVztBQUNqQyxhQUFLLFVBQVU7QUFBQSxNQUNuQjtBQUNBLGVBQVM7QUFDVCxVQUFJLENBQUMsS0FBSyxTQUFTO0FBQ2YsYUFBSyxpQkFBaUIsUUFBUSxLQUFLLFNBQVMsS0FBSyxvQkFBb0I7QUFDckUsYUFBSyxVQUFVO0FBQUEsTUFDbkI7QUFBQSxJQUNKO0FBQUEsSUFDQSxPQUFPO0FBQ0gsVUFBSSxLQUFLLFNBQVM7QUFDZCxhQUFLLGlCQUFpQixZQUFZO0FBQ2xDLGFBQUssaUJBQWlCLFdBQVc7QUFDakMsYUFBSyxVQUFVO0FBQUEsTUFDbkI7QUFBQSxJQUNKO0FBQUEsSUFDQSxVQUFVO0FBQ04sVUFBSSxLQUFLLFNBQVM7QUFDZCxjQUFNLFVBQVUsSUFBSSxJQUFJLEtBQUssb0JBQW9CLENBQUM7QUFDbEQsbUJBQVcsV0FBVyxNQUFNLEtBQUssS0FBSyxRQUFRLEdBQUc7QUFDN0MsY0FBSSxDQUFDLFFBQVEsSUFBSSxPQUFPLEdBQUc7QUFDdkIsaUJBQUssY0FBYyxPQUFPO0FBQUEsVUFDOUI7QUFBQSxRQUNKO0FBQ0EsbUJBQVcsV0FBVyxNQUFNLEtBQUssT0FBTyxHQUFHO0FBQ3ZDLGVBQUssV0FBVyxPQUFPO0FBQUEsUUFDM0I7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsaUJBQWlCLFdBQVc7QUFDeEIsVUFBSSxLQUFLLFNBQVM7QUFDZCxtQkFBVyxZQUFZLFdBQVc7QUFDOUIsZUFBSyxnQkFBZ0IsUUFBUTtBQUFBLFFBQ2pDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGdCQUFnQixVQUFVO0FBQ3RCLFVBQUksU0FBUyxRQUFRLGNBQWM7QUFDL0IsYUFBSyx1QkFBdUIsU0FBUyxRQUFRLFNBQVMsYUFBYTtBQUFBLE1BQ3ZFLFdBQ1MsU0FBUyxRQUFRLGFBQWE7QUFDbkMsYUFBSyxvQkFBb0IsU0FBUyxZQUFZO0FBQzlDLGFBQUssa0JBQWtCLFNBQVMsVUFBVTtBQUFBLE1BQzlDO0FBQUEsSUFDSjtBQUFBLElBQ0EsdUJBQXVCLFNBQVMsZUFBZTtBQUMzQyxVQUFJLEtBQUssU0FBUyxJQUFJLE9BQU8sR0FBRztBQUM1QixZQUFJLEtBQUssU0FBUywyQkFBMkIsS0FBSyxhQUFhLE9BQU8sR0FBRztBQUNyRSxlQUFLLFNBQVMsd0JBQXdCLFNBQVMsYUFBYTtBQUFBLFFBQ2hFLE9BQ0s7QUFDRCxlQUFLLGNBQWMsT0FBTztBQUFBLFFBQzlCO0FBQUEsTUFDSixXQUNTLEtBQUssYUFBYSxPQUFPLEdBQUc7QUFDakMsYUFBSyxXQUFXLE9BQU87QUFBQSxNQUMzQjtBQUFBLElBQ0o7QUFBQSxJQUNBLG9CQUFvQixPQUFPO0FBQ3ZCLGlCQUFXLFFBQVEsTUFBTSxLQUFLLEtBQUssR0FBRztBQUNsQyxjQUFNLFVBQVUsS0FBSyxnQkFBZ0IsSUFBSTtBQUN6QyxZQUFJLFNBQVM7QUFDVCxlQUFLLFlBQVksU0FBUyxLQUFLLGFBQWE7QUFBQSxRQUNoRDtBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxrQkFBa0IsT0FBTztBQUNyQixpQkFBVyxRQUFRLE1BQU0sS0FBSyxLQUFLLEdBQUc7QUFDbEMsY0FBTSxVQUFVLEtBQUssZ0JBQWdCLElBQUk7QUFDekMsWUFBSSxXQUFXLEtBQUssZ0JBQWdCLE9BQU8sR0FBRztBQUMxQyxlQUFLLFlBQVksU0FBUyxLQUFLLFVBQVU7QUFBQSxRQUM3QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxhQUFhLFNBQVM7QUFDbEIsYUFBTyxLQUFLLFNBQVMsYUFBYSxPQUFPO0FBQUEsSUFDN0M7QUFBQSxJQUNBLG9CQUFvQixPQUFPLEtBQUssU0FBUztBQUNyQyxhQUFPLEtBQUssU0FBUyxvQkFBb0IsSUFBSTtBQUFBLElBQ2pEO0FBQUEsSUFDQSxZQUFZLE1BQU0sV0FBVztBQUN6QixpQkFBVyxXQUFXLEtBQUssb0JBQW9CLElBQUksR0FBRztBQUNsRCxrQkFBVSxLQUFLLE1BQU0sT0FBTztBQUFBLE1BQ2hDO0FBQUEsSUFDSjtBQUFBLElBQ0EsZ0JBQWdCLE1BQU07QUFDbEIsVUFBSSxLQUFLLFlBQVksS0FBSyxjQUFjO0FBQ3BDLGVBQU87QUFBQSxNQUNYO0FBQUEsSUFDSjtBQUFBLElBQ0EsZ0JBQWdCLFNBQVM7QUFDckIsVUFBSSxRQUFRLGVBQWUsS0FBSyxRQUFRLGFBQWE7QUFDakQsZUFBTztBQUFBLE1BQ1gsT0FDSztBQUNELGVBQU8sS0FBSyxRQUFRLFNBQVMsT0FBTztBQUFBLE1BQ3hDO0FBQUEsSUFDSjtBQUFBLElBQ0EsV0FBVyxTQUFTO0FBQ2hCLFVBQUksQ0FBQyxLQUFLLFNBQVMsSUFBSSxPQUFPLEdBQUc7QUFDN0IsWUFBSSxLQUFLLGdCQUFnQixPQUFPLEdBQUc7QUFDL0IsZUFBSyxTQUFTLElBQUksT0FBTztBQUN6QixjQUFJLEtBQUssU0FBUyxnQkFBZ0I7QUFDOUIsaUJBQUssU0FBUyxlQUFlLE9BQU87QUFBQSxVQUN4QztBQUFBLFFBQ0o7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsY0FBYyxTQUFTO0FBQ25CLFVBQUksS0FBSyxTQUFTLElBQUksT0FBTyxHQUFHO0FBQzVCLGFBQUssU0FBUyxPQUFPLE9BQU87QUFDNUIsWUFBSSxLQUFLLFNBQVMsa0JBQWtCO0FBQ2hDLGVBQUssU0FBUyxpQkFBaUIsT0FBTztBQUFBLFFBQzFDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxFQUNKO0FBRUEsTUFBTSxvQkFBTixNQUF3QjtBQUFBLElBQ3BCLFlBQVksU0FBUyxlQUFlLFVBQVU7QUFDMUMsV0FBSyxnQkFBZ0I7QUFDckIsV0FBSyxXQUFXO0FBQ2hCLFdBQUssa0JBQWtCLElBQUksZ0JBQWdCLFNBQVMsSUFBSTtBQUFBLElBQzVEO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssZ0JBQWdCO0FBQUEsSUFDaEM7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sSUFBSSxLQUFLLGFBQWE7QUFBQSxJQUNqQztBQUFBLElBQ0EsUUFBUTtBQUNKLFdBQUssZ0JBQWdCLE1BQU07QUFBQSxJQUMvQjtBQUFBLElBQ0EsTUFBTSxVQUFVO0FBQ1osV0FBSyxnQkFBZ0IsTUFBTSxRQUFRO0FBQUEsSUFDdkM7QUFBQSxJQUNBLE9BQU87QUFDSCxXQUFLLGdCQUFnQixLQUFLO0FBQUEsSUFDOUI7QUFBQSxJQUNBLFVBQVU7QUFDTixXQUFLLGdCQUFnQixRQUFRO0FBQUEsSUFDakM7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxnQkFBZ0I7QUFBQSxJQUNoQztBQUFBLElBQ0EsYUFBYSxTQUFTO0FBQ2xCLGFBQU8sUUFBUSxhQUFhLEtBQUssYUFBYTtBQUFBLElBQ2xEO0FBQUEsSUFDQSxvQkFBb0IsTUFBTTtBQUN0QixZQUFNLFFBQVEsS0FBSyxhQUFhLElBQUksSUFBSSxDQUFDLElBQUksSUFBSSxDQUFDO0FBQ2xELFlBQU0sVUFBVSxNQUFNLEtBQUssS0FBSyxpQkFBaUIsS0FBSyxRQUFRLENBQUM7QUFDL0QsYUFBTyxNQUFNLE9BQU8sT0FBTztBQUFBLElBQy9CO0FBQUEsSUFDQSxlQUFlLFNBQVM7QUFDcEIsVUFBSSxLQUFLLFNBQVMseUJBQXlCO0FBQ3ZDLGFBQUssU0FBUyx3QkFBd0IsU0FBUyxLQUFLLGFBQWE7QUFBQSxNQUNyRTtBQUFBLElBQ0o7QUFBQSxJQUNBLGlCQUFpQixTQUFTO0FBQ3RCLFVBQUksS0FBSyxTQUFTLDJCQUEyQjtBQUN6QyxhQUFLLFNBQVMsMEJBQTBCLFNBQVMsS0FBSyxhQUFhO0FBQUEsTUFDdkU7QUFBQSxJQUNKO0FBQUEsSUFDQSx3QkFBd0IsU0FBUyxlQUFlO0FBQzVDLFVBQUksS0FBSyxTQUFTLGdDQUFnQyxLQUFLLGlCQUFpQixlQUFlO0FBQ25GLGFBQUssU0FBUyw2QkFBNkIsU0FBUyxhQUFhO0FBQUEsTUFDckU7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLFdBQVMsSUFBSSxLQUFLLEtBQUssT0FBTztBQUMxQixJQUFBQyxPQUFNLEtBQUssR0FBRyxFQUFFLElBQUksS0FBSztBQUFBLEVBQzdCO0FBQ0EsV0FBUyxJQUFJLEtBQUssS0FBSyxPQUFPO0FBQzFCLElBQUFBLE9BQU0sS0FBSyxHQUFHLEVBQUUsT0FBTyxLQUFLO0FBQzVCLFVBQU0sS0FBSyxHQUFHO0FBQUEsRUFDbEI7QUFDQSxXQUFTQSxPQUFNLEtBQUssS0FBSztBQUNyQixRQUFJLFNBQVMsSUFBSSxJQUFJLEdBQUc7QUFDeEIsUUFBSSxDQUFDLFFBQVE7QUFDVCxlQUFTLG9CQUFJLElBQUk7QUFDakIsVUFBSSxJQUFJLEtBQUssTUFBTTtBQUFBLElBQ3ZCO0FBQ0EsV0FBTztBQUFBLEVBQ1g7QUFDQSxXQUFTLE1BQU0sS0FBSyxLQUFLO0FBQ3JCLFVBQU0sU0FBUyxJQUFJLElBQUksR0FBRztBQUMxQixRQUFJLFVBQVUsUUFBUSxPQUFPLFFBQVEsR0FBRztBQUNwQyxVQUFJLE9BQU8sR0FBRztBQUFBLElBQ2xCO0FBQUEsRUFDSjtBQUVBLE1BQU0sV0FBTixNQUFlO0FBQUEsSUFDWCxjQUFjO0FBQ1YsV0FBSyxjQUFjLG9CQUFJLElBQUk7QUFBQSxJQUMvQjtBQUFBLElBQ0EsSUFBSSxPQUFPO0FBQ1AsYUFBTyxNQUFNLEtBQUssS0FBSyxZQUFZLEtBQUssQ0FBQztBQUFBLElBQzdDO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxZQUFNLE9BQU8sTUFBTSxLQUFLLEtBQUssWUFBWSxPQUFPLENBQUM7QUFDakQsYUFBTyxLQUFLLE9BQU8sQ0FBQyxRQUFRLFFBQVEsT0FBTyxPQUFPLE1BQU0sS0FBSyxHQUFHLENBQUMsR0FBRyxDQUFDLENBQUM7QUFBQSxJQUMxRTtBQUFBLElBQ0EsSUFBSSxPQUFPO0FBQ1AsWUFBTSxPQUFPLE1BQU0sS0FBSyxLQUFLLFlBQVksT0FBTyxDQUFDO0FBQ2pELGFBQU8sS0FBSyxPQUFPLENBQUMsTUFBTSxRQUFRLE9BQU8sSUFBSSxNQUFNLENBQUM7QUFBQSxJQUN4RDtBQUFBLElBQ0EsSUFBSSxLQUFLLE9BQU87QUFDWixVQUFJLEtBQUssYUFBYSxLQUFLLEtBQUs7QUFBQSxJQUNwQztBQUFBLElBQ0EsT0FBTyxLQUFLLE9BQU87QUFDZixVQUFJLEtBQUssYUFBYSxLQUFLLEtBQUs7QUFBQSxJQUNwQztBQUFBLElBQ0EsSUFBSSxLQUFLLE9BQU87QUFDWixZQUFNLFNBQVMsS0FBSyxZQUFZLElBQUksR0FBRztBQUN2QyxhQUFPLFVBQVUsUUFBUSxPQUFPLElBQUksS0FBSztBQUFBLElBQzdDO0FBQUEsSUFDQSxPQUFPLEtBQUs7QUFDUixhQUFPLEtBQUssWUFBWSxJQUFJLEdBQUc7QUFBQSxJQUNuQztBQUFBLElBQ0EsU0FBUyxPQUFPO0FBQ1osWUFBTSxPQUFPLE1BQU0sS0FBSyxLQUFLLFlBQVksT0FBTyxDQUFDO0FBQ2pELGFBQU8sS0FBSyxLQUFLLENBQUMsUUFBUSxJQUFJLElBQUksS0FBSyxDQUFDO0FBQUEsSUFDNUM7QUFBQSxJQUNBLGdCQUFnQixLQUFLO0FBQ2pCLFlBQU0sU0FBUyxLQUFLLFlBQVksSUFBSSxHQUFHO0FBQ3ZDLGFBQU8sU0FBUyxNQUFNLEtBQUssTUFBTSxJQUFJLENBQUM7QUFBQSxJQUMxQztBQUFBLElBQ0EsZ0JBQWdCLE9BQU87QUFDbkIsYUFBTyxNQUFNLEtBQUssS0FBSyxXQUFXLEVBQzdCLE9BQU8sQ0FBQyxDQUFDLE1BQU0sTUFBTSxNQUFNLE9BQU8sSUFBSSxLQUFLLENBQUMsRUFDNUMsSUFBSSxDQUFDLENBQUMsS0FBSyxPQUFPLE1BQU0sR0FBRztBQUFBLElBQ3BDO0FBQUEsRUFDSjtBQTJCQSxNQUFNLG1CQUFOLE1BQXVCO0FBQUEsSUFDbkIsWUFBWSxTQUFTLFVBQVUsVUFBVSxTQUFTO0FBQzlDLFdBQUssWUFBWTtBQUNqQixXQUFLLFVBQVU7QUFDZixXQUFLLGtCQUFrQixJQUFJLGdCQUFnQixTQUFTLElBQUk7QUFDeEQsV0FBSyxXQUFXO0FBQ2hCLFdBQUssbUJBQW1CLElBQUksU0FBUztBQUFBLElBQ3pDO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssZ0JBQWdCO0FBQUEsSUFDaEM7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sS0FBSztBQUFBLElBQ2hCO0FBQUEsSUFDQSxJQUFJLFNBQVMsVUFBVTtBQUNuQixXQUFLLFlBQVk7QUFDakIsV0FBSyxRQUFRO0FBQUEsSUFDakI7QUFBQSxJQUNBLFFBQVE7QUFDSixXQUFLLGdCQUFnQixNQUFNO0FBQUEsSUFDL0I7QUFBQSxJQUNBLE1BQU0sVUFBVTtBQUNaLFdBQUssZ0JBQWdCLE1BQU0sUUFBUTtBQUFBLElBQ3ZDO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxnQkFBZ0IsS0FBSztBQUFBLElBQzlCO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxnQkFBZ0IsUUFBUTtBQUFBLElBQ2pDO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssZ0JBQWdCO0FBQUEsSUFDaEM7QUFBQSxJQUNBLGFBQWEsU0FBUztBQUNsQixZQUFNLEVBQUUsU0FBUyxJQUFJO0FBQ3JCLFVBQUksVUFBVTtBQUNWLGNBQU0sVUFBVSxRQUFRLFFBQVEsUUFBUTtBQUN4QyxZQUFJLEtBQUssU0FBUyxzQkFBc0I7QUFDcEMsaUJBQU8sV0FBVyxLQUFLLFNBQVMscUJBQXFCLFNBQVMsS0FBSyxPQUFPO0FBQUEsUUFDOUU7QUFDQSxlQUFPO0FBQUEsTUFDWCxPQUNLO0FBQ0QsZUFBTztBQUFBLE1BQ1g7QUFBQSxJQUNKO0FBQUEsSUFDQSxvQkFBb0IsTUFBTTtBQUN0QixZQUFNLEVBQUUsU0FBUyxJQUFJO0FBQ3JCLFVBQUksVUFBVTtBQUNWLGNBQU0sUUFBUSxLQUFLLGFBQWEsSUFBSSxJQUFJLENBQUMsSUFBSSxJQUFJLENBQUM7QUFDbEQsY0FBTSxVQUFVLE1BQU0sS0FBSyxLQUFLLGlCQUFpQixRQUFRLENBQUMsRUFBRSxPQUFPLENBQUNDLFdBQVUsS0FBSyxhQUFhQSxNQUFLLENBQUM7QUFDdEcsZUFBTyxNQUFNLE9BQU8sT0FBTztBQUFBLE1BQy9CLE9BQ0s7QUFDRCxlQUFPLENBQUM7QUFBQSxNQUNaO0FBQUEsSUFDSjtBQUFBLElBQ0EsZUFBZSxTQUFTO0FBQ3BCLFlBQU0sRUFBRSxTQUFTLElBQUk7QUFDckIsVUFBSSxVQUFVO0FBQ1YsYUFBSyxnQkFBZ0IsU0FBUyxRQUFRO0FBQUEsTUFDMUM7QUFBQSxJQUNKO0FBQUEsSUFDQSxpQkFBaUIsU0FBUztBQUN0QixZQUFNLFlBQVksS0FBSyxpQkFBaUIsZ0JBQWdCLE9BQU87QUFDL0QsaUJBQVcsWUFBWSxXQUFXO0FBQzlCLGFBQUssa0JBQWtCLFNBQVMsUUFBUTtBQUFBLE1BQzVDO0FBQUEsSUFDSjtBQUFBLElBQ0Esd0JBQXdCLFNBQVMsZ0JBQWdCO0FBQzdDLFlBQU0sRUFBRSxTQUFTLElBQUk7QUFDckIsVUFBSSxVQUFVO0FBQ1YsY0FBTSxVQUFVLEtBQUssYUFBYSxPQUFPO0FBQ3pDLGNBQU0sZ0JBQWdCLEtBQUssaUJBQWlCLElBQUksVUFBVSxPQUFPO0FBQ2pFLFlBQUksV0FBVyxDQUFDLGVBQWU7QUFDM0IsZUFBSyxnQkFBZ0IsU0FBUyxRQUFRO0FBQUEsUUFDMUMsV0FDUyxDQUFDLFdBQVcsZUFBZTtBQUNoQyxlQUFLLGtCQUFrQixTQUFTLFFBQVE7QUFBQSxRQUM1QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxnQkFBZ0IsU0FBUyxVQUFVO0FBQy9CLFdBQUssU0FBUyxnQkFBZ0IsU0FBUyxVQUFVLEtBQUssT0FBTztBQUM3RCxXQUFLLGlCQUFpQixJQUFJLFVBQVUsT0FBTztBQUFBLElBQy9DO0FBQUEsSUFDQSxrQkFBa0IsU0FBUyxVQUFVO0FBQ2pDLFdBQUssU0FBUyxrQkFBa0IsU0FBUyxVQUFVLEtBQUssT0FBTztBQUMvRCxXQUFLLGlCQUFpQixPQUFPLFVBQVUsT0FBTztBQUFBLElBQ2xEO0FBQUEsRUFDSjtBQUVBLE1BQU0sb0JBQU4sTUFBd0I7QUFBQSxJQUNwQixZQUFZLFNBQVMsVUFBVTtBQUMzQixXQUFLLFVBQVU7QUFDZixXQUFLLFdBQVc7QUFDaEIsV0FBSyxVQUFVO0FBQ2YsV0FBSyxZQUFZLG9CQUFJLElBQUk7QUFDekIsV0FBSyxtQkFBbUIsSUFBSSxpQkFBaUIsQ0FBQyxjQUFjLEtBQUssaUJBQWlCLFNBQVMsQ0FBQztBQUFBLElBQ2hHO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssU0FBUztBQUNmLGFBQUssVUFBVTtBQUNmLGFBQUssaUJBQWlCLFFBQVEsS0FBSyxTQUFTLEVBQUUsWUFBWSxNQUFNLG1CQUFtQixLQUFLLENBQUM7QUFDekYsYUFBSyxRQUFRO0FBQUEsTUFDakI7QUFBQSxJQUNKO0FBQUEsSUFDQSxPQUFPO0FBQ0gsVUFBSSxLQUFLLFNBQVM7QUFDZCxhQUFLLGlCQUFpQixZQUFZO0FBQ2xDLGFBQUssaUJBQWlCLFdBQVc7QUFDakMsYUFBSyxVQUFVO0FBQUEsTUFDbkI7QUFBQSxJQUNKO0FBQUEsSUFDQSxVQUFVO0FBQ04sVUFBSSxLQUFLLFNBQVM7QUFDZCxtQkFBVyxpQkFBaUIsS0FBSyxxQkFBcUI7QUFDbEQsZUFBSyxpQkFBaUIsZUFBZSxJQUFJO0FBQUEsUUFDN0M7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsaUJBQWlCLFdBQVc7QUFDeEIsVUFBSSxLQUFLLFNBQVM7QUFDZCxtQkFBVyxZQUFZLFdBQVc7QUFDOUIsZUFBSyxnQkFBZ0IsUUFBUTtBQUFBLFFBQ2pDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLGdCQUFnQixVQUFVO0FBQ3RCLFlBQU0sZ0JBQWdCLFNBQVM7QUFDL0IsVUFBSSxlQUFlO0FBQ2YsYUFBSyxpQkFBaUIsZUFBZSxTQUFTLFFBQVE7QUFBQSxNQUMxRDtBQUFBLElBQ0o7QUFBQSxJQUNBLGlCQUFpQixlQUFlLFVBQVU7QUFDdEMsWUFBTSxNQUFNLEtBQUssU0FBUyw0QkFBNEIsYUFBYTtBQUNuRSxVQUFJLE9BQU8sTUFBTTtBQUNiLFlBQUksQ0FBQyxLQUFLLFVBQVUsSUFBSSxhQUFhLEdBQUc7QUFDcEMsZUFBSyxrQkFBa0IsS0FBSyxhQUFhO0FBQUEsUUFDN0M7QUFDQSxjQUFNLFFBQVEsS0FBSyxRQUFRLGFBQWEsYUFBYTtBQUNyRCxZQUFJLEtBQUssVUFBVSxJQUFJLGFBQWEsS0FBSyxPQUFPO0FBQzVDLGVBQUssc0JBQXNCLE9BQU8sS0FBSyxRQUFRO0FBQUEsUUFDbkQ7QUFDQSxZQUFJLFNBQVMsTUFBTTtBQUNmLGdCQUFNQyxZQUFXLEtBQUssVUFBVSxJQUFJLGFBQWE7QUFDakQsZUFBSyxVQUFVLE9BQU8sYUFBYTtBQUNuQyxjQUFJQTtBQUNBLGlCQUFLLG9CQUFvQixLQUFLLGVBQWVBLFNBQVE7QUFBQSxRQUM3RCxPQUNLO0FBQ0QsZUFBSyxVQUFVLElBQUksZUFBZSxLQUFLO0FBQUEsUUFDM0M7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0Esa0JBQWtCLEtBQUssZUFBZTtBQUNsQyxVQUFJLEtBQUssU0FBUyxtQkFBbUI7QUFDakMsYUFBSyxTQUFTLGtCQUFrQixLQUFLLGFBQWE7QUFBQSxNQUN0RDtBQUFBLElBQ0o7QUFBQSxJQUNBLHNCQUFzQixPQUFPLEtBQUssVUFBVTtBQUN4QyxVQUFJLEtBQUssU0FBUyx1QkFBdUI7QUFDckMsYUFBSyxTQUFTLHNCQUFzQixPQUFPLEtBQUssUUFBUTtBQUFBLE1BQzVEO0FBQUEsSUFDSjtBQUFBLElBQ0Esb0JBQW9CLEtBQUssZUFBZSxVQUFVO0FBQzlDLFVBQUksS0FBSyxTQUFTLHFCQUFxQjtBQUNuQyxhQUFLLFNBQVMsb0JBQW9CLEtBQUssZUFBZSxRQUFRO0FBQUEsTUFDbEU7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLHNCQUFzQjtBQUN0QixhQUFPLE1BQU0sS0FBSyxJQUFJLElBQUksS0FBSyxzQkFBc0IsT0FBTyxLQUFLLHNCQUFzQixDQUFDLENBQUM7QUFBQSxJQUM3RjtBQUFBLElBQ0EsSUFBSSx3QkFBd0I7QUFDeEIsYUFBTyxNQUFNLEtBQUssS0FBSyxRQUFRLFVBQVUsRUFBRSxJQUFJLENBQUMsY0FBYyxVQUFVLElBQUk7QUFBQSxJQUNoRjtBQUFBLElBQ0EsSUFBSSx5QkFBeUI7QUFDekIsYUFBTyxNQUFNLEtBQUssS0FBSyxVQUFVLEtBQUssQ0FBQztBQUFBLElBQzNDO0FBQUEsRUFDSjtBQUVBLE1BQU0sb0JBQU4sTUFBd0I7QUFBQSxJQUNwQixZQUFZLFNBQVMsZUFBZSxVQUFVO0FBQzFDLFdBQUssb0JBQW9CLElBQUksa0JBQWtCLFNBQVMsZUFBZSxJQUFJO0FBQzNFLFdBQUssV0FBVztBQUNoQixXQUFLLGtCQUFrQixJQUFJLFNBQVM7QUFBQSxJQUN4QztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLGtCQUFrQjtBQUFBLElBQ2xDO0FBQUEsSUFDQSxRQUFRO0FBQ0osV0FBSyxrQkFBa0IsTUFBTTtBQUFBLElBQ2pDO0FBQUEsSUFDQSxNQUFNLFVBQVU7QUFDWixXQUFLLGtCQUFrQixNQUFNLFFBQVE7QUFBQSxJQUN6QztBQUFBLElBQ0EsT0FBTztBQUNILFdBQUssa0JBQWtCLEtBQUs7QUFBQSxJQUNoQztBQUFBLElBQ0EsVUFBVTtBQUNOLFdBQUssa0JBQWtCLFFBQVE7QUFBQSxJQUNuQztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLGtCQUFrQjtBQUFBLElBQ2xDO0FBQUEsSUFDQSxJQUFJLGdCQUFnQjtBQUNoQixhQUFPLEtBQUssa0JBQWtCO0FBQUEsSUFDbEM7QUFBQSxJQUNBLHdCQUF3QixTQUFTO0FBQzdCLFdBQUssY0FBYyxLQUFLLHFCQUFxQixPQUFPLENBQUM7QUFBQSxJQUN6RDtBQUFBLElBQ0EsNkJBQTZCLFNBQVM7QUFDbEMsWUFBTSxDQUFDLGlCQUFpQixhQUFhLElBQUksS0FBSyx3QkFBd0IsT0FBTztBQUM3RSxXQUFLLGdCQUFnQixlQUFlO0FBQ3BDLFdBQUssY0FBYyxhQUFhO0FBQUEsSUFDcEM7QUFBQSxJQUNBLDBCQUEwQixTQUFTO0FBQy9CLFdBQUssZ0JBQWdCLEtBQUssZ0JBQWdCLGdCQUFnQixPQUFPLENBQUM7QUFBQSxJQUN0RTtBQUFBLElBQ0EsY0FBYyxRQUFRO0FBQ2xCLGFBQU8sUUFBUSxDQUFDLFVBQVUsS0FBSyxhQUFhLEtBQUssQ0FBQztBQUFBLElBQ3REO0FBQUEsSUFDQSxnQkFBZ0IsUUFBUTtBQUNwQixhQUFPLFFBQVEsQ0FBQyxVQUFVLEtBQUssZUFBZSxLQUFLLENBQUM7QUFBQSxJQUN4RDtBQUFBLElBQ0EsYUFBYSxPQUFPO0FBQ2hCLFdBQUssU0FBUyxhQUFhLEtBQUs7QUFDaEMsV0FBSyxnQkFBZ0IsSUFBSSxNQUFNLFNBQVMsS0FBSztBQUFBLElBQ2pEO0FBQUEsSUFDQSxlQUFlLE9BQU87QUFDbEIsV0FBSyxTQUFTLGVBQWUsS0FBSztBQUNsQyxXQUFLLGdCQUFnQixPQUFPLE1BQU0sU0FBUyxLQUFLO0FBQUEsSUFDcEQ7QUFBQSxJQUNBLHdCQUF3QixTQUFTO0FBQzdCLFlBQU0saUJBQWlCLEtBQUssZ0JBQWdCLGdCQUFnQixPQUFPO0FBQ25FLFlBQU0sZ0JBQWdCLEtBQUsscUJBQXFCLE9BQU87QUFDdkQsWUFBTSxzQkFBc0IsSUFBSSxnQkFBZ0IsYUFBYSxFQUFFLFVBQVUsQ0FBQyxDQUFDLGVBQWUsWUFBWSxNQUFNLENBQUMsZUFBZSxlQUFlLFlBQVksQ0FBQztBQUN4SixVQUFJLHVCQUF1QixJQUFJO0FBQzNCLGVBQU8sQ0FBQyxDQUFDLEdBQUcsQ0FBQyxDQUFDO0FBQUEsTUFDbEIsT0FDSztBQUNELGVBQU8sQ0FBQyxlQUFlLE1BQU0sbUJBQW1CLEdBQUcsY0FBYyxNQUFNLG1CQUFtQixDQUFDO0FBQUEsTUFDL0Y7QUFBQSxJQUNKO0FBQUEsSUFDQSxxQkFBcUIsU0FBUztBQUMxQixZQUFNLGdCQUFnQixLQUFLO0FBQzNCLFlBQU0sY0FBYyxRQUFRLGFBQWEsYUFBYSxLQUFLO0FBQzNELGFBQU8saUJBQWlCLGFBQWEsU0FBUyxhQUFhO0FBQUEsSUFDL0Q7QUFBQSxFQUNKO0FBQ0EsV0FBUyxpQkFBaUIsYUFBYSxTQUFTLGVBQWU7QUFDM0QsV0FBTyxZQUNGLEtBQUssRUFDTCxNQUFNLEtBQUssRUFDWCxPQUFPLENBQUMsWUFBWSxRQUFRLE1BQU0sRUFDbEMsSUFBSSxDQUFDLFNBQVMsV0FBVyxFQUFFLFNBQVMsZUFBZSxTQUFTLE1BQU0sRUFBRTtBQUFBLEVBQzdFO0FBQ0EsV0FBUyxJQUFJLE1BQU0sT0FBTztBQUN0QixVQUFNLFNBQVMsS0FBSyxJQUFJLEtBQUssUUFBUSxNQUFNLE1BQU07QUFDakQsV0FBTyxNQUFNLEtBQUssRUFBRSxPQUFPLEdBQUcsQ0FBQyxHQUFHLFVBQVUsQ0FBQyxLQUFLLEtBQUssR0FBRyxNQUFNLEtBQUssQ0FBQyxDQUFDO0FBQUEsRUFDM0U7QUFDQSxXQUFTLGVBQWUsTUFBTSxPQUFPO0FBQ2pDLFdBQU8sUUFBUSxTQUFTLEtBQUssU0FBUyxNQUFNLFNBQVMsS0FBSyxXQUFXLE1BQU07QUFBQSxFQUMvRTtBQUVBLE1BQU0sb0JBQU4sTUFBd0I7QUFBQSxJQUNwQixZQUFZLFNBQVMsZUFBZSxVQUFVO0FBQzFDLFdBQUssb0JBQW9CLElBQUksa0JBQWtCLFNBQVMsZUFBZSxJQUFJO0FBQzNFLFdBQUssV0FBVztBQUNoQixXQUFLLHNCQUFzQixvQkFBSSxRQUFRO0FBQ3ZDLFdBQUsseUJBQXlCLG9CQUFJLFFBQVE7QUFBQSxJQUM5QztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLGtCQUFrQjtBQUFBLElBQ2xDO0FBQUEsSUFDQSxRQUFRO0FBQ0osV0FBSyxrQkFBa0IsTUFBTTtBQUFBLElBQ2pDO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxrQkFBa0IsS0FBSztBQUFBLElBQ2hDO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxrQkFBa0IsUUFBUTtBQUFBLElBQ25DO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssa0JBQWtCO0FBQUEsSUFDbEM7QUFBQSxJQUNBLElBQUksZ0JBQWdCO0FBQ2hCLGFBQU8sS0FBSyxrQkFBa0I7QUFBQSxJQUNsQztBQUFBLElBQ0EsYUFBYSxPQUFPO0FBQ2hCLFlBQU0sRUFBRSxRQUFRLElBQUk7QUFDcEIsWUFBTSxFQUFFLE1BQU0sSUFBSSxLQUFLLHlCQUF5QixLQUFLO0FBQ3JELFVBQUksT0FBTztBQUNQLGFBQUssNkJBQTZCLE9BQU8sRUFBRSxJQUFJLE9BQU8sS0FBSztBQUMzRCxhQUFLLFNBQVMsb0JBQW9CLFNBQVMsS0FBSztBQUFBLE1BQ3BEO0FBQUEsSUFDSjtBQUFBLElBQ0EsZUFBZSxPQUFPO0FBQ2xCLFlBQU0sRUFBRSxRQUFRLElBQUk7QUFDcEIsWUFBTSxFQUFFLE1BQU0sSUFBSSxLQUFLLHlCQUF5QixLQUFLO0FBQ3JELFVBQUksT0FBTztBQUNQLGFBQUssNkJBQTZCLE9BQU8sRUFBRSxPQUFPLEtBQUs7QUFDdkQsYUFBSyxTQUFTLHNCQUFzQixTQUFTLEtBQUs7QUFBQSxNQUN0RDtBQUFBLElBQ0o7QUFBQSxJQUNBLHlCQUF5QixPQUFPO0FBQzVCLFVBQUksY0FBYyxLQUFLLG9CQUFvQixJQUFJLEtBQUs7QUFDcEQsVUFBSSxDQUFDLGFBQWE7QUFDZCxzQkFBYyxLQUFLLFdBQVcsS0FBSztBQUNuQyxhQUFLLG9CQUFvQixJQUFJLE9BQU8sV0FBVztBQUFBLE1BQ25EO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLDZCQUE2QixTQUFTO0FBQ2xDLFVBQUksZ0JBQWdCLEtBQUssdUJBQXVCLElBQUksT0FBTztBQUMzRCxVQUFJLENBQUMsZUFBZTtBQUNoQix3QkFBZ0Isb0JBQUksSUFBSTtBQUN4QixhQUFLLHVCQUF1QixJQUFJLFNBQVMsYUFBYTtBQUFBLE1BQzFEO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLFdBQVcsT0FBTztBQUNkLFVBQUk7QUFDQSxjQUFNLFFBQVEsS0FBSyxTQUFTLG1CQUFtQixLQUFLO0FBQ3BELGVBQU8sRUFBRSxNQUFNO0FBQUEsTUFDbkIsU0FDT0MsUUFBTztBQUNWLGVBQU8sRUFBRSxPQUFBQSxPQUFNO0FBQUEsTUFDbkI7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLE1BQU0sa0JBQU4sTUFBc0I7QUFBQSxJQUNsQixZQUFZLFNBQVMsVUFBVTtBQUMzQixXQUFLLFVBQVU7QUFDZixXQUFLLFdBQVc7QUFDaEIsV0FBSyxtQkFBbUIsb0JBQUksSUFBSTtBQUFBLElBQ3BDO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssbUJBQW1CO0FBQ3pCLGFBQUssb0JBQW9CLElBQUksa0JBQWtCLEtBQUssU0FBUyxLQUFLLGlCQUFpQixJQUFJO0FBQ3ZGLGFBQUssa0JBQWtCLE1BQU07QUFBQSxNQUNqQztBQUFBLElBQ0o7QUFBQSxJQUNBLE9BQU87QUFDSCxVQUFJLEtBQUssbUJBQW1CO0FBQ3hCLGFBQUssa0JBQWtCLEtBQUs7QUFDNUIsZUFBTyxLQUFLO0FBQ1osYUFBSyxxQkFBcUI7QUFBQSxNQUM5QjtBQUFBLElBQ0o7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksa0JBQWtCO0FBQ2xCLGFBQU8sS0FBSyxPQUFPO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksU0FBUztBQUNULGFBQU8sS0FBSyxRQUFRO0FBQUEsSUFDeEI7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sTUFBTSxLQUFLLEtBQUssaUJBQWlCLE9BQU8sQ0FBQztBQUFBLElBQ3BEO0FBQUEsSUFDQSxjQUFjLFFBQVE7QUFDbEIsWUFBTSxVQUFVLElBQUksUUFBUSxLQUFLLFNBQVMsTUFBTTtBQUNoRCxXQUFLLGlCQUFpQixJQUFJLFFBQVEsT0FBTztBQUN6QyxXQUFLLFNBQVMsaUJBQWlCLE9BQU87QUFBQSxJQUMxQztBQUFBLElBQ0EsaUJBQWlCLFFBQVE7QUFDckIsWUFBTSxVQUFVLEtBQUssaUJBQWlCLElBQUksTUFBTTtBQUNoRCxVQUFJLFNBQVM7QUFDVCxhQUFLLGlCQUFpQixPQUFPLE1BQU07QUFDbkMsYUFBSyxTQUFTLG9CQUFvQixPQUFPO0FBQUEsTUFDN0M7QUFBQSxJQUNKO0FBQUEsSUFDQSx1QkFBdUI7QUFDbkIsV0FBSyxTQUFTLFFBQVEsQ0FBQyxZQUFZLEtBQUssU0FBUyxvQkFBb0IsU0FBUyxJQUFJLENBQUM7QUFDbkYsV0FBSyxpQkFBaUIsTUFBTTtBQUFBLElBQ2hDO0FBQUEsSUFDQSxtQkFBbUIsT0FBTztBQUN0QixZQUFNLFNBQVMsT0FBTyxTQUFTLE9BQU8sS0FBSyxNQUFNO0FBQ2pELFVBQUksT0FBTyxjQUFjLEtBQUssWUFBWTtBQUN0QyxlQUFPO0FBQUEsTUFDWDtBQUFBLElBQ0o7QUFBQSxJQUNBLG9CQUFvQixTQUFTLFFBQVE7QUFDakMsV0FBSyxjQUFjLE1BQU07QUFBQSxJQUM3QjtBQUFBLElBQ0Esc0JBQXNCLFNBQVMsUUFBUTtBQUNuQyxXQUFLLGlCQUFpQixNQUFNO0FBQUEsSUFDaEM7QUFBQSxFQUNKO0FBRUEsTUFBTSxnQkFBTixNQUFvQjtBQUFBLElBQ2hCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssVUFBVTtBQUNmLFdBQUssV0FBVztBQUNoQixXQUFLLG9CQUFvQixJQUFJLGtCQUFrQixLQUFLLFNBQVMsSUFBSTtBQUNqRSxXQUFLLHFCQUFxQixLQUFLLFdBQVc7QUFBQSxJQUM5QztBQUFBLElBQ0EsUUFBUTtBQUNKLFdBQUssa0JBQWtCLE1BQU07QUFDN0IsV0FBSyx1Q0FBdUM7QUFBQSxJQUNoRDtBQUFBLElBQ0EsT0FBTztBQUNILFdBQUssa0JBQWtCLEtBQUs7QUFBQSxJQUNoQztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsNEJBQTRCLGVBQWU7QUFDdkMsVUFBSSxpQkFBaUIsS0FBSyxvQkFBb0I7QUFDMUMsZUFBTyxLQUFLLG1CQUFtQixhQUFhLEVBQUU7QUFBQSxNQUNsRDtBQUFBLElBQ0o7QUFBQSxJQUNBLGtCQUFrQixLQUFLLGVBQWU7QUFDbEMsWUFBTSxhQUFhLEtBQUssbUJBQW1CLGFBQWE7QUFDeEQsVUFBSSxDQUFDLEtBQUssU0FBUyxHQUFHLEdBQUc7QUFDckIsYUFBSyxzQkFBc0IsS0FBSyxXQUFXLE9BQU8sS0FBSyxTQUFTLEdBQUcsQ0FBQyxHQUFHLFdBQVcsT0FBTyxXQUFXLFlBQVksQ0FBQztBQUFBLE1BQ3JIO0FBQUEsSUFDSjtBQUFBLElBQ0Esc0JBQXNCLE9BQU8sTUFBTSxVQUFVO0FBQ3pDLFlBQU0sYUFBYSxLQUFLLHVCQUF1QixJQUFJO0FBQ25ELFVBQUksVUFBVTtBQUNWO0FBQ0osVUFBSSxhQUFhLE1BQU07QUFDbkIsbUJBQVcsV0FBVyxPQUFPLFdBQVcsWUFBWTtBQUFBLE1BQ3hEO0FBQ0EsV0FBSyxzQkFBc0IsTUFBTSxPQUFPLFFBQVE7QUFBQSxJQUNwRDtBQUFBLElBQ0Esb0JBQW9CLEtBQUssZUFBZSxVQUFVO0FBQzlDLFlBQU0sYUFBYSxLQUFLLHVCQUF1QixHQUFHO0FBQ2xELFVBQUksS0FBSyxTQUFTLEdBQUcsR0FBRztBQUNwQixhQUFLLHNCQUFzQixLQUFLLFdBQVcsT0FBTyxLQUFLLFNBQVMsR0FBRyxDQUFDLEdBQUcsUUFBUTtBQUFBLE1BQ25GLE9BQ0s7QUFDRCxhQUFLLHNCQUFzQixLQUFLLFdBQVcsT0FBTyxXQUFXLFlBQVksR0FBRyxRQUFRO0FBQUEsTUFDeEY7QUFBQSxJQUNKO0FBQUEsSUFDQSx5Q0FBeUM7QUFDckMsaUJBQVcsRUFBRSxLQUFLLE1BQU0sY0FBYyxPQUFPLEtBQUssS0FBSyxrQkFBa0I7QUFDckUsWUFBSSxnQkFBZ0IsVUFBYSxDQUFDLEtBQUssV0FBVyxLQUFLLElBQUksR0FBRyxHQUFHO0FBQzdELGVBQUssc0JBQXNCLE1BQU0sT0FBTyxZQUFZLEdBQUcsTUFBUztBQUFBLFFBQ3BFO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxJQUNBLHNCQUFzQixNQUFNLFVBQVUsYUFBYTtBQUMvQyxZQUFNLG9CQUFvQixHQUFHLElBQUk7QUFDakMsWUFBTSxnQkFBZ0IsS0FBSyxTQUFTLGlCQUFpQjtBQUNyRCxVQUFJLE9BQU8saUJBQWlCLFlBQVk7QUFDcEMsY0FBTSxhQUFhLEtBQUssdUJBQXVCLElBQUk7QUFDbkQsWUFBSTtBQUNBLGdCQUFNLFFBQVEsV0FBVyxPQUFPLFFBQVE7QUFDeEMsY0FBSSxXQUFXO0FBQ2YsY0FBSSxhQUFhO0FBQ2IsdUJBQVcsV0FBVyxPQUFPLFdBQVc7QUFBQSxVQUM1QztBQUNBLHdCQUFjLEtBQUssS0FBSyxVQUFVLE9BQU8sUUFBUTtBQUFBLFFBQ3JELFNBQ09BLFFBQU87QUFDVixjQUFJQSxrQkFBaUIsV0FBVztBQUM1QixZQUFBQSxPQUFNLFVBQVUsbUJBQW1CLEtBQUssUUFBUSxVQUFVLElBQUksV0FBVyxJQUFJLE9BQU9BLE9BQU0sT0FBTztBQUFBLFVBQ3JHO0FBQ0EsZ0JBQU1BO0FBQUEsUUFDVjtBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLG1CQUFtQjtBQUNuQixZQUFNLEVBQUUsbUJBQW1CLElBQUk7QUFDL0IsYUFBTyxPQUFPLEtBQUssa0JBQWtCLEVBQUUsSUFBSSxDQUFDLFFBQVEsbUJBQW1CLEdBQUcsQ0FBQztBQUFBLElBQy9FO0FBQUEsSUFDQSxJQUFJLHlCQUF5QjtBQUN6QixZQUFNLGNBQWMsQ0FBQztBQUNyQixhQUFPLEtBQUssS0FBSyxrQkFBa0IsRUFBRSxRQUFRLENBQUMsUUFBUTtBQUNsRCxjQUFNLGFBQWEsS0FBSyxtQkFBbUIsR0FBRztBQUM5QyxvQkFBWSxXQUFXLElBQUksSUFBSTtBQUFBLE1BQ25DLENBQUM7QUFDRCxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsU0FBUyxlQUFlO0FBQ3BCLFlBQU0sYUFBYSxLQUFLLHVCQUF1QixhQUFhO0FBQzVELFlBQU0sZ0JBQWdCLE1BQU0sV0FBVyxXQUFXLElBQUksQ0FBQztBQUN2RCxhQUFPLEtBQUssU0FBUyxhQUFhO0FBQUEsSUFDdEM7QUFBQSxFQUNKO0FBRUEsTUFBTSxpQkFBTixNQUFxQjtBQUFBLElBQ2pCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssVUFBVTtBQUNmLFdBQUssV0FBVztBQUNoQixXQUFLLGdCQUFnQixJQUFJLFNBQVM7QUFBQSxJQUN0QztBQUFBLElBQ0EsUUFBUTtBQUNKLFVBQUksQ0FBQyxLQUFLLG1CQUFtQjtBQUN6QixhQUFLLG9CQUFvQixJQUFJLGtCQUFrQixLQUFLLFNBQVMsS0FBSyxlQUFlLElBQUk7QUFDckYsYUFBSyxrQkFBa0IsTUFBTTtBQUFBLE1BQ2pDO0FBQUEsSUFDSjtBQUFBLElBQ0EsT0FBTztBQUNILFVBQUksS0FBSyxtQkFBbUI7QUFDeEIsYUFBSyxxQkFBcUI7QUFDMUIsYUFBSyxrQkFBa0IsS0FBSztBQUM1QixlQUFPLEtBQUs7QUFBQSxNQUNoQjtBQUFBLElBQ0o7QUFBQSxJQUNBLGFBQWEsRUFBRSxTQUFTLFNBQVMsS0FBSyxHQUFHO0FBQ3JDLFVBQUksS0FBSyxNQUFNLGdCQUFnQixPQUFPLEdBQUc7QUFDckMsYUFBSyxjQUFjLFNBQVMsSUFBSTtBQUFBLE1BQ3BDO0FBQUEsSUFDSjtBQUFBLElBQ0EsZUFBZSxFQUFFLFNBQVMsU0FBUyxLQUFLLEdBQUc7QUFDdkMsV0FBSyxpQkFBaUIsU0FBUyxJQUFJO0FBQUEsSUFDdkM7QUFBQSxJQUNBLGNBQWMsU0FBUyxNQUFNO0FBQ3pCLFVBQUlDO0FBQ0osVUFBSSxDQUFDLEtBQUssY0FBYyxJQUFJLE1BQU0sT0FBTyxHQUFHO0FBQ3hDLGFBQUssY0FBYyxJQUFJLE1BQU0sT0FBTztBQUNwQyxTQUFDQSxNQUFLLEtBQUssdUJBQXVCLFFBQVFBLFFBQU8sU0FBUyxTQUFTQSxJQUFHLE1BQU0sTUFBTSxLQUFLLFNBQVMsZ0JBQWdCLFNBQVMsSUFBSSxDQUFDO0FBQUEsTUFDbEk7QUFBQSxJQUNKO0FBQUEsSUFDQSxpQkFBaUIsU0FBUyxNQUFNO0FBQzVCLFVBQUlBO0FBQ0osVUFBSSxLQUFLLGNBQWMsSUFBSSxNQUFNLE9BQU8sR0FBRztBQUN2QyxhQUFLLGNBQWMsT0FBTyxNQUFNLE9BQU87QUFDdkMsU0FBQ0EsTUFBSyxLQUFLLHVCQUF1QixRQUFRQSxRQUFPLFNBQVMsU0FBU0EsSUFBRyxNQUFNLE1BQU0sS0FBSyxTQUFTLG1CQUFtQixTQUFTLElBQUksQ0FBQztBQUFBLE1BQ3JJO0FBQUEsSUFDSjtBQUFBLElBQ0EsdUJBQXVCO0FBQ25CLGlCQUFXLFFBQVEsS0FBSyxjQUFjLE1BQU07QUFDeEMsbUJBQVcsV0FBVyxLQUFLLGNBQWMsZ0JBQWdCLElBQUksR0FBRztBQUM1RCxlQUFLLGlCQUFpQixTQUFTLElBQUk7QUFBQSxRQUN2QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLGdCQUFnQjtBQUNoQixhQUFPLFFBQVEsS0FBSyxRQUFRLFVBQVU7QUFBQSxJQUMxQztBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxRQUFRO0FBQ1IsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLEVBQ0o7QUFFQSxXQUFTLGlDQUFpQyxhQUFhLGNBQWM7QUFDakUsVUFBTSxZQUFZLDJCQUEyQixXQUFXO0FBQ3hELFdBQU8sTUFBTSxLQUFLLFVBQVUsT0FBTyxDQUFDLFFBQVFDLGlCQUFnQjtBQUN4RCw4QkFBd0JBLGNBQWEsWUFBWSxFQUFFLFFBQVEsQ0FBQyxTQUFTLE9BQU8sSUFBSSxJQUFJLENBQUM7QUFDckYsYUFBTztBQUFBLElBQ1gsR0FBRyxvQkFBSSxJQUFJLENBQUMsQ0FBQztBQUFBLEVBQ2pCO0FBQ0EsV0FBUyxpQ0FBaUMsYUFBYSxjQUFjO0FBQ2pFLFVBQU0sWUFBWSwyQkFBMkIsV0FBVztBQUN4RCxXQUFPLFVBQVUsT0FBTyxDQUFDLE9BQU9BLGlCQUFnQjtBQUM1QyxZQUFNLEtBQUssR0FBRyx3QkFBd0JBLGNBQWEsWUFBWSxDQUFDO0FBQ2hFLGFBQU87QUFBQSxJQUNYLEdBQUcsQ0FBQyxDQUFDO0FBQUEsRUFDVDtBQUNBLFdBQVMsMkJBQTJCLGFBQWE7QUFDN0MsVUFBTSxZQUFZLENBQUM7QUFDbkIsV0FBTyxhQUFhO0FBQ2hCLGdCQUFVLEtBQUssV0FBVztBQUMxQixvQkFBYyxPQUFPLGVBQWUsV0FBVztBQUFBLElBQ25EO0FBQ0EsV0FBTyxVQUFVLFFBQVE7QUFBQSxFQUM3QjtBQUNBLFdBQVMsd0JBQXdCLGFBQWEsY0FBYztBQUN4RCxVQUFNLGFBQWEsWUFBWSxZQUFZO0FBQzNDLFdBQU8sTUFBTSxRQUFRLFVBQVUsSUFBSSxhQUFhLENBQUM7QUFBQSxFQUNyRDtBQUNBLFdBQVMsd0JBQXdCLGFBQWEsY0FBYztBQUN4RCxVQUFNLGFBQWEsWUFBWSxZQUFZO0FBQzNDLFdBQU8sYUFBYSxPQUFPLEtBQUssVUFBVSxFQUFFLElBQUksQ0FBQyxRQUFRLENBQUMsS0FBSyxXQUFXLEdBQUcsQ0FBQyxDQUFDLElBQUksQ0FBQztBQUFBLEVBQ3hGO0FBRUEsTUFBTSxpQkFBTixNQUFxQjtBQUFBLElBQ2pCLFlBQVksU0FBUyxVQUFVO0FBQzNCLFdBQUssVUFBVTtBQUNmLFdBQUssVUFBVTtBQUNmLFdBQUssV0FBVztBQUNoQixXQUFLLGdCQUFnQixJQUFJLFNBQVM7QUFDbEMsV0FBSyx1QkFBdUIsSUFBSSxTQUFTO0FBQ3pDLFdBQUssc0JBQXNCLG9CQUFJLElBQUk7QUFDbkMsV0FBSyx1QkFBdUIsb0JBQUksSUFBSTtBQUFBLElBQ3hDO0FBQUEsSUFDQSxRQUFRO0FBQ0osVUFBSSxDQUFDLEtBQUssU0FBUztBQUNmLGFBQUssa0JBQWtCLFFBQVEsQ0FBQyxlQUFlO0FBQzNDLGVBQUssK0JBQStCLFVBQVU7QUFDOUMsZUFBSyxnQ0FBZ0MsVUFBVTtBQUFBLFFBQ25ELENBQUM7QUFDRCxhQUFLLFVBQVU7QUFDZixhQUFLLGtCQUFrQixRQUFRLENBQUMsWUFBWSxRQUFRLFFBQVEsQ0FBQztBQUFBLE1BQ2pFO0FBQUEsSUFDSjtBQUFBLElBQ0EsVUFBVTtBQUNOLFdBQUssb0JBQW9CLFFBQVEsQ0FBQyxhQUFhLFNBQVMsUUFBUSxDQUFDO0FBQ2pFLFdBQUsscUJBQXFCLFFBQVEsQ0FBQyxhQUFhLFNBQVMsUUFBUSxDQUFDO0FBQUEsSUFDdEU7QUFBQSxJQUNBLE9BQU87QUFDSCxVQUFJLEtBQUssU0FBUztBQUNkLGFBQUssVUFBVTtBQUNmLGFBQUsscUJBQXFCO0FBQzFCLGFBQUssc0JBQXNCO0FBQzNCLGFBQUssdUJBQXVCO0FBQUEsTUFDaEM7QUFBQSxJQUNKO0FBQUEsSUFDQSx3QkFBd0I7QUFDcEIsVUFBSSxLQUFLLG9CQUFvQixPQUFPLEdBQUc7QUFDbkMsYUFBSyxvQkFBb0IsUUFBUSxDQUFDLGFBQWEsU0FBUyxLQUFLLENBQUM7QUFDOUQsYUFBSyxvQkFBb0IsTUFBTTtBQUFBLE1BQ25DO0FBQUEsSUFDSjtBQUFBLElBQ0EseUJBQXlCO0FBQ3JCLFVBQUksS0FBSyxxQkFBcUIsT0FBTyxHQUFHO0FBQ3BDLGFBQUsscUJBQXFCLFFBQVEsQ0FBQyxhQUFhLFNBQVMsS0FBSyxDQUFDO0FBQy9ELGFBQUsscUJBQXFCLE1BQU07QUFBQSxNQUNwQztBQUFBLElBQ0o7QUFBQSxJQUNBLGdCQUFnQixTQUFTLFdBQVcsRUFBRSxXQUFXLEdBQUc7QUFDaEQsWUFBTSxTQUFTLEtBQUssVUFBVSxTQUFTLFVBQVU7QUFDakQsVUFBSSxRQUFRO0FBQ1IsYUFBSyxjQUFjLFFBQVEsU0FBUyxVQUFVO0FBQUEsTUFDbEQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxrQkFBa0IsU0FBUyxXQUFXLEVBQUUsV0FBVyxHQUFHO0FBQ2xELFlBQU0sU0FBUyxLQUFLLGlCQUFpQixTQUFTLFVBQVU7QUFDeEQsVUFBSSxRQUFRO0FBQ1IsYUFBSyxpQkFBaUIsUUFBUSxTQUFTLFVBQVU7QUFBQSxNQUNyRDtBQUFBLElBQ0o7QUFBQSxJQUNBLHFCQUFxQixTQUFTLEVBQUUsV0FBVyxHQUFHO0FBQzFDLFlBQU0sV0FBVyxLQUFLLFNBQVMsVUFBVTtBQUN6QyxZQUFNLFlBQVksS0FBSyxVQUFVLFNBQVMsVUFBVTtBQUNwRCxZQUFNLHNCQUFzQixRQUFRLFFBQVEsSUFBSSxLQUFLLE9BQU8sbUJBQW1CLEtBQUssVUFBVSxHQUFHO0FBQ2pHLFVBQUksVUFBVTtBQUNWLGVBQU8sYUFBYSx1QkFBdUIsUUFBUSxRQUFRLFFBQVE7QUFBQSxNQUN2RSxPQUNLO0FBQ0QsZUFBTztBQUFBLE1BQ1g7QUFBQSxJQUNKO0FBQUEsSUFDQSx3QkFBd0IsVUFBVSxlQUFlO0FBQzdDLFlBQU0sYUFBYSxLQUFLLHFDQUFxQyxhQUFhO0FBQzFFLFVBQUksWUFBWTtBQUNaLGFBQUssZ0NBQWdDLFVBQVU7QUFBQSxNQUNuRDtBQUFBLElBQ0o7QUFBQSxJQUNBLDZCQUE2QixVQUFVLGVBQWU7QUFDbEQsWUFBTSxhQUFhLEtBQUsscUNBQXFDLGFBQWE7QUFDMUUsVUFBSSxZQUFZO0FBQ1osYUFBSyxnQ0FBZ0MsVUFBVTtBQUFBLE1BQ25EO0FBQUEsSUFDSjtBQUFBLElBQ0EsMEJBQTBCLFVBQVUsZUFBZTtBQUMvQyxZQUFNLGFBQWEsS0FBSyxxQ0FBcUMsYUFBYTtBQUMxRSxVQUFJLFlBQVk7QUFDWixhQUFLLGdDQUFnQyxVQUFVO0FBQUEsTUFDbkQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxjQUFjLFFBQVEsU0FBUyxZQUFZO0FBQ3ZDLFVBQUlEO0FBQ0osVUFBSSxDQUFDLEtBQUsscUJBQXFCLElBQUksWUFBWSxPQUFPLEdBQUc7QUFDckQsYUFBSyxjQUFjLElBQUksWUFBWSxNQUFNO0FBQ3pDLGFBQUsscUJBQXFCLElBQUksWUFBWSxPQUFPO0FBQ2pELFNBQUNBLE1BQUssS0FBSyxvQkFBb0IsSUFBSSxVQUFVLE9BQU8sUUFBUUEsUUFBTyxTQUFTLFNBQVNBLElBQUcsTUFBTSxNQUFNLEtBQUssU0FBUyxnQkFBZ0IsUUFBUSxTQUFTLFVBQVUsQ0FBQztBQUFBLE1BQ2xLO0FBQUEsSUFDSjtBQUFBLElBQ0EsaUJBQWlCLFFBQVEsU0FBUyxZQUFZO0FBQzFDLFVBQUlBO0FBQ0osVUFBSSxLQUFLLHFCQUFxQixJQUFJLFlBQVksT0FBTyxHQUFHO0FBQ3BELGFBQUssY0FBYyxPQUFPLFlBQVksTUFBTTtBQUM1QyxhQUFLLHFCQUFxQixPQUFPLFlBQVksT0FBTztBQUNwRCxTQUFDQSxNQUFLLEtBQUssb0JBQ04sSUFBSSxVQUFVLE9BQU8sUUFBUUEsUUFBTyxTQUFTLFNBQVNBLElBQUcsTUFBTSxNQUFNLEtBQUssU0FBUyxtQkFBbUIsUUFBUSxTQUFTLFVBQVUsQ0FBQztBQUFBLE1BQzNJO0FBQUEsSUFDSjtBQUFBLElBQ0EsdUJBQXVCO0FBQ25CLGlCQUFXLGNBQWMsS0FBSyxxQkFBcUIsTUFBTTtBQUNyRCxtQkFBVyxXQUFXLEtBQUsscUJBQXFCLGdCQUFnQixVQUFVLEdBQUc7QUFDekUscUJBQVcsVUFBVSxLQUFLLGNBQWMsZ0JBQWdCLFVBQVUsR0FBRztBQUNqRSxpQkFBSyxpQkFBaUIsUUFBUSxTQUFTLFVBQVU7QUFBQSxVQUNyRDtBQUFBLFFBQ0o7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsZ0NBQWdDLFlBQVk7QUFDeEMsWUFBTSxXQUFXLEtBQUssb0JBQW9CLElBQUksVUFBVTtBQUN4RCxVQUFJLFVBQVU7QUFDVixpQkFBUyxXQUFXLEtBQUssU0FBUyxVQUFVO0FBQUEsTUFDaEQ7QUFBQSxJQUNKO0FBQUEsSUFDQSwrQkFBK0IsWUFBWTtBQUN2QyxZQUFNLFdBQVcsS0FBSyxTQUFTLFVBQVU7QUFDekMsWUFBTSxtQkFBbUIsSUFBSSxpQkFBaUIsU0FBUyxNQUFNLFVBQVUsTUFBTSxFQUFFLFdBQVcsQ0FBQztBQUMzRixXQUFLLG9CQUFvQixJQUFJLFlBQVksZ0JBQWdCO0FBQ3pELHVCQUFpQixNQUFNO0FBQUEsSUFDM0I7QUFBQSxJQUNBLGdDQUFnQyxZQUFZO0FBQ3hDLFlBQU0sZ0JBQWdCLEtBQUssMkJBQTJCLFVBQVU7QUFDaEUsWUFBTSxvQkFBb0IsSUFBSSxrQkFBa0IsS0FBSyxNQUFNLFNBQVMsZUFBZSxJQUFJO0FBQ3ZGLFdBQUsscUJBQXFCLElBQUksWUFBWSxpQkFBaUI7QUFDM0Qsd0JBQWtCLE1BQU07QUFBQSxJQUM1QjtBQUFBLElBQ0EsU0FBUyxZQUFZO0FBQ2pCLGFBQU8sS0FBSyxNQUFNLFFBQVEseUJBQXlCLFVBQVU7QUFBQSxJQUNqRTtBQUFBLElBQ0EsMkJBQTJCLFlBQVk7QUFDbkMsYUFBTyxLQUFLLE1BQU0sT0FBTyx3QkFBd0IsS0FBSyxZQUFZLFVBQVU7QUFBQSxJQUNoRjtBQUFBLElBQ0EscUNBQXFDLGVBQWU7QUFDaEQsYUFBTyxLQUFLLGtCQUFrQixLQUFLLENBQUMsZUFBZSxLQUFLLDJCQUEyQixVQUFVLE1BQU0sYUFBYTtBQUFBLElBQ3BIO0FBQUEsSUFDQSxJQUFJLHFCQUFxQjtBQUNyQixZQUFNLGVBQWUsSUFBSSxTQUFTO0FBQ2xDLFdBQUssT0FBTyxRQUFRLFFBQVEsQ0FBQyxXQUFXO0FBQ3BDLGNBQU0sY0FBYyxPQUFPLFdBQVc7QUFDdEMsY0FBTSxVQUFVLGlDQUFpQyxhQUFhLFNBQVM7QUFDdkUsZ0JBQVEsUUFBUSxDQUFDLFdBQVcsYUFBYSxJQUFJLFFBQVEsT0FBTyxVQUFVLENBQUM7QUFBQSxNQUMzRSxDQUFDO0FBQ0QsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLElBQUksb0JBQW9CO0FBQ3BCLGFBQU8sS0FBSyxtQkFBbUIsZ0JBQWdCLEtBQUssVUFBVTtBQUFBLElBQ2xFO0FBQUEsSUFDQSxJQUFJLGlDQUFpQztBQUNqQyxhQUFPLEtBQUssbUJBQW1CLGdCQUFnQixLQUFLLFVBQVU7QUFBQSxJQUNsRTtBQUFBLElBQ0EsSUFBSSxvQkFBb0I7QUFDcEIsWUFBTSxjQUFjLEtBQUs7QUFDekIsYUFBTyxLQUFLLE9BQU8sU0FBUyxPQUFPLENBQUMsWUFBWSxZQUFZLFNBQVMsUUFBUSxVQUFVLENBQUM7QUFBQSxJQUM1RjtBQUFBLElBQ0EsVUFBVSxTQUFTLFlBQVk7QUFDM0IsYUFBTyxDQUFDLENBQUMsS0FBSyxVQUFVLFNBQVMsVUFBVSxLQUFLLENBQUMsQ0FBQyxLQUFLLGlCQUFpQixTQUFTLFVBQVU7QUFBQSxJQUMvRjtBQUFBLElBQ0EsVUFBVSxTQUFTLFlBQVk7QUFDM0IsYUFBTyxLQUFLLFlBQVkscUNBQXFDLFNBQVMsVUFBVTtBQUFBLElBQ3BGO0FBQUEsSUFDQSxpQkFBaUIsU0FBUyxZQUFZO0FBQ2xDLGFBQU8sS0FBSyxjQUFjLGdCQUFnQixVQUFVLEVBQUUsS0FBSyxDQUFDLFdBQVcsT0FBTyxZQUFZLE9BQU87QUFBQSxJQUNyRztBQUFBLElBQ0EsSUFBSSxRQUFRO0FBQ1IsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxjQUFjO0FBQ2QsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsYUFBTyxLQUFLLFlBQVk7QUFBQSxJQUM1QjtBQUFBLEVBQ0o7QUFFQSxNQUFNLFVBQU4sTUFBYztBQUFBLElBQ1YsWUFBWSxRQUFRLE9BQU87QUFDdkIsV0FBSyxtQkFBbUIsQ0FBQyxjQUFjLFNBQVMsQ0FBQyxNQUFNO0FBQ25ELGNBQU0sRUFBRSxZQUFZLFlBQVksUUFBUSxJQUFJO0FBQzVDLGlCQUFTLE9BQU8sT0FBTyxFQUFFLFlBQVksWUFBWSxRQUFRLEdBQUcsTUFBTTtBQUNsRSxhQUFLLFlBQVksaUJBQWlCLEtBQUssWUFBWSxjQUFjLE1BQU07QUFBQSxNQUMzRTtBQUNBLFdBQUssU0FBUztBQUNkLFdBQUssUUFBUTtBQUNiLFdBQUssYUFBYSxJQUFJLE9BQU8sc0JBQXNCLElBQUk7QUFDdkQsV0FBSyxrQkFBa0IsSUFBSSxnQkFBZ0IsTUFBTSxLQUFLLFVBQVU7QUFDaEUsV0FBSyxnQkFBZ0IsSUFBSSxjQUFjLE1BQU0sS0FBSyxVQUFVO0FBQzVELFdBQUssaUJBQWlCLElBQUksZUFBZSxNQUFNLElBQUk7QUFDbkQsV0FBSyxpQkFBaUIsSUFBSSxlQUFlLE1BQU0sSUFBSTtBQUNuRCxVQUFJO0FBQ0EsYUFBSyxXQUFXLFdBQVc7QUFDM0IsYUFBSyxpQkFBaUIsWUFBWTtBQUFBLE1BQ3RDLFNBQ09ELFFBQU87QUFDVixhQUFLLFlBQVlBLFFBQU8seUJBQXlCO0FBQUEsTUFDckQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxnQkFBZ0IsTUFBTTtBQUMzQixXQUFLLGNBQWMsTUFBTTtBQUN6QixXQUFLLGVBQWUsTUFBTTtBQUMxQixXQUFLLGVBQWUsTUFBTTtBQUMxQixVQUFJO0FBQ0EsYUFBSyxXQUFXLFFBQVE7QUFDeEIsYUFBSyxpQkFBaUIsU0FBUztBQUFBLE1BQ25DLFNBQ09BLFFBQU87QUFDVixhQUFLLFlBQVlBLFFBQU8sdUJBQXVCO0FBQUEsTUFDbkQ7QUFBQSxJQUNKO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxlQUFlLFFBQVE7QUFBQSxJQUNoQztBQUFBLElBQ0EsYUFBYTtBQUNULFVBQUk7QUFDQSxhQUFLLFdBQVcsV0FBVztBQUMzQixhQUFLLGlCQUFpQixZQUFZO0FBQUEsTUFDdEMsU0FDT0EsUUFBTztBQUNWLGFBQUssWUFBWUEsUUFBTywwQkFBMEI7QUFBQSxNQUN0RDtBQUNBLFdBQUssZUFBZSxLQUFLO0FBQ3pCLFdBQUssZUFBZSxLQUFLO0FBQ3pCLFdBQUssY0FBYyxLQUFLO0FBQ3hCLFdBQUssZ0JBQWdCLEtBQUs7QUFBQSxJQUM5QjtBQUFBLElBQ0EsSUFBSSxjQUFjO0FBQ2QsYUFBTyxLQUFLLE9BQU87QUFBQSxJQUN2QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLE9BQU87QUFBQSxJQUN2QjtBQUFBLElBQ0EsSUFBSSxTQUFTO0FBQ1QsYUFBTyxLQUFLLFlBQVk7QUFBQSxJQUM1QjtBQUFBLElBQ0EsSUFBSSxhQUFhO0FBQ2IsYUFBTyxLQUFLLFlBQVk7QUFBQSxJQUM1QjtBQUFBLElBQ0EsSUFBSSxVQUFVO0FBQ1YsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLElBQ0EsSUFBSSxnQkFBZ0I7QUFDaEIsYUFBTyxLQUFLLFFBQVE7QUFBQSxJQUN4QjtBQUFBLElBQ0EsWUFBWUEsUUFBTyxTQUFTLFNBQVMsQ0FBQyxHQUFHO0FBQ3JDLFlBQU0sRUFBRSxZQUFZLFlBQVksUUFBUSxJQUFJO0FBQzVDLGVBQVMsT0FBTyxPQUFPLEVBQUUsWUFBWSxZQUFZLFFBQVEsR0FBRyxNQUFNO0FBQ2xFLFdBQUssWUFBWSxZQUFZQSxRQUFPLFNBQVMsT0FBTyxJQUFJLE1BQU07QUFBQSxJQUNsRTtBQUFBLElBQ0EsZ0JBQWdCLFNBQVMsTUFBTTtBQUMzQixXQUFLLHVCQUF1QixHQUFHLElBQUksbUJBQW1CLE9BQU87QUFBQSxJQUNqRTtBQUFBLElBQ0EsbUJBQW1CLFNBQVMsTUFBTTtBQUM5QixXQUFLLHVCQUF1QixHQUFHLElBQUksc0JBQXNCLE9BQU87QUFBQSxJQUNwRTtBQUFBLElBQ0EsZ0JBQWdCLFFBQVEsU0FBUyxNQUFNO0FBQ25DLFdBQUssdUJBQXVCLEdBQUcsa0JBQWtCLElBQUksQ0FBQyxtQkFBbUIsUUFBUSxPQUFPO0FBQUEsSUFDNUY7QUFBQSxJQUNBLG1CQUFtQixRQUFRLFNBQVMsTUFBTTtBQUN0QyxXQUFLLHVCQUF1QixHQUFHLGtCQUFrQixJQUFJLENBQUMsc0JBQXNCLFFBQVEsT0FBTztBQUFBLElBQy9GO0FBQUEsSUFDQSx1QkFBdUIsZUFBZSxNQUFNO0FBQ3hDLFlBQU0sYUFBYSxLQUFLO0FBQ3hCLFVBQUksT0FBTyxXQUFXLFVBQVUsS0FBSyxZQUFZO0FBQzdDLG1CQUFXLFVBQVUsRUFBRSxHQUFHLElBQUk7QUFBQSxNQUNsQztBQUFBLElBQ0o7QUFBQSxFQUNKO0FBRUEsV0FBUyxNQUFNLGFBQWE7QUFDeEIsV0FBTyxPQUFPLGFBQWEscUJBQXFCLFdBQVcsQ0FBQztBQUFBLEVBQ2hFO0FBQ0EsV0FBUyxPQUFPLGFBQWEsWUFBWTtBQUNyQyxVQUFNLG9CQUFvQixPQUFPLFdBQVc7QUFDNUMsVUFBTSxtQkFBbUIsb0JBQW9CLFlBQVksV0FBVyxVQUFVO0FBQzlFLFdBQU8saUJBQWlCLGtCQUFrQixXQUFXLGdCQUFnQjtBQUNyRSxXQUFPO0FBQUEsRUFDWDtBQUNBLFdBQVMscUJBQXFCLGFBQWE7QUFDdkMsVUFBTSxZQUFZLGlDQUFpQyxhQUFhLFdBQVc7QUFDM0UsV0FBTyxVQUFVLE9BQU8sQ0FBQyxtQkFBbUIsYUFBYTtBQUNyRCxZQUFNLGFBQWEsU0FBUyxXQUFXO0FBQ3ZDLGlCQUFXLE9BQU8sWUFBWTtBQUMxQixjQUFNLGFBQWEsa0JBQWtCLEdBQUcsS0FBSyxDQUFDO0FBQzlDLDBCQUFrQixHQUFHLElBQUksT0FBTyxPQUFPLFlBQVksV0FBVyxHQUFHLENBQUM7QUFBQSxNQUN0RTtBQUNBLGFBQU87QUFBQSxJQUNYLEdBQUcsQ0FBQyxDQUFDO0FBQUEsRUFDVDtBQUNBLFdBQVMsb0JBQW9CLFdBQVcsWUFBWTtBQUNoRCxXQUFPLFdBQVcsVUFBVSxFQUFFLE9BQU8sQ0FBQyxrQkFBa0IsUUFBUTtBQUM1RCxZQUFNLGFBQWEsc0JBQXNCLFdBQVcsWUFBWSxHQUFHO0FBQ25FLFVBQUksWUFBWTtBQUNaLGVBQU8sT0FBTyxrQkFBa0IsRUFBRSxDQUFDLEdBQUcsR0FBRyxXQUFXLENBQUM7QUFBQSxNQUN6RDtBQUNBLGFBQU87QUFBQSxJQUNYLEdBQUcsQ0FBQyxDQUFDO0FBQUEsRUFDVDtBQUNBLFdBQVMsc0JBQXNCLFdBQVcsWUFBWSxLQUFLO0FBQ3ZELFVBQU0sc0JBQXNCLE9BQU8seUJBQXlCLFdBQVcsR0FBRztBQUMxRSxVQUFNLGtCQUFrQix1QkFBdUIsV0FBVztBQUMxRCxRQUFJLENBQUMsaUJBQWlCO0FBQ2xCLFlBQU0sYUFBYSxPQUFPLHlCQUF5QixZQUFZLEdBQUcsRUFBRTtBQUNwRSxVQUFJLHFCQUFxQjtBQUNyQixtQkFBVyxNQUFNLG9CQUFvQixPQUFPLFdBQVc7QUFDdkQsbUJBQVcsTUFBTSxvQkFBb0IsT0FBTyxXQUFXO0FBQUEsTUFDM0Q7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLEVBQ0o7QUFDQSxNQUFNLGNBQWMsTUFBTTtBQUN0QixRQUFJLE9BQU8sT0FBTyx5QkFBeUIsWUFBWTtBQUNuRCxhQUFPLENBQUMsV0FBVyxDQUFDLEdBQUcsT0FBTyxvQkFBb0IsTUFBTSxHQUFHLEdBQUcsT0FBTyxzQkFBc0IsTUFBTSxDQUFDO0FBQUEsSUFDdEcsT0FDSztBQUNELGFBQU8sT0FBTztBQUFBLElBQ2xCO0FBQUEsRUFDSixHQUFHO0FBQ0gsTUFBTSxVQUFVLE1BQU07QUFDbEIsYUFBUyxrQkFBa0IsYUFBYTtBQUNwQyxlQUFTLFdBQVc7QUFDaEIsZUFBTyxRQUFRLFVBQVUsYUFBYSxXQUFXLFVBQVU7QUFBQSxNQUMvRDtBQUNBLGVBQVMsWUFBWSxPQUFPLE9BQU8sWUFBWSxXQUFXO0FBQUEsUUFDdEQsYUFBYSxFQUFFLE9BQU8sU0FBUztBQUFBLE1BQ25DLENBQUM7QUFDRCxjQUFRLGVBQWUsVUFBVSxXQUFXO0FBQzVDLGFBQU87QUFBQSxJQUNYO0FBQ0EsYUFBUyx1QkFBdUI7QUFDNUIsWUFBTSxJQUFJLFdBQVk7QUFDbEIsYUFBSyxFQUFFLEtBQUssSUFBSTtBQUFBLE1BQ3BCO0FBQ0EsWUFBTSxJQUFJLGtCQUFrQixDQUFDO0FBQzdCLFFBQUUsVUFBVSxJQUFJLFdBQVk7QUFBQSxNQUFFO0FBQzlCLGFBQU8sSUFBSSxFQUFFO0FBQUEsSUFDakI7QUFDQSxRQUFJO0FBQ0EsMkJBQXFCO0FBQ3JCLGFBQU87QUFBQSxJQUNYLFNBQ09BLFFBQU87QUFDVixhQUFPLENBQUMsZ0JBQWdCLE1BQU0saUJBQWlCLFlBQVk7QUFBQSxNQUMzRDtBQUFBLElBQ0o7QUFBQSxFQUNKLEdBQUc7QUFFSCxXQUFTLGdCQUFnQixZQUFZO0FBQ2pDLFdBQU87QUFBQSxNQUNILFlBQVksV0FBVztBQUFBLE1BQ3ZCLHVCQUF1QixNQUFNLFdBQVcscUJBQXFCO0FBQUEsSUFDakU7QUFBQSxFQUNKO0FBRUEsTUFBTSxTQUFOLE1BQWE7QUFBQSxJQUNULFlBQVksYUFBYSxZQUFZO0FBQ2pDLFdBQUssY0FBYztBQUNuQixXQUFLLGFBQWEsZ0JBQWdCLFVBQVU7QUFDNUMsV0FBSyxrQkFBa0Isb0JBQUksUUFBUTtBQUNuQyxXQUFLLG9CQUFvQixvQkFBSSxJQUFJO0FBQUEsSUFDckM7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxXQUFXO0FBQUEsSUFDM0I7QUFBQSxJQUNBLElBQUksd0JBQXdCO0FBQ3hCLGFBQU8sS0FBSyxXQUFXO0FBQUEsSUFDM0I7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sTUFBTSxLQUFLLEtBQUssaUJBQWlCO0FBQUEsSUFDNUM7QUFBQSxJQUNBLHVCQUF1QixPQUFPO0FBQzFCLFlBQU0sVUFBVSxLQUFLLHFCQUFxQixLQUFLO0FBQy9DLFdBQUssa0JBQWtCLElBQUksT0FBTztBQUNsQyxjQUFRLFFBQVE7QUFBQSxJQUNwQjtBQUFBLElBQ0EsMEJBQTBCLE9BQU87QUFDN0IsWUFBTSxVQUFVLEtBQUssZ0JBQWdCLElBQUksS0FBSztBQUM5QyxVQUFJLFNBQVM7QUFDVCxhQUFLLGtCQUFrQixPQUFPLE9BQU87QUFDckMsZ0JBQVEsV0FBVztBQUFBLE1BQ3ZCO0FBQUEsSUFDSjtBQUFBLElBQ0EscUJBQXFCLE9BQU87QUFDeEIsVUFBSSxVQUFVLEtBQUssZ0JBQWdCLElBQUksS0FBSztBQUM1QyxVQUFJLENBQUMsU0FBUztBQUNWLGtCQUFVLElBQUksUUFBUSxNQUFNLEtBQUs7QUFDakMsYUFBSyxnQkFBZ0IsSUFBSSxPQUFPLE9BQU87QUFBQSxNQUMzQztBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsRUFDSjtBQUVBLE1BQU0sV0FBTixNQUFlO0FBQUEsSUFDWCxZQUFZLE9BQU87QUFDZixXQUFLLFFBQVE7QUFBQSxJQUNqQjtBQUFBLElBQ0EsSUFBSSxNQUFNO0FBQ04sYUFBTyxLQUFLLEtBQUssSUFBSSxLQUFLLFdBQVcsSUFBSSxDQUFDO0FBQUEsSUFDOUM7QUFBQSxJQUNBLElBQUksTUFBTTtBQUNOLGFBQU8sS0FBSyxPQUFPLElBQUksRUFBRSxDQUFDO0FBQUEsSUFDOUI7QUFBQSxJQUNBLE9BQU8sTUFBTTtBQUNULFlBQU0sY0FBYyxLQUFLLEtBQUssSUFBSSxLQUFLLFdBQVcsSUFBSSxDQUFDLEtBQUs7QUFDNUQsYUFBTyxTQUFTLFdBQVc7QUFBQSxJQUMvQjtBQUFBLElBQ0EsaUJBQWlCLE1BQU07QUFDbkIsYUFBTyxLQUFLLEtBQUssdUJBQXVCLEtBQUssV0FBVyxJQUFJLENBQUM7QUFBQSxJQUNqRTtBQUFBLElBQ0EsV0FBVyxNQUFNO0FBQ2IsYUFBTyxHQUFHLElBQUk7QUFBQSxJQUNsQjtBQUFBLElBQ0EsSUFBSSxPQUFPO0FBQ1AsYUFBTyxLQUFLLE1BQU07QUFBQSxJQUN0QjtBQUFBLEVBQ0o7QUFFQSxNQUFNLFVBQU4sTUFBYztBQUFBLElBQ1YsWUFBWSxPQUFPO0FBQ2YsV0FBSyxRQUFRO0FBQUEsSUFDakI7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksS0FBSztBQUNMLFlBQU0sT0FBTyxLQUFLLHVCQUF1QixHQUFHO0FBQzVDLGFBQU8sS0FBSyxRQUFRLGFBQWEsSUFBSTtBQUFBLElBQ3pDO0FBQUEsSUFDQSxJQUFJLEtBQUssT0FBTztBQUNaLFlBQU0sT0FBTyxLQUFLLHVCQUF1QixHQUFHO0FBQzVDLFdBQUssUUFBUSxhQUFhLE1BQU0sS0FBSztBQUNyQyxhQUFPLEtBQUssSUFBSSxHQUFHO0FBQUEsSUFDdkI7QUFBQSxJQUNBLElBQUksS0FBSztBQUNMLFlBQU0sT0FBTyxLQUFLLHVCQUF1QixHQUFHO0FBQzVDLGFBQU8sS0FBSyxRQUFRLGFBQWEsSUFBSTtBQUFBLElBQ3pDO0FBQUEsSUFDQSxPQUFPLEtBQUs7QUFDUixVQUFJLEtBQUssSUFBSSxHQUFHLEdBQUc7QUFDZixjQUFNLE9BQU8sS0FBSyx1QkFBdUIsR0FBRztBQUM1QyxhQUFLLFFBQVEsZ0JBQWdCLElBQUk7QUFDakMsZUFBTztBQUFBLE1BQ1gsT0FDSztBQUNELGVBQU87QUFBQSxNQUNYO0FBQUEsSUFDSjtBQUFBLElBQ0EsdUJBQXVCLEtBQUs7QUFDeEIsYUFBTyxRQUFRLEtBQUssVUFBVSxJQUFJLFVBQVUsR0FBRyxDQUFDO0FBQUEsSUFDcEQ7QUFBQSxFQUNKO0FBRUEsTUFBTSxRQUFOLE1BQVk7QUFBQSxJQUNSLFlBQVksUUFBUTtBQUNoQixXQUFLLHFCQUFxQixvQkFBSSxRQUFRO0FBQ3RDLFdBQUssU0FBUztBQUFBLElBQ2xCO0FBQUEsSUFDQSxLQUFLLFFBQVEsS0FBSyxTQUFTO0FBQ3ZCLFVBQUksYUFBYSxLQUFLLG1CQUFtQixJQUFJLE1BQU07QUFDbkQsVUFBSSxDQUFDLFlBQVk7QUFDYixxQkFBYSxvQkFBSSxJQUFJO0FBQ3JCLGFBQUssbUJBQW1CLElBQUksUUFBUSxVQUFVO0FBQUEsTUFDbEQ7QUFDQSxVQUFJLENBQUMsV0FBVyxJQUFJLEdBQUcsR0FBRztBQUN0QixtQkFBVyxJQUFJLEdBQUc7QUFDbEIsYUFBSyxPQUFPLEtBQUssU0FBUyxNQUFNO0FBQUEsTUFDcEM7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLFdBQVMsNEJBQTRCLGVBQWUsT0FBTztBQUN2RCxXQUFPLElBQUksYUFBYSxNQUFNLEtBQUs7QUFBQSxFQUN2QztBQUVBLE1BQU0sWUFBTixNQUFnQjtBQUFBLElBQ1osWUFBWSxPQUFPO0FBQ2YsV0FBSyxRQUFRO0FBQUEsSUFDakI7QUFBQSxJQUNBLElBQUksVUFBVTtBQUNWLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksYUFBYTtBQUNiLGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksU0FBUztBQUNULGFBQU8sS0FBSyxNQUFNO0FBQUEsSUFDdEI7QUFBQSxJQUNBLElBQUksWUFBWTtBQUNaLGFBQU8sS0FBSyxLQUFLLFVBQVUsS0FBSztBQUFBLElBQ3BDO0FBQUEsSUFDQSxRQUFRLGFBQWE7QUFDakIsYUFBTyxZQUFZLE9BQU8sQ0FBQyxRQUFRLGVBQWUsVUFBVSxLQUFLLFdBQVcsVUFBVSxLQUFLLEtBQUssaUJBQWlCLFVBQVUsR0FBRyxNQUFTO0FBQUEsSUFDM0k7QUFBQSxJQUNBLFdBQVcsYUFBYTtBQUNwQixhQUFPLFlBQVksT0FBTyxDQUFDLFNBQVMsZUFBZTtBQUFBLFFBQy9DLEdBQUc7QUFBQSxRQUNILEdBQUcsS0FBSyxlQUFlLFVBQVU7QUFBQSxRQUNqQyxHQUFHLEtBQUsscUJBQXFCLFVBQVU7QUFBQSxNQUMzQyxHQUFHLENBQUMsQ0FBQztBQUFBLElBQ1Q7QUFBQSxJQUNBLFdBQVcsWUFBWTtBQUNuQixZQUFNLFdBQVcsS0FBSyx5QkFBeUIsVUFBVTtBQUN6RCxhQUFPLEtBQUssTUFBTSxZQUFZLFFBQVE7QUFBQSxJQUMxQztBQUFBLElBQ0EsZUFBZSxZQUFZO0FBQ3ZCLFlBQU0sV0FBVyxLQUFLLHlCQUF5QixVQUFVO0FBQ3pELGFBQU8sS0FBSyxNQUFNLGdCQUFnQixRQUFRO0FBQUEsSUFDOUM7QUFBQSxJQUNBLHlCQUF5QixZQUFZO0FBQ2pDLFlBQU0sZ0JBQWdCLEtBQUssT0FBTyx3QkFBd0IsS0FBSyxVQUFVO0FBQ3pFLGFBQU8sNEJBQTRCLGVBQWUsVUFBVTtBQUFBLElBQ2hFO0FBQUEsSUFDQSxpQkFBaUIsWUFBWTtBQUN6QixZQUFNLFdBQVcsS0FBSywrQkFBK0IsVUFBVTtBQUMvRCxhQUFPLEtBQUssVUFBVSxLQUFLLE1BQU0sWUFBWSxRQUFRLEdBQUcsVUFBVTtBQUFBLElBQ3RFO0FBQUEsSUFDQSxxQkFBcUIsWUFBWTtBQUM3QixZQUFNLFdBQVcsS0FBSywrQkFBK0IsVUFBVTtBQUMvRCxhQUFPLEtBQUssTUFBTSxnQkFBZ0IsUUFBUSxFQUFFLElBQUksQ0FBQyxZQUFZLEtBQUssVUFBVSxTQUFTLFVBQVUsQ0FBQztBQUFBLElBQ3BHO0FBQUEsSUFDQSwrQkFBK0IsWUFBWTtBQUN2QyxZQUFNLG1CQUFtQixHQUFHLEtBQUssVUFBVSxJQUFJLFVBQVU7QUFDekQsYUFBTyw0QkFBNEIsS0FBSyxPQUFPLGlCQUFpQixnQkFBZ0I7QUFBQSxJQUNwRjtBQUFBLElBQ0EsVUFBVSxTQUFTLFlBQVk7QUFDM0IsVUFBSSxTQUFTO0FBQ1QsY0FBTSxFQUFFLFdBQVcsSUFBSTtBQUN2QixjQUFNLGdCQUFnQixLQUFLLE9BQU87QUFDbEMsY0FBTSx1QkFBdUIsS0FBSyxPQUFPLHdCQUF3QixVQUFVO0FBQzNFLGFBQUssTUFBTSxLQUFLLFNBQVMsVUFBVSxVQUFVLElBQUksa0JBQWtCLGFBQWEsS0FBSyxVQUFVLElBQUksVUFBVSxVQUFVLG9CQUFvQixLQUFLLFVBQVUsVUFDL0ksYUFBYSwrRUFBK0U7QUFBQSxNQUMzRztBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxJQUFJLFFBQVE7QUFDUixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsRUFDSjtBQUVBLE1BQU0sWUFBTixNQUFnQjtBQUFBLElBQ1osWUFBWSxPQUFPLG1CQUFtQjtBQUNsQyxXQUFLLFFBQVE7QUFDYixXQUFLLG9CQUFvQjtBQUFBLElBQzdCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFlBQVk7QUFDWixhQUFPLEtBQUssS0FBSyxVQUFVLEtBQUs7QUFBQSxJQUNwQztBQUFBLElBQ0EsUUFBUSxhQUFhO0FBQ2pCLGFBQU8sWUFBWSxPQUFPLENBQUMsUUFBUSxlQUFlLFVBQVUsS0FBSyxXQUFXLFVBQVUsR0FBRyxNQUFTO0FBQUEsSUFDdEc7QUFBQSxJQUNBLFdBQVcsYUFBYTtBQUNwQixhQUFPLFlBQVksT0FBTyxDQUFDLFNBQVMsZUFBZSxDQUFDLEdBQUcsU0FBUyxHQUFHLEtBQUssZUFBZSxVQUFVLENBQUMsR0FBRyxDQUFDLENBQUM7QUFBQSxJQUMzRztBQUFBLElBQ0EseUJBQXlCLFlBQVk7QUFDakMsWUFBTSxnQkFBZ0IsS0FBSyxPQUFPLHdCQUF3QixLQUFLLFlBQVksVUFBVTtBQUNyRixhQUFPLEtBQUssa0JBQWtCLGFBQWEsYUFBYTtBQUFBLElBQzVEO0FBQUEsSUFDQSxXQUFXLFlBQVk7QUFDbkIsWUFBTSxXQUFXLEtBQUsseUJBQXlCLFVBQVU7QUFDekQsVUFBSTtBQUNBLGVBQU8sS0FBSyxZQUFZLFVBQVUsVUFBVTtBQUFBLElBQ3BEO0FBQUEsSUFDQSxlQUFlLFlBQVk7QUFDdkIsWUFBTSxXQUFXLEtBQUsseUJBQXlCLFVBQVU7QUFDekQsYUFBTyxXQUFXLEtBQUssZ0JBQWdCLFVBQVUsVUFBVSxJQUFJLENBQUM7QUFBQSxJQUNwRTtBQUFBLElBQ0EsWUFBWSxVQUFVLFlBQVk7QUFDOUIsWUFBTSxXQUFXLEtBQUssTUFBTSxjQUFjLFFBQVE7QUFDbEQsYUFBTyxTQUFTLE9BQU8sQ0FBQyxZQUFZLEtBQUssZUFBZSxTQUFTLFVBQVUsVUFBVSxDQUFDLEVBQUUsQ0FBQztBQUFBLElBQzdGO0FBQUEsSUFDQSxnQkFBZ0IsVUFBVSxZQUFZO0FBQ2xDLFlBQU0sV0FBVyxLQUFLLE1BQU0sY0FBYyxRQUFRO0FBQ2xELGFBQU8sU0FBUyxPQUFPLENBQUMsWUFBWSxLQUFLLGVBQWUsU0FBUyxVQUFVLFVBQVUsQ0FBQztBQUFBLElBQzFGO0FBQUEsSUFDQSxlQUFlLFNBQVMsVUFBVSxZQUFZO0FBQzFDLFlBQU0sc0JBQXNCLFFBQVEsYUFBYSxLQUFLLE1BQU0sT0FBTyxtQkFBbUIsS0FBSztBQUMzRixhQUFPLFFBQVEsUUFBUSxRQUFRLEtBQUssb0JBQW9CLE1BQU0sR0FBRyxFQUFFLFNBQVMsVUFBVTtBQUFBLElBQzFGO0FBQUEsRUFDSjtBQUVBLE1BQU0sUUFBTixNQUFNLE9BQU07QUFBQSxJQUNSLFlBQVksUUFBUSxTQUFTLFlBQVksUUFBUTtBQUM3QyxXQUFLLFVBQVUsSUFBSSxVQUFVLElBQUk7QUFDakMsV0FBSyxVQUFVLElBQUksU0FBUyxJQUFJO0FBQ2hDLFdBQUssT0FBTyxJQUFJLFFBQVEsSUFBSTtBQUM1QixXQUFLLGtCQUFrQixDQUFDRyxhQUFZO0FBQ2hDLGVBQU9BLFNBQVEsUUFBUSxLQUFLLGtCQUFrQixNQUFNLEtBQUs7QUFBQSxNQUM3RDtBQUNBLFdBQUssU0FBUztBQUNkLFdBQUssVUFBVTtBQUNmLFdBQUssYUFBYTtBQUNsQixXQUFLLFFBQVEsSUFBSSxNQUFNLE1BQU07QUFDN0IsV0FBSyxVQUFVLElBQUksVUFBVSxLQUFLLGVBQWUsT0FBTztBQUFBLElBQzVEO0FBQUEsSUFDQSxZQUFZLFVBQVU7QUFDbEIsYUFBTyxLQUFLLFFBQVEsUUFBUSxRQUFRLElBQUksS0FBSyxVQUFVLEtBQUssY0FBYyxRQUFRLEVBQUUsS0FBSyxLQUFLLGVBQWU7QUFBQSxJQUNqSDtBQUFBLElBQ0EsZ0JBQWdCLFVBQVU7QUFDdEIsYUFBTztBQUFBLFFBQ0gsR0FBSSxLQUFLLFFBQVEsUUFBUSxRQUFRLElBQUksQ0FBQyxLQUFLLE9BQU8sSUFBSSxDQUFDO0FBQUEsUUFDdkQsR0FBRyxLQUFLLGNBQWMsUUFBUSxFQUFFLE9BQU8sS0FBSyxlQUFlO0FBQUEsTUFDL0Q7QUFBQSxJQUNKO0FBQUEsSUFDQSxjQUFjLFVBQVU7QUFDcEIsYUFBTyxNQUFNLEtBQUssS0FBSyxRQUFRLGlCQUFpQixRQUFRLENBQUM7QUFBQSxJQUM3RDtBQUFBLElBQ0EsSUFBSSxxQkFBcUI7QUFDckIsYUFBTyw0QkFBNEIsS0FBSyxPQUFPLHFCQUFxQixLQUFLLFVBQVU7QUFBQSxJQUN2RjtBQUFBLElBQ0EsSUFBSSxrQkFBa0I7QUFDbEIsYUFBTyxLQUFLLFlBQVksU0FBUztBQUFBLElBQ3JDO0FBQUEsSUFDQSxJQUFJLGdCQUFnQjtBQUNoQixhQUFPLEtBQUssa0JBQ04sT0FDQSxJQUFJLE9BQU0sS0FBSyxRQUFRLFNBQVMsaUJBQWlCLEtBQUssWUFBWSxLQUFLLE1BQU0sTUFBTTtBQUFBLElBQzdGO0FBQUEsRUFDSjtBQUVBLE1BQU0sZ0JBQU4sTUFBb0I7QUFBQSxJQUNoQixZQUFZLFNBQVMsUUFBUSxVQUFVO0FBQ25DLFdBQUssVUFBVTtBQUNmLFdBQUssU0FBUztBQUNkLFdBQUssV0FBVztBQUNoQixXQUFLLG9CQUFvQixJQUFJLGtCQUFrQixLQUFLLFNBQVMsS0FBSyxxQkFBcUIsSUFBSTtBQUMzRixXQUFLLDhCQUE4QixvQkFBSSxRQUFRO0FBQy9DLFdBQUssdUJBQXVCLG9CQUFJLFFBQVE7QUFBQSxJQUM1QztBQUFBLElBQ0EsUUFBUTtBQUNKLFdBQUssa0JBQWtCLE1BQU07QUFBQSxJQUNqQztBQUFBLElBQ0EsT0FBTztBQUNILFdBQUssa0JBQWtCLEtBQUs7QUFBQSxJQUNoQztBQUFBLElBQ0EsSUFBSSxzQkFBc0I7QUFDdEIsYUFBTyxLQUFLLE9BQU87QUFBQSxJQUN2QjtBQUFBLElBQ0EsbUJBQW1CLE9BQU87QUFDdEIsWUFBTSxFQUFFLFNBQVMsU0FBUyxXQUFXLElBQUk7QUFDekMsYUFBTyxLQUFLLGtDQUFrQyxTQUFTLFVBQVU7QUFBQSxJQUNyRTtBQUFBLElBQ0Esa0NBQWtDLFNBQVMsWUFBWTtBQUNuRCxZQUFNLHFCQUFxQixLQUFLLGtDQUFrQyxPQUFPO0FBQ3pFLFVBQUksUUFBUSxtQkFBbUIsSUFBSSxVQUFVO0FBQzdDLFVBQUksQ0FBQyxPQUFPO0FBQ1IsZ0JBQVEsS0FBSyxTQUFTLG1DQUFtQyxTQUFTLFVBQVU7QUFDNUUsMkJBQW1CLElBQUksWUFBWSxLQUFLO0FBQUEsTUFDNUM7QUFDQSxhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0Esb0JBQW9CLFNBQVMsT0FBTztBQUNoQyxZQUFNLGtCQUFrQixLQUFLLHFCQUFxQixJQUFJLEtBQUssS0FBSyxLQUFLO0FBQ3JFLFdBQUsscUJBQXFCLElBQUksT0FBTyxjQUFjO0FBQ25ELFVBQUksa0JBQWtCLEdBQUc7QUFDckIsYUFBSyxTQUFTLGVBQWUsS0FBSztBQUFBLE1BQ3RDO0FBQUEsSUFDSjtBQUFBLElBQ0Esc0JBQXNCLFNBQVMsT0FBTztBQUNsQyxZQUFNLGlCQUFpQixLQUFLLHFCQUFxQixJQUFJLEtBQUs7QUFDMUQsVUFBSSxnQkFBZ0I7QUFDaEIsYUFBSyxxQkFBcUIsSUFBSSxPQUFPLGlCQUFpQixDQUFDO0FBQ3ZELFlBQUksa0JBQWtCLEdBQUc7QUFDckIsZUFBSyxTQUFTLGtCQUFrQixLQUFLO0FBQUEsUUFDekM7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0Esa0NBQWtDLFNBQVM7QUFDdkMsVUFBSSxxQkFBcUIsS0FBSyw0QkFBNEIsSUFBSSxPQUFPO0FBQ3JFLFVBQUksQ0FBQyxvQkFBb0I7QUFDckIsNkJBQXFCLG9CQUFJLElBQUk7QUFDN0IsYUFBSyw0QkFBNEIsSUFBSSxTQUFTLGtCQUFrQjtBQUFBLE1BQ3BFO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxFQUNKO0FBRUEsTUFBTSxTQUFOLE1BQWE7QUFBQSxJQUNULFlBQVksYUFBYTtBQUNyQixXQUFLLGNBQWM7QUFDbkIsV0FBSyxnQkFBZ0IsSUFBSSxjQUFjLEtBQUssU0FBUyxLQUFLLFFBQVEsSUFBSTtBQUN0RSxXQUFLLHFCQUFxQixJQUFJLFNBQVM7QUFDdkMsV0FBSyxzQkFBc0Isb0JBQUksSUFBSTtBQUFBLElBQ3ZDO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssWUFBWTtBQUFBLElBQzVCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxhQUFPLEtBQUssWUFBWTtBQUFBLElBQzVCO0FBQUEsSUFDQSxJQUFJLFNBQVM7QUFDVCxhQUFPLEtBQUssWUFBWTtBQUFBLElBQzVCO0FBQUEsSUFDQSxJQUFJLHNCQUFzQjtBQUN0QixhQUFPLEtBQUssT0FBTztBQUFBLElBQ3ZCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLE1BQU0sS0FBSyxLQUFLLG9CQUFvQixPQUFPLENBQUM7QUFBQSxJQUN2RDtBQUFBLElBQ0EsSUFBSSxXQUFXO0FBQ1gsYUFBTyxLQUFLLFFBQVEsT0FBTyxDQUFDLFVBQVUsV0FBVyxTQUFTLE9BQU8sT0FBTyxRQUFRLEdBQUcsQ0FBQyxDQUFDO0FBQUEsSUFDekY7QUFBQSxJQUNBLFFBQVE7QUFDSixXQUFLLGNBQWMsTUFBTTtBQUFBLElBQzdCO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxjQUFjLEtBQUs7QUFBQSxJQUM1QjtBQUFBLElBQ0EsZUFBZSxZQUFZO0FBQ3ZCLFdBQUssaUJBQWlCLFdBQVcsVUFBVTtBQUMzQyxZQUFNLFNBQVMsSUFBSSxPQUFPLEtBQUssYUFBYSxVQUFVO0FBQ3RELFdBQUssY0FBYyxNQUFNO0FBQ3pCLFlBQU0sWUFBWSxXQUFXLHNCQUFzQjtBQUNuRCxVQUFJLFdBQVc7QUFDWCxrQkFBVSxLQUFLLFdBQVcsdUJBQXVCLFdBQVcsWUFBWSxLQUFLLFdBQVc7QUFBQSxNQUM1RjtBQUFBLElBQ0o7QUFBQSxJQUNBLGlCQUFpQixZQUFZO0FBQ3pCLFlBQU0sU0FBUyxLQUFLLG9CQUFvQixJQUFJLFVBQVU7QUFDdEQsVUFBSSxRQUFRO0FBQ1IsYUFBSyxpQkFBaUIsTUFBTTtBQUFBLE1BQ2hDO0FBQUEsSUFDSjtBQUFBLElBQ0Esa0NBQWtDLFNBQVMsWUFBWTtBQUNuRCxZQUFNLFNBQVMsS0FBSyxvQkFBb0IsSUFBSSxVQUFVO0FBQ3RELFVBQUksUUFBUTtBQUNSLGVBQU8sT0FBTyxTQUFTLEtBQUssQ0FBQyxZQUFZLFFBQVEsV0FBVyxPQUFPO0FBQUEsTUFDdkU7QUFBQSxJQUNKO0FBQUEsSUFDQSw2Q0FBNkMsU0FBUyxZQUFZO0FBQzlELFlBQU0sUUFBUSxLQUFLLGNBQWMsa0NBQWtDLFNBQVMsVUFBVTtBQUN0RixVQUFJLE9BQU87QUFDUCxhQUFLLGNBQWMsb0JBQW9CLE1BQU0sU0FBUyxLQUFLO0FBQUEsTUFDL0QsT0FDSztBQUNELGdCQUFRLE1BQU0sa0RBQWtELFVBQVUsa0JBQWtCLE9BQU87QUFBQSxNQUN2RztBQUFBLElBQ0o7QUFBQSxJQUNBLFlBQVlILFFBQU8sU0FBUyxRQUFRO0FBQ2hDLFdBQUssWUFBWSxZQUFZQSxRQUFPLFNBQVMsTUFBTTtBQUFBLElBQ3ZEO0FBQUEsSUFDQSxtQ0FBbUMsU0FBUyxZQUFZO0FBQ3BELGFBQU8sSUFBSSxNQUFNLEtBQUssUUFBUSxTQUFTLFlBQVksS0FBSyxNQUFNO0FBQUEsSUFDbEU7QUFBQSxJQUNBLGVBQWUsT0FBTztBQUNsQixXQUFLLG1CQUFtQixJQUFJLE1BQU0sWUFBWSxLQUFLO0FBQ25ELFlBQU0sU0FBUyxLQUFLLG9CQUFvQixJQUFJLE1BQU0sVUFBVTtBQUM1RCxVQUFJLFFBQVE7QUFDUixlQUFPLHVCQUF1QixLQUFLO0FBQUEsTUFDdkM7QUFBQSxJQUNKO0FBQUEsSUFDQSxrQkFBa0IsT0FBTztBQUNyQixXQUFLLG1CQUFtQixPQUFPLE1BQU0sWUFBWSxLQUFLO0FBQ3RELFlBQU0sU0FBUyxLQUFLLG9CQUFvQixJQUFJLE1BQU0sVUFBVTtBQUM1RCxVQUFJLFFBQVE7QUFDUixlQUFPLDBCQUEwQixLQUFLO0FBQUEsTUFDMUM7QUFBQSxJQUNKO0FBQUEsSUFDQSxjQUFjLFFBQVE7QUFDbEIsV0FBSyxvQkFBb0IsSUFBSSxPQUFPLFlBQVksTUFBTTtBQUN0RCxZQUFNLFNBQVMsS0FBSyxtQkFBbUIsZ0JBQWdCLE9BQU8sVUFBVTtBQUN4RSxhQUFPLFFBQVEsQ0FBQyxVQUFVLE9BQU8sdUJBQXVCLEtBQUssQ0FBQztBQUFBLElBQ2xFO0FBQUEsSUFDQSxpQkFBaUIsUUFBUTtBQUNyQixXQUFLLG9CQUFvQixPQUFPLE9BQU8sVUFBVTtBQUNqRCxZQUFNLFNBQVMsS0FBSyxtQkFBbUIsZ0JBQWdCLE9BQU8sVUFBVTtBQUN4RSxhQUFPLFFBQVEsQ0FBQyxVQUFVLE9BQU8sMEJBQTBCLEtBQUssQ0FBQztBQUFBLElBQ3JFO0FBQUEsRUFDSjtBQUVBLE1BQU0sZ0JBQWdCO0FBQUEsSUFDbEIscUJBQXFCO0FBQUEsSUFDckIsaUJBQWlCO0FBQUEsSUFDakIsaUJBQWlCO0FBQUEsSUFDakIseUJBQXlCLENBQUMsZUFBZSxRQUFRLFVBQVU7QUFBQSxJQUMzRCx5QkFBeUIsQ0FBQyxZQUFZLFdBQVcsUUFBUSxVQUFVLElBQUksTUFBTTtBQUFBLElBQzdFLGFBQWEsT0FBTyxPQUFPLE9BQU8sT0FBTyxFQUFFLE9BQU8sU0FBUyxLQUFLLE9BQU8sS0FBSyxVQUFVLE9BQU8sS0FBSyxJQUFJLFdBQVcsTUFBTSxhQUFhLE1BQU0sYUFBYSxPQUFPLGNBQWMsTUFBTSxRQUFRLEtBQUssT0FBTyxTQUFTLFVBQVUsV0FBVyxXQUFXLEdBQUcsa0JBQWtCLDZCQUE2QixNQUFNLEVBQUUsRUFBRSxJQUFJLENBQUMsTUFBTSxDQUFDLEdBQUcsQ0FBQyxDQUFDLENBQUMsQ0FBQyxHQUFHLGtCQUFrQixhQUFhLE1BQU0sRUFBRSxFQUFFLElBQUksQ0FBQyxNQUFNLENBQUMsR0FBRyxDQUFDLENBQUMsQ0FBQyxDQUFDO0FBQUEsRUFDalk7QUFDQSxXQUFTLGtCQUFrQixPQUFPO0FBQzlCLFdBQU8sTUFBTSxPQUFPLENBQUMsTUFBTSxDQUFDLEdBQUcsQ0FBQyxNQUFPLE9BQU8sT0FBTyxPQUFPLE9BQU8sQ0FBQyxHQUFHLElBQUksR0FBRyxFQUFFLENBQUMsQ0FBQyxHQUFHLEVBQUUsQ0FBQyxHQUFJLENBQUMsQ0FBQztBQUFBLEVBQ2xHO0FBRUEsTUFBTSxjQUFOLE1BQWtCO0FBQUEsSUFDZCxZQUFZLFVBQVUsU0FBUyxpQkFBaUIsU0FBUyxlQUFlO0FBQ3BFLFdBQUssU0FBUztBQUNkLFdBQUssUUFBUTtBQUNiLFdBQUssbUJBQW1CLENBQUMsWUFBWSxjQUFjLFNBQVMsQ0FBQyxNQUFNO0FBQy9ELFlBQUksS0FBSyxPQUFPO0FBQ1osZUFBSyxvQkFBb0IsWUFBWSxjQUFjLE1BQU07QUFBQSxRQUM3RDtBQUFBLE1BQ0o7QUFDQSxXQUFLLFVBQVU7QUFDZixXQUFLLFNBQVM7QUFDZCxXQUFLLGFBQWEsSUFBSSxXQUFXLElBQUk7QUFDckMsV0FBSyxTQUFTLElBQUksT0FBTyxJQUFJO0FBQzdCLFdBQUssMEJBQTBCLE9BQU8sT0FBTyxDQUFDLEdBQUcsOEJBQThCO0FBQUEsSUFDbkY7QUFBQSxJQUNBLE9BQU8sTUFBTSxTQUFTLFFBQVE7QUFDMUIsWUFBTSxjQUFjLElBQUksS0FBSyxTQUFTLE1BQU07QUFDNUMsa0JBQVksTUFBTTtBQUNsQixhQUFPO0FBQUEsSUFDWDtBQUFBLElBQ0EsTUFBTSxRQUFRO0FBQ1YsWUFBTSxTQUFTO0FBQ2YsV0FBSyxpQkFBaUIsZUFBZSxVQUFVO0FBQy9DLFdBQUssV0FBVyxNQUFNO0FBQ3RCLFdBQUssT0FBTyxNQUFNO0FBQ2xCLFdBQUssaUJBQWlCLGVBQWUsT0FBTztBQUFBLElBQ2hEO0FBQUEsSUFDQSxPQUFPO0FBQ0gsV0FBSyxpQkFBaUIsZUFBZSxVQUFVO0FBQy9DLFdBQUssV0FBVyxLQUFLO0FBQ3JCLFdBQUssT0FBTyxLQUFLO0FBQ2pCLFdBQUssaUJBQWlCLGVBQWUsTUFBTTtBQUFBLElBQy9DO0FBQUEsSUFDQSxTQUFTLFlBQVksdUJBQXVCO0FBQ3hDLFdBQUssS0FBSyxFQUFFLFlBQVksc0JBQXNCLENBQUM7QUFBQSxJQUNuRDtBQUFBLElBQ0EscUJBQXFCLE1BQU0sUUFBUTtBQUMvQixXQUFLLHdCQUF3QixJQUFJLElBQUk7QUFBQSxJQUN6QztBQUFBLElBQ0EsS0FBSyxTQUFTLE1BQU07QUFDaEIsWUFBTSxjQUFjLE1BQU0sUUFBUSxJQUFJLElBQUksT0FBTyxDQUFDLE1BQU0sR0FBRyxJQUFJO0FBQy9ELGtCQUFZLFFBQVEsQ0FBQyxlQUFlO0FBQ2hDLFlBQUksV0FBVyxzQkFBc0IsWUFBWTtBQUM3QyxlQUFLLE9BQU8sZUFBZSxVQUFVO0FBQUEsUUFDekM7QUFBQSxNQUNKLENBQUM7QUFBQSxJQUNMO0FBQUEsSUFDQSxPQUFPLFNBQVMsTUFBTTtBQUNsQixZQUFNLGNBQWMsTUFBTSxRQUFRLElBQUksSUFBSSxPQUFPLENBQUMsTUFBTSxHQUFHLElBQUk7QUFDL0Qsa0JBQVksUUFBUSxDQUFDLGVBQWUsS0FBSyxPQUFPLGlCQUFpQixVQUFVLENBQUM7QUFBQSxJQUNoRjtBQUFBLElBQ0EsSUFBSSxjQUFjO0FBQ2QsYUFBTyxLQUFLLE9BQU8sU0FBUyxJQUFJLENBQUMsWUFBWSxRQUFRLFVBQVU7QUFBQSxJQUNuRTtBQUFBLElBQ0EscUNBQXFDLFNBQVMsWUFBWTtBQUN0RCxZQUFNLFVBQVUsS0FBSyxPQUFPLGtDQUFrQyxTQUFTLFVBQVU7QUFDakYsYUFBTyxVQUFVLFFBQVEsYUFBYTtBQUFBLElBQzFDO0FBQUEsSUFDQSxZQUFZQSxRQUFPLFNBQVMsUUFBUTtBQUNoQyxVQUFJQztBQUNKLFdBQUssT0FBTyxNQUFNO0FBQUE7QUFBQTtBQUFBO0FBQUEsS0FBa0IsU0FBU0QsUUFBTyxNQUFNO0FBQzFELE9BQUNDLE1BQUssT0FBTyxhQUFhLFFBQVFBLFFBQU8sU0FBUyxTQUFTQSxJQUFHLEtBQUssUUFBUSxTQUFTLElBQUksR0FBRyxHQUFHRCxNQUFLO0FBQUEsSUFDdkc7QUFBQSxJQUNBLG9CQUFvQixZQUFZLGNBQWMsU0FBUyxDQUFDLEdBQUc7QUFDdkQsZUFBUyxPQUFPLE9BQU8sRUFBRSxhQUFhLEtBQUssR0FBRyxNQUFNO0FBQ3BELFdBQUssT0FBTyxlQUFlLEdBQUcsVUFBVSxLQUFLLFlBQVksRUFBRTtBQUMzRCxXQUFLLE9BQU8sSUFBSSxZQUFZLE9BQU8sT0FBTyxDQUFDLEdBQUcsTUFBTSxDQUFDO0FBQ3JELFdBQUssT0FBTyxTQUFTO0FBQUEsSUFDekI7QUFBQSxFQUNKO0FBQ0EsV0FBUyxXQUFXO0FBQ2hCLFdBQU8sSUFBSSxRQUFRLENBQUMsWUFBWTtBQUM1QixVQUFJLFNBQVMsY0FBYyxXQUFXO0FBQ2xDLGlCQUFTLGlCQUFpQixvQkFBb0IsTUFBTSxRQUFRLENBQUM7QUFBQSxNQUNqRSxPQUNLO0FBQ0QsZ0JBQVE7QUFBQSxNQUNaO0FBQUEsSUFDSixDQUFDO0FBQUEsRUFDTDtBQUVBLFdBQVMsd0JBQXdCLGFBQWE7QUFDMUMsVUFBTSxVQUFVLGlDQUFpQyxhQUFhLFNBQVM7QUFDdkUsV0FBTyxRQUFRLE9BQU8sQ0FBQyxZQUFZLG9CQUFvQjtBQUNuRCxhQUFPLE9BQU8sT0FBTyxZQUFZLDZCQUE2QixlQUFlLENBQUM7QUFBQSxJQUNsRixHQUFHLENBQUMsQ0FBQztBQUFBLEVBQ1Q7QUFDQSxXQUFTLDZCQUE2QixLQUFLO0FBQ3ZDLFdBQU87QUFBQSxNQUNILENBQUMsR0FBRyxHQUFHLE9BQU8sR0FBRztBQUFBLFFBQ2IsTUFBTTtBQUNGLGdCQUFNLEVBQUUsUUFBUSxJQUFJO0FBQ3BCLGNBQUksUUFBUSxJQUFJLEdBQUcsR0FBRztBQUNsQixtQkFBTyxRQUFRLElBQUksR0FBRztBQUFBLFVBQzFCLE9BQ0s7QUFDRCxrQkFBTSxZQUFZLFFBQVEsaUJBQWlCLEdBQUc7QUFDOUMsa0JBQU0sSUFBSSxNQUFNLHNCQUFzQixTQUFTLEdBQUc7QUFBQSxVQUN0RDtBQUFBLFFBQ0o7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLEdBQUcsR0FBRyxTQUFTLEdBQUc7QUFBQSxRQUNmLE1BQU07QUFDRixpQkFBTyxLQUFLLFFBQVEsT0FBTyxHQUFHO0FBQUEsUUFDbEM7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLE1BQU0sV0FBVyxHQUFHLENBQUMsT0FBTyxHQUFHO0FBQUEsUUFDNUIsTUFBTTtBQUNGLGlCQUFPLEtBQUssUUFBUSxJQUFJLEdBQUc7QUFBQSxRQUMvQjtBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUVBLFdBQVMseUJBQXlCLGFBQWE7QUFDM0MsVUFBTSxVQUFVLGlDQUFpQyxhQUFhLFNBQVM7QUFDdkUsV0FBTyxRQUFRLE9BQU8sQ0FBQyxZQUFZLHFCQUFxQjtBQUNwRCxhQUFPLE9BQU8sT0FBTyxZQUFZLDhCQUE4QixnQkFBZ0IsQ0FBQztBQUFBLElBQ3BGLEdBQUcsQ0FBQyxDQUFDO0FBQUEsRUFDVDtBQUNBLFdBQVMsb0JBQW9CLFlBQVksU0FBUyxZQUFZO0FBQzFELFdBQU8sV0FBVyxZQUFZLHFDQUFxQyxTQUFTLFVBQVU7QUFBQSxFQUMxRjtBQUNBLFdBQVMscUNBQXFDLFlBQVksU0FBUyxZQUFZO0FBQzNFLFFBQUksbUJBQW1CLG9CQUFvQixZQUFZLFNBQVMsVUFBVTtBQUMxRSxRQUFJO0FBQ0EsYUFBTztBQUNYLGVBQVcsWUFBWSxPQUFPLDZDQUE2QyxTQUFTLFVBQVU7QUFDOUYsdUJBQW1CLG9CQUFvQixZQUFZLFNBQVMsVUFBVTtBQUN0RSxRQUFJO0FBQ0EsYUFBTztBQUFBLEVBQ2Y7QUFDQSxXQUFTLDhCQUE4QixNQUFNO0FBQ3pDLFVBQU0sZ0JBQWdCLGtCQUFrQixJQUFJO0FBQzVDLFdBQU87QUFBQSxNQUNILENBQUMsR0FBRyxhQUFhLFFBQVEsR0FBRztBQUFBLFFBQ3hCLE1BQU07QUFDRixnQkFBTSxnQkFBZ0IsS0FBSyxRQUFRLEtBQUssSUFBSTtBQUM1QyxnQkFBTSxXQUFXLEtBQUssUUFBUSx5QkFBeUIsSUFBSTtBQUMzRCxjQUFJLGVBQWU7QUFDZixrQkFBTSxtQkFBbUIscUNBQXFDLE1BQU0sZUFBZSxJQUFJO0FBQ3ZGLGdCQUFJO0FBQ0EscUJBQU87QUFDWCxrQkFBTSxJQUFJLE1BQU0sZ0VBQWdFLElBQUksbUNBQW1DLEtBQUssVUFBVSxHQUFHO0FBQUEsVUFDN0k7QUFDQSxnQkFBTSxJQUFJLE1BQU0sMkJBQTJCLElBQUksMEJBQTBCLEtBQUssVUFBVSx1RUFBdUUsUUFBUSxJQUFJO0FBQUEsUUFDL0s7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLEdBQUcsYUFBYSxTQUFTLEdBQUc7QUFBQSxRQUN6QixNQUFNO0FBQ0YsZ0JBQU0sVUFBVSxLQUFLLFFBQVEsUUFBUSxJQUFJO0FBQ3pDLGNBQUksUUFBUSxTQUFTLEdBQUc7QUFDcEIsbUJBQU8sUUFDRixJQUFJLENBQUMsa0JBQWtCO0FBQ3hCLG9CQUFNLG1CQUFtQixxQ0FBcUMsTUFBTSxlQUFlLElBQUk7QUFDdkYsa0JBQUk7QUFDQSx1QkFBTztBQUNYLHNCQUFRLEtBQUssZ0VBQWdFLElBQUksbUNBQW1DLEtBQUssVUFBVSxLQUFLLGFBQWE7QUFBQSxZQUN6SixDQUFDLEVBQ0ksT0FBTyxDQUFDLGVBQWUsVUFBVTtBQUFBLFVBQzFDO0FBQ0EsaUJBQU8sQ0FBQztBQUFBLFFBQ1o7QUFBQSxNQUNKO0FBQUEsTUFDQSxDQUFDLEdBQUcsYUFBYSxlQUFlLEdBQUc7QUFBQSxRQUMvQixNQUFNO0FBQ0YsZ0JBQU0sZ0JBQWdCLEtBQUssUUFBUSxLQUFLLElBQUk7QUFDNUMsZ0JBQU0sV0FBVyxLQUFLLFFBQVEseUJBQXlCLElBQUk7QUFDM0QsY0FBSSxlQUFlO0FBQ2YsbUJBQU87QUFBQSxVQUNYLE9BQ0s7QUFDRCxrQkFBTSxJQUFJLE1BQU0sMkJBQTJCLElBQUksMEJBQTBCLEtBQUssVUFBVSx1RUFBdUUsUUFBUSxJQUFJO0FBQUEsVUFDL0s7QUFBQSxRQUNKO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxHQUFHLGFBQWEsZ0JBQWdCLEdBQUc7QUFBQSxRQUNoQyxNQUFNO0FBQ0YsaUJBQU8sS0FBSyxRQUFRLFFBQVEsSUFBSTtBQUFBLFFBQ3BDO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxNQUFNLFdBQVcsYUFBYSxDQUFDLFFBQVEsR0FBRztBQUFBLFFBQ3ZDLE1BQU07QUFDRixpQkFBTyxLQUFLLFFBQVEsSUFBSSxJQUFJO0FBQUEsUUFDaEM7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLEVBQ0o7QUFFQSxXQUFTLHlCQUF5QixhQUFhO0FBQzNDLFVBQU0sVUFBVSxpQ0FBaUMsYUFBYSxTQUFTO0FBQ3ZFLFdBQU8sUUFBUSxPQUFPLENBQUMsWUFBWSxxQkFBcUI7QUFDcEQsYUFBTyxPQUFPLE9BQU8sWUFBWSw4QkFBOEIsZ0JBQWdCLENBQUM7QUFBQSxJQUNwRixHQUFHLENBQUMsQ0FBQztBQUFBLEVBQ1Q7QUFDQSxXQUFTLDhCQUE4QixNQUFNO0FBQ3pDLFdBQU87QUFBQSxNQUNILENBQUMsR0FBRyxJQUFJLFFBQVEsR0FBRztBQUFBLFFBQ2YsTUFBTTtBQUNGLGdCQUFNLFNBQVMsS0FBSyxRQUFRLEtBQUssSUFBSTtBQUNyQyxjQUFJLFFBQVE7QUFDUixtQkFBTztBQUFBLFVBQ1gsT0FDSztBQUNELGtCQUFNLElBQUksTUFBTSwyQkFBMkIsSUFBSSxVQUFVLEtBQUssVUFBVSxjQUFjO0FBQUEsVUFDMUY7QUFBQSxRQUNKO0FBQUEsTUFDSjtBQUFBLE1BQ0EsQ0FBQyxHQUFHLElBQUksU0FBUyxHQUFHO0FBQUEsUUFDaEIsTUFBTTtBQUNGLGlCQUFPLEtBQUssUUFBUSxRQUFRLElBQUk7QUFBQSxRQUNwQztBQUFBLE1BQ0o7QUFBQSxNQUNBLENBQUMsTUFBTSxXQUFXLElBQUksQ0FBQyxRQUFRLEdBQUc7QUFBQSxRQUM5QixNQUFNO0FBQ0YsaUJBQU8sS0FBSyxRQUFRLElBQUksSUFBSTtBQUFBLFFBQ2hDO0FBQUEsTUFDSjtBQUFBLElBQ0o7QUFBQSxFQUNKO0FBRUEsV0FBUyx3QkFBd0IsYUFBYTtBQUMxQyxVQUFNLHVCQUF1QixpQ0FBaUMsYUFBYSxRQUFRO0FBQ25GLFVBQU0sd0JBQXdCO0FBQUEsTUFDMUIsb0JBQW9CO0FBQUEsUUFDaEIsTUFBTTtBQUNGLGlCQUFPLHFCQUFxQixPQUFPLENBQUMsUUFBUSx3QkFBd0I7QUFDaEUsa0JBQU0sa0JBQWtCLHlCQUF5QixxQkFBcUIsS0FBSyxVQUFVO0FBQ3JGLGtCQUFNLGdCQUFnQixLQUFLLEtBQUssdUJBQXVCLGdCQUFnQixHQUFHO0FBQzFFLG1CQUFPLE9BQU8sT0FBTyxRQUFRLEVBQUUsQ0FBQyxhQUFhLEdBQUcsZ0JBQWdCLENBQUM7QUFBQSxVQUNyRSxHQUFHLENBQUMsQ0FBQztBQUFBLFFBQ1Q7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUNBLFdBQU8scUJBQXFCLE9BQU8sQ0FBQyxZQUFZLHdCQUF3QjtBQUNwRSxhQUFPLE9BQU8sT0FBTyxZQUFZLGlDQUFpQyxtQkFBbUIsQ0FBQztBQUFBLElBQzFGLEdBQUcscUJBQXFCO0FBQUEsRUFDNUI7QUFDQSxXQUFTLGlDQUFpQyxxQkFBcUIsWUFBWTtBQUN2RSxVQUFNLGFBQWEseUJBQXlCLHFCQUFxQixVQUFVO0FBQzNFLFVBQU0sRUFBRSxLQUFLLE1BQU0sUUFBUSxNQUFNLFFBQVEsTUFBTSxJQUFJO0FBQ25ELFdBQU87QUFBQSxNQUNILENBQUMsSUFBSSxHQUFHO0FBQUEsUUFDSixNQUFNO0FBQ0YsZ0JBQU0sUUFBUSxLQUFLLEtBQUssSUFBSSxHQUFHO0FBQy9CLGNBQUksVUFBVSxNQUFNO0FBQ2hCLG1CQUFPLEtBQUssS0FBSztBQUFBLFVBQ3JCLE9BQ0s7QUFDRCxtQkFBTyxXQUFXO0FBQUEsVUFDdEI7QUFBQSxRQUNKO0FBQUEsUUFDQSxJQUFJLE9BQU87QUFDUCxjQUFJLFVBQVUsUUFBVztBQUNyQixpQkFBSyxLQUFLLE9BQU8sR0FBRztBQUFBLFVBQ3hCLE9BQ0s7QUFDRCxpQkFBSyxLQUFLLElBQUksS0FBSyxNQUFNLEtBQUssQ0FBQztBQUFBLFVBQ25DO0FBQUEsUUFDSjtBQUFBLE1BQ0o7QUFBQSxNQUNBLENBQUMsTUFBTSxXQUFXLElBQUksQ0FBQyxFQUFFLEdBQUc7QUFBQSxRQUN4QixNQUFNO0FBQ0YsaUJBQU8sS0FBSyxLQUFLLElBQUksR0FBRyxLQUFLLFdBQVc7QUFBQSxRQUM1QztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsRUFDSjtBQUNBLFdBQVMseUJBQXlCLENBQUMsT0FBTyxjQUFjLEdBQUcsWUFBWTtBQUNuRSxXQUFPLHlDQUF5QztBQUFBLE1BQzVDO0FBQUEsTUFDQTtBQUFBLE1BQ0E7QUFBQSxJQUNKLENBQUM7QUFBQSxFQUNMO0FBQ0EsV0FBUyx1QkFBdUIsVUFBVTtBQUN0QyxZQUFRLFVBQVU7QUFBQSxNQUNkLEtBQUs7QUFDRCxlQUFPO0FBQUEsTUFDWCxLQUFLO0FBQ0QsZUFBTztBQUFBLE1BQ1gsS0FBSztBQUNELGVBQU87QUFBQSxNQUNYLEtBQUs7QUFDRCxlQUFPO0FBQUEsTUFDWCxLQUFLO0FBQ0QsZUFBTztBQUFBLElBQ2Y7QUFBQSxFQUNKO0FBQ0EsV0FBUyxzQkFBc0IsY0FBYztBQUN6QyxZQUFRLE9BQU8sY0FBYztBQUFBLE1BQ3pCLEtBQUs7QUFDRCxlQUFPO0FBQUEsTUFDWCxLQUFLO0FBQ0QsZUFBTztBQUFBLE1BQ1gsS0FBSztBQUNELGVBQU87QUFBQSxJQUNmO0FBQ0EsUUFBSSxNQUFNLFFBQVEsWUFBWTtBQUMxQixhQUFPO0FBQ1gsUUFBSSxPQUFPLFVBQVUsU0FBUyxLQUFLLFlBQVksTUFBTTtBQUNqRCxhQUFPO0FBQUEsRUFDZjtBQUNBLFdBQVMscUJBQXFCLFNBQVM7QUFDbkMsVUFBTSxFQUFFLFlBQVksT0FBTyxXQUFXLElBQUk7QUFDMUMsVUFBTSxVQUFVLFlBQVksV0FBVyxJQUFJO0FBQzNDLFVBQU0sYUFBYSxZQUFZLFdBQVcsT0FBTztBQUNqRCxVQUFNLGFBQWEsV0FBVztBQUM5QixVQUFNLFdBQVcsV0FBVyxDQUFDO0FBQzdCLFVBQU0sY0FBYyxDQUFDLFdBQVc7QUFDaEMsVUFBTSxpQkFBaUIsdUJBQXVCLFdBQVcsSUFBSTtBQUM3RCxVQUFNLHVCQUF1QixzQkFBc0IsUUFBUSxXQUFXLE9BQU87QUFDN0UsUUFBSTtBQUNBLGFBQU87QUFDWCxRQUFJO0FBQ0EsYUFBTztBQUNYLFFBQUksbUJBQW1CLHNCQUFzQjtBQUN6QyxZQUFNLGVBQWUsYUFBYSxHQUFHLFVBQVUsSUFBSSxLQUFLLEtBQUs7QUFDN0QsWUFBTSxJQUFJLE1BQU0sdURBQXVELFlBQVksa0NBQWtDLGNBQWMscUNBQXFDLFdBQVcsT0FBTyxpQkFBaUIsb0JBQW9CLElBQUk7QUFBQSxJQUN2TztBQUNBLFFBQUk7QUFDQSxhQUFPO0FBQUEsRUFDZjtBQUNBLFdBQVMseUJBQXlCLFNBQVM7QUFDdkMsVUFBTSxFQUFFLFlBQVksT0FBTyxlQUFlLElBQUk7QUFDOUMsVUFBTSxhQUFhLEVBQUUsWUFBWSxPQUFPLFlBQVksZUFBZTtBQUNuRSxVQUFNLGlCQUFpQixxQkFBcUIsVUFBVTtBQUN0RCxVQUFNLHVCQUF1QixzQkFBc0IsY0FBYztBQUNqRSxVQUFNLG1CQUFtQix1QkFBdUIsY0FBYztBQUM5RCxVQUFNLE9BQU8sa0JBQWtCLHdCQUF3QjtBQUN2RCxRQUFJO0FBQ0EsYUFBTztBQUNYLFVBQU0sZUFBZSxhQUFhLEdBQUcsVUFBVSxJQUFJLGNBQWMsS0FBSztBQUN0RSxVQUFNLElBQUksTUFBTSx1QkFBdUIsWUFBWSxVQUFVLEtBQUssU0FBUztBQUFBLEVBQy9FO0FBQ0EsV0FBUywwQkFBMEIsZ0JBQWdCO0FBQy9DLFVBQU0sV0FBVyx1QkFBdUIsY0FBYztBQUN0RCxRQUFJO0FBQ0EsYUFBTyxvQkFBb0IsUUFBUTtBQUN2QyxVQUFNLGFBQWEsWUFBWSxnQkFBZ0IsU0FBUztBQUN4RCxVQUFNLFVBQVUsWUFBWSxnQkFBZ0IsTUFBTTtBQUNsRCxVQUFNLGFBQWE7QUFDbkIsUUFBSTtBQUNBLGFBQU8sV0FBVztBQUN0QixRQUFJLFNBQVM7QUFDVCxZQUFNLEVBQUUsS0FBSyxJQUFJO0FBQ2pCLFlBQU0sbUJBQW1CLHVCQUF1QixJQUFJO0FBQ3BELFVBQUk7QUFDQSxlQUFPLG9CQUFvQixnQkFBZ0I7QUFBQSxJQUNuRDtBQUNBLFdBQU87QUFBQSxFQUNYO0FBQ0EsV0FBUyx5Q0FBeUMsU0FBUztBQUN2RCxVQUFNLEVBQUUsT0FBTyxlQUFlLElBQUk7QUFDbEMsVUFBTSxNQUFNLEdBQUcsVUFBVSxLQUFLLENBQUM7QUFDL0IsVUFBTSxPQUFPLHlCQUF5QixPQUFPO0FBQzdDLFdBQU87QUFBQSxNQUNIO0FBQUEsTUFDQTtBQUFBLE1BQ0EsTUFBTSxTQUFTLEdBQUc7QUFBQSxNQUNsQixJQUFJLGVBQWU7QUFDZixlQUFPLDBCQUEwQixjQUFjO0FBQUEsTUFDbkQ7QUFBQSxNQUNBLElBQUksd0JBQXdCO0FBQ3hCLGVBQU8sc0JBQXNCLGNBQWMsTUFBTTtBQUFBLE1BQ3JEO0FBQUEsTUFDQSxRQUFRLFFBQVEsSUFBSTtBQUFBLE1BQ3BCLFFBQVEsUUFBUSxJQUFJLEtBQUssUUFBUTtBQUFBLElBQ3JDO0FBQUEsRUFDSjtBQUNBLE1BQU0sc0JBQXNCO0FBQUEsSUFDeEIsSUFBSSxRQUFRO0FBQ1IsYUFBTyxDQUFDO0FBQUEsSUFDWjtBQUFBLElBQ0EsU0FBUztBQUFBLElBQ1QsUUFBUTtBQUFBLElBQ1IsSUFBSSxTQUFTO0FBQ1QsYUFBTyxDQUFDO0FBQUEsSUFDWjtBQUFBLElBQ0EsUUFBUTtBQUFBLEVBQ1o7QUFDQSxNQUFNLFVBQVU7QUFBQSxJQUNaLE1BQU0sT0FBTztBQUNULFlBQU0sUUFBUSxLQUFLLE1BQU0sS0FBSztBQUM5QixVQUFJLENBQUMsTUFBTSxRQUFRLEtBQUssR0FBRztBQUN2QixjQUFNLElBQUksVUFBVSx5REFBeUQsS0FBSyxjQUFjLHNCQUFzQixLQUFLLENBQUMsR0FBRztBQUFBLE1BQ25JO0FBQ0EsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLFFBQVEsT0FBTztBQUNYLGFBQU8sRUFBRSxTQUFTLE9BQU8sT0FBTyxLQUFLLEVBQUUsWUFBWSxLQUFLO0FBQUEsSUFDNUQ7QUFBQSxJQUNBLE9BQU8sT0FBTztBQUNWLGFBQU8sT0FBTyxNQUFNLFFBQVEsTUFBTSxFQUFFLENBQUM7QUFBQSxJQUN6QztBQUFBLElBQ0EsT0FBTyxPQUFPO0FBQ1YsWUFBTSxTQUFTLEtBQUssTUFBTSxLQUFLO0FBQy9CLFVBQUksV0FBVyxRQUFRLE9BQU8sVUFBVSxZQUFZLE1BQU0sUUFBUSxNQUFNLEdBQUc7QUFDdkUsY0FBTSxJQUFJLFVBQVUsMERBQTBELEtBQUssY0FBYyxzQkFBc0IsTUFBTSxDQUFDLEdBQUc7QUFBQSxNQUNySTtBQUNBLGFBQU87QUFBQSxJQUNYO0FBQUEsSUFDQSxPQUFPLE9BQU87QUFDVixhQUFPO0FBQUEsSUFDWDtBQUFBLEVBQ0o7QUFDQSxNQUFNLFVBQVU7QUFBQSxJQUNaLFNBQVM7QUFBQSxJQUNULE9BQU87QUFBQSxJQUNQLFFBQVE7QUFBQSxFQUNaO0FBQ0EsV0FBUyxVQUFVLE9BQU87QUFDdEIsV0FBTyxLQUFLLFVBQVUsS0FBSztBQUFBLEVBQy9CO0FBQ0EsV0FBUyxZQUFZLE9BQU87QUFDeEIsV0FBTyxHQUFHLEtBQUs7QUFBQSxFQUNuQjtBQUVBLE1BQU0sYUFBTixNQUFpQjtBQUFBLElBQ2IsWUFBWSxTQUFTO0FBQ2pCLFdBQUssVUFBVTtBQUFBLElBQ25CO0FBQUEsSUFDQSxXQUFXLGFBQWE7QUFDcEIsYUFBTztBQUFBLElBQ1g7QUFBQSxJQUNBLE9BQU8sVUFBVSxhQUFhLGNBQWM7QUFDeEM7QUFBQSxJQUNKO0FBQUEsSUFDQSxJQUFJLGNBQWM7QUFDZCxhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxJQUFJLFFBQVE7QUFDUixhQUFPLEtBQUssUUFBUTtBQUFBLElBQ3hCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLGFBQWE7QUFDYixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLFVBQVU7QUFDVixhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxJQUFJLE9BQU87QUFDUCxhQUFPLEtBQUssTUFBTTtBQUFBLElBQ3RCO0FBQUEsSUFDQSxhQUFhO0FBQUEsSUFDYjtBQUFBLElBQ0EsVUFBVTtBQUFBLElBQ1Y7QUFBQSxJQUNBLGFBQWE7QUFBQSxJQUNiO0FBQUEsSUFDQSxTQUFTLFdBQVcsRUFBRSxTQUFTLEtBQUssU0FBUyxTQUFTLENBQUMsR0FBRyxTQUFTLEtBQUssWUFBWSxVQUFVLE1BQU0sYUFBYSxLQUFNLElBQUksQ0FBQyxHQUFHO0FBQzNILFlBQU0sT0FBTyxTQUFTLEdBQUcsTUFBTSxJQUFJLFNBQVMsS0FBSztBQUNqRCxZQUFNLFFBQVEsSUFBSSxZQUFZLE1BQU0sRUFBRSxRQUFRLFNBQVMsV0FBVyxDQUFDO0FBQ25FLGFBQU8sY0FBYyxLQUFLO0FBQzFCLGFBQU87QUFBQSxJQUNYO0FBQUEsRUFDSjtBQUNBLGFBQVcsWUFBWTtBQUFBLElBQ25CO0FBQUEsSUFDQTtBQUFBLElBQ0E7QUFBQSxJQUNBO0FBQUEsRUFDSjtBQUNBLGFBQVcsVUFBVSxDQUFDO0FBQ3RCLGFBQVcsVUFBVSxDQUFDO0FBQ3RCLGFBQVcsU0FBUyxDQUFDOzs7QUMvK0VyQixNQUFPLGtDQUFQLGNBQTZCLFdBQVc7QUFBQSxJQVF0QyxVQUFVO0FBekJaLFVBQUFJLEtBQUE7QUEwQkksY0FBUSxJQUFJLHFDQUFxQztBQUFBLFFBQy9DLG1CQUFtQixDQUFDLENBQUMsS0FBSztBQUFBLFFBQzFCLGdCQUFnQixLQUFLLHNCQUFzQixLQUFLLG9CQUFvQixVQUFVLEdBQUcsRUFBRSxJQUFJLFFBQVE7QUFBQSxNQUNqRyxDQUFDO0FBR0QsWUFBTSxZQUFZLEtBQUssUUFBUSxhQUFhLGlCQUFpQjtBQUM3RCxVQUFJLFdBQVc7QUFDYixnQkFBUSxJQUFJLGVBQWUsU0FBUztBQUFBLE1BQ3RDO0FBR0EsVUFBSSxDQUFDLEtBQUsscUJBQXFCO0FBQzdCLGdCQUFRLE1BQU0sdUNBQXVDO0FBQ3JELGFBQUssWUFBVSxNQUFBQSxNQUFBLE9BQU8sWUFBUCxnQkFBQUEsSUFBZ0IsU0FBaEIsbUJBQXNCLGlCQUFnQixxREFBcUQ7QUFDMUc7QUFBQSxNQUNGO0FBR0EsV0FBSyxpQkFBaUI7QUFBQSxJQUN4QjtBQUFBLElBRUEsYUFBYTtBQUVYLFVBQUksS0FBSyxnQkFBZ0I7QUFDdkIsYUFBSyxlQUFlLFFBQVE7QUFBQSxNQUM5QjtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLE1BQU0sbUJBQW1CO0FBMUQzQixVQUFBQSxLQUFBO0FBNERJLFVBQUksT0FBTyxXQUFXLGFBQWE7QUFDakMsZ0JBQVEsSUFBSSxrQ0FBa0M7QUFDOUMsY0FBTSxLQUFLLGNBQWM7QUFBQSxNQUMzQjtBQUVBLFVBQUk7QUFFRixhQUFLLFNBQVMsT0FBTyxLQUFLLG1CQUFtQjtBQUc3QyxjQUFNLGFBQWE7QUFBQSxVQUNqQixPQUFPO0FBQUEsVUFDUCxXQUFXO0FBQUEsWUFDVCxjQUFjO0FBQUEsWUFDZCxpQkFBaUI7QUFBQSxZQUNqQixXQUFXO0FBQUEsWUFDWCxZQUFZO0FBQUEsWUFDWixjQUFjO0FBQUEsVUFDaEI7QUFBQSxRQUNGO0FBRUEsYUFBSyxXQUFXLEtBQUssT0FBTyxTQUFTO0FBQUEsVUFDbkM7QUFBQSxRQUNGLENBQUM7QUFFRCxhQUFLLE9BQU8sS0FBSyxTQUFTLE9BQU8sTUFBTTtBQUN2QyxhQUFLLEtBQUssTUFBTSxlQUFlO0FBRS9CLGdCQUFRLElBQUksaURBQWlEO0FBQUEsTUFFL0QsU0FBU0MsUUFBTztBQUNkLGdCQUFRLE1BQU0sZ0NBQWdDQSxNQUFLO0FBQ25ELGFBQUssWUFBVSxNQUFBRCxNQUFBLE9BQU8sWUFBUCxnQkFBQUEsSUFBZ0IsU0FBaEIsbUJBQXNCLGdCQUFlLDZEQUE2RDtBQUFBLE1BQ25IO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxnQkFBZ0I7QUFDZCxhQUFPLElBQUksUUFBUSxDQUFDLFlBQVk7QUFDOUIsY0FBTSxjQUFjLE1BQU07QUFDeEIsY0FBSSxPQUFPLFdBQVcsYUFBYTtBQUNqQyxvQkFBUTtBQUFBLFVBQ1YsT0FBTztBQUNMLHVCQUFXLGFBQWEsR0FBRztBQUFBLFVBQzdCO0FBQUEsUUFDRjtBQUNBLG9CQUFZO0FBQUEsTUFDZCxDQUFDO0FBQUEsSUFDSDtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsY0FBYztBQUNaLFVBQUksS0FBSyxrQkFBa0I7QUFDekIsYUFBSyxjQUFjLE1BQU0sVUFBVTtBQUFBLE1BQ3JDO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxVQUFVLFNBQVM7QUFDakIsWUFBTSxXQUFXLFNBQVMsZUFBZSxnQkFBZ0I7QUFDekQsVUFBSSxZQUFZLEtBQUssdUJBQXVCO0FBQzFDLGlCQUFTLE1BQU0sVUFBVTtBQUN6QixhQUFLLG1CQUFtQixjQUFjO0FBQUEsTUFDeEM7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxZQUFZO0FBQ1YsWUFBTSxXQUFXLFNBQVMsZUFBZSxnQkFBZ0I7QUFDekQsVUFBSSxVQUFVO0FBQ1osaUJBQVMsTUFBTSxVQUFVO0FBQ3pCLFlBQUksS0FBSyx1QkFBdUI7QUFDOUIsZUFBSyxtQkFBbUIsY0FBYztBQUFBLFFBQ3hDO0FBQUEsTUFDRjtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLGNBQWM7QUFDWixVQUFJLEtBQUssa0JBQWtCO0FBQ3pCLGFBQUssY0FBYyxNQUFNLFVBQVU7QUFBQSxNQUNyQztBQUFBLElBQ0Y7QUFBQSxFQUVGO0FBMUlFLGdCQURLLGlDQUNFLFVBQVM7QUFBQSxJQUNkLGdCQUFnQjtBQUFBLElBQ2hCLGNBQWM7QUFBQSxFQUNoQjtBQUVBLGdCQU5LLGlDQU1FLFdBQVUsQ0FBQyxnQkFBZ0IsU0FBUzs7O0FDSjdDLE1BQU8sa0NBQVAsY0FBNkIsV0FBVztBQUFBO0FBQUE7QUFBQTtBQUFBLElBV3RDLFVBQVU7QUFDUixjQUFRLElBQUksbUNBQW1DO0FBQy9DLGNBQVEsSUFBSSxtQkFBbUIsS0FBSyxPQUFPO0FBQUEsSUFDN0M7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLGFBQWE7QUFDWCxjQUFRLElBQUksc0NBQXNDO0FBQUEsSUFDcEQ7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsMkJBQTJCO0FBQ3pCLFlBQU0sY0FBYyxTQUFTLGVBQWUsY0FBYztBQUMxRCxVQUFJLENBQUMsYUFBYTtBQUNoQixnQkFBUSxNQUFNLHdCQUF3QjtBQUN0QyxlQUFPO0FBQUEsTUFDVDtBQUVBLFlBQU0sYUFBYSxLQUFLLFlBQVk7QUFBQSxRQUNsQztBQUFBLFFBQ0E7QUFBQSxNQUNGO0FBRUEsVUFBSSxDQUFDLFlBQVk7QUFDZixnQkFBUSxNQUFNLG1EQUFtRDtBQUNqRSxlQUFPO0FBQUEsTUFDVDtBQUVBLGNBQVEsSUFBSSxrQ0FBa0MsVUFBVTtBQUN4RCxhQUFPO0FBQUEsSUFDVDtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLE1BQU0sYUFBYSxPQUFPO0FBeEU1QixVQUFBRSxLQUFBO0FBeUVJLFlBQU0sZUFBZTtBQUVyQixjQUFRLElBQUksK0JBQStCO0FBQUEsUUFDekMsVUFBVSxLQUFLLFFBQVE7QUFBQSxRQUN2QixhQUFhLEtBQUs7QUFBQSxRQUNsQixZQUFXLG9CQUFJLEtBQUssR0FBRSxZQUFZO0FBQUEsTUFDcEMsQ0FBQztBQUVELFdBQUssWUFBWTtBQUVqQixVQUFJO0FBRUYsWUFBSSxLQUFLLHFCQUFxQixVQUFVO0FBQ3RDLGdCQUFNLEtBQUsscUJBQXFCO0FBQUEsUUFDbEMsT0FBTztBQUNMLGdCQUFNLEtBQUssb0JBQW9CO0FBQUEsUUFDakM7QUFBQSxNQUNGLFNBQVNDLFFBQU87QUFDZCxnQkFBUSxNQUFNLDJCQUEyQkEsTUFBSztBQUM5QyxhQUFLLFVBQVVBLE9BQU0sYUFBVyxNQUFBRCxNQUFBLE9BQU8sWUFBUCxnQkFBQUEsSUFBZ0IsU0FBaEIsbUJBQXNCLG1CQUFrQiwyQkFBMkI7QUFBQSxNQUNyRyxVQUFFO0FBQ0EsYUFBSyxZQUFZO0FBQUEsTUFDbkI7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU1BLE1BQU0sdUJBQXVCO0FBdEcvQixVQUFBQSxLQUFBO0FBdUdJLFVBQUksQ0FBQyxPQUFPLFFBQVE7QUFDbEIsY0FBTSxJQUFJLFFBQU0sTUFBQUEsTUFBQSxPQUFPLFlBQVAsZ0JBQUFBLElBQWdCLFNBQWhCLG1CQUFzQixrQkFBaUIsc0JBQXNCO0FBQUEsTUFDL0U7QUFHQSxVQUFJLENBQUMsS0FBSywwQkFBMEIsQ0FBQyxLQUFLLHFCQUFxQjtBQUM3RCxjQUFNLElBQUksUUFBTSxrQkFBTyxZQUFQLG1CQUFnQixTQUFoQixtQkFBc0IsdUJBQXNCLHVDQUF1QztBQUFBLE1BQ3JHO0FBRUEsWUFBTSxTQUFTLE9BQU8sS0FBSyxtQkFBbUI7QUFFOUMsV0FBSyxZQUFVLGtCQUFPLFlBQVAsbUJBQWdCLFNBQWhCLG1CQUFzQixxQkFBb0IsOEJBQThCO0FBR3ZGLFlBQU0sV0FBVyxNQUFNLE1BQU0sS0FBSyxVQUFVO0FBQUEsUUFDMUMsUUFBUTtBQUFBLFFBQ1IsU0FBUztBQUFBLFVBQ1AsZ0JBQWdCO0FBQUEsUUFDbEI7QUFBQSxRQUNBLE1BQU0sS0FBSyxVQUFVO0FBQUEsVUFDbkIsU0FBUztBQUFBO0FBQUEsUUFDWCxDQUFDO0FBQUEsUUFDRCxhQUFhO0FBQUEsTUFDZixDQUFDO0FBRUQsVUFBSSxDQUFDLFNBQVMsSUFBSTtBQUNoQixjQUFNLFlBQVksTUFBTSxTQUFTLEtBQUssRUFBRSxNQUFNLE9BQU8sQ0FBQyxFQUFFO0FBQ3hELGNBQU0sSUFBSSxNQUFNLFVBQVUsV0FBUyxrQkFBTyxZQUFQLG1CQUFnQixTQUFoQixtQkFBc0IsbUJBQWtCLG1DQUFtQztBQUFBLE1BQ2hIO0FBRUEsWUFBTSxPQUFPLE1BQU0sU0FBUyxLQUFLO0FBRWpDLFVBQUksQ0FBQyxLQUFLLElBQUk7QUFDWixjQUFNLElBQUksUUFBTSxrQkFBTyxZQUFQLG1CQUFnQixTQUFoQixtQkFBc0Isb0JBQW1CLG1DQUFtQztBQUFBLE1BQzlGO0FBRUEsY0FBUSxJQUFJLDZCQUE2QixLQUFLLElBQUksUUFBUSxLQUFLLEdBQUc7QUFDbEUsY0FBUSxJQUFJLGVBQWUsS0FBSyxNQUFNO0FBR3RDLFVBQUksS0FBSyxLQUFLO0FBQ1osZUFBTyxTQUFTLE9BQU8sS0FBSztBQUM1QjtBQUFBLE1BQ0Y7QUFHQSxZQUFNLEVBQUUsT0FBQUMsT0FBTSxJQUFJLE1BQU0sT0FBTyxtQkFBbUI7QUFBQSxRQUNoRCxXQUFXLEtBQUs7QUFBQSxNQUNsQixDQUFDO0FBRUQsVUFBSUEsUUFBTztBQUNULGNBQU1BO0FBQUEsTUFDUjtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsTUFBTSxzQkFBc0I7QUFsSzlCLFVBQUFELEtBQUE7QUFvS0ksWUFBTSx3QkFBd0IsS0FBSyx5QkFBeUI7QUFFNUQsVUFBSSxDQUFDLHVCQUF1QjtBQUMxQixjQUFNLElBQUksUUFBTSxNQUFBQSxNQUFBLE9BQU8sWUFBUCxnQkFBQUEsSUFBZ0IsU0FBaEIsbUJBQXNCLHlCQUF3QiwrREFBK0Q7QUFBQSxNQUMvSDtBQUdBLFVBQUksQ0FBQyxzQkFBc0IsUUFBUSxDQUFDLHNCQUFzQixRQUFRO0FBQ2hFLGdCQUFRLE1BQU0sMkJBQTJCO0FBQUEsVUFDdkMsU0FBUyxDQUFDLENBQUMsc0JBQXNCO0FBQUEsVUFDakMsV0FBVyxDQUFDLENBQUMsc0JBQXNCO0FBQUEsUUFDckMsQ0FBQztBQUNELGNBQU0sSUFBSSxRQUFNLGtCQUFPLFlBQVAsbUJBQWdCLFNBQWhCLG1CQUFzQixtQkFBa0Isd0RBQXdEO0FBQUEsTUFDbEg7QUFFQSxjQUFRLElBQUksNEJBQTRCO0FBQUEsUUFDdEMsU0FBUyxDQUFDLENBQUMsc0JBQXNCO0FBQUEsUUFDakMsV0FBVyxDQUFDLENBQUMsc0JBQXNCO0FBQUEsTUFDckMsQ0FBQztBQUVELFlBQU0sd0JBQXdCLE1BQU0sS0FBSyxjQUFjO0FBQ3ZELFlBQU0sZUFBZSxzQkFBc0I7QUFFM0MsWUFBTSx5QkFBeUIsTUFBTSxzQkFBc0IsT0FBTyxtQkFBbUIsY0FBYztBQUFBLFFBQ2pHLGdCQUFnQjtBQUFBLFVBQ2QsTUFBTSxzQkFBc0I7QUFBQSxRQUM5QjtBQUFBLE1BQ0YsQ0FBQztBQUVELFVBQUksdUJBQXVCLE9BQU87QUFDaEMsY0FBTSxJQUFJLE1BQU0sdUJBQXVCLE1BQU0sT0FBTztBQUFBLE1BQ3RELFdBQVcsdUJBQXVCLGlCQUFpQix1QkFBdUIsY0FBYyxXQUFXLGFBQWE7QUFDOUcsZ0JBQVEsSUFBSSxxQkFBcUIsdUJBQXVCLGFBQWE7QUFBQSxNQUV2RSxPQUFPO0FBQ0wsY0FBTSxJQUFJLFFBQU0sa0JBQU8sWUFBUCxtQkFBZ0IsU0FBaEIsbUJBQXNCLDBCQUF5Qix1QkFBdUI7QUFBQSxNQUN4RjtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFPQSxNQUFNLGdCQUFnQjtBQWhOeEIsVUFBQUEsS0FBQTtBQWlOSSxVQUFJLENBQUMsS0FBSyxhQUFhO0FBQ3JCLGNBQU0sSUFBSSxRQUFNLE1BQUFBLE1BQUEsT0FBTyxZQUFQLGdCQUFBQSxJQUFnQixTQUFoQixtQkFBc0IsdUJBQXNCLCtCQUErQjtBQUFBLE1BQzdGO0FBRUEsY0FBUSxJQUFJLG9DQUFvQyxLQUFLLFFBQVE7QUFFN0QsWUFBTSxXQUFXLE1BQU0sTUFBTSxLQUFLLFVBQVU7QUFBQSxRQUMxQyxRQUFRO0FBQUEsUUFDUixTQUFTO0FBQUEsVUFDUCxnQkFBZ0I7QUFBQSxRQUNsQjtBQUFBLFFBQ0EsYUFBYTtBQUFBLE1BQ2YsQ0FBQztBQUVELFVBQUksQ0FBQyxTQUFTLElBQUk7QUFDaEIsY0FBTSxJQUFJLE1BQU0sdUJBQXVCLFNBQVMsTUFBTSxFQUFFO0FBQUEsTUFDMUQ7QUFFQSxZQUFNLGVBQWUsTUFBTSxTQUFTLEtBQUs7QUFFekMsVUFBSSxhQUFhLE9BQU87QUFDdEIsY0FBTSxJQUFJLE1BQU0sYUFBYSxLQUFLO0FBQUEsTUFDcEM7QUFFQSxVQUFJLENBQUMsYUFBYSxXQUFXLENBQUMsYUFBYSxjQUFjO0FBQ3ZELGNBQU0sSUFBSSxRQUFNLGtCQUFPLFlBQVAsbUJBQWdCLFNBQWhCLG1CQUFzQixtQkFBa0IsaUNBQWlDO0FBQUEsTUFDM0Y7QUFFQSxhQUFPO0FBQUEsSUFDVDtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsY0FBYztBQW5QaEIsVUFBQUEsS0FBQTtBQW9QSSxXQUFLLFFBQVEsV0FBVztBQUN4QixXQUFLLGVBQWUsS0FBSyxRQUFRO0FBQ2pDLFdBQUssUUFBUSxnQkFBYyxNQUFBQSxNQUFBLE9BQU8sWUFBUCxnQkFBQUEsSUFBZ0IsU0FBaEIsbUJBQXNCLGVBQWM7QUFBQSxJQUNqRTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsY0FBYztBQUNaLFdBQUssUUFBUSxXQUFXO0FBQ3hCLFVBQUksS0FBSyxjQUFjO0FBQ3JCLGFBQUssUUFBUSxjQUFjLEtBQUs7QUFBQSxNQUNsQztBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsVUFBVSxTQUFTO0FBQ2pCLFVBQUksS0FBSyxpQkFBaUI7QUFDeEIsYUFBSyxhQUFhLGNBQWM7QUFDaEMsYUFBSyxhQUFhLFlBQVk7QUFBQSxNQUNoQztBQUNBLGNBQVEsSUFBSSxXQUFXLE9BQU87QUFBQSxJQUNoQztBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxVQUFVLFNBQVM7QUFDakIsVUFBSSxLQUFLLGlCQUFpQjtBQUN4QixhQUFLLGFBQWEsY0FBYztBQUNoQyxhQUFLLGFBQWEsWUFBWTtBQUFBLE1BQ2hDLE9BQU87QUFDTCxjQUFNLFlBQVksT0FBTztBQUFBLE1BQzNCO0FBQUEsSUFDRjtBQUFBLEVBQ0Y7QUF2UUUsZ0JBREssaUNBQ0UsV0FBVSxDQUFDLFFBQVE7QUFDMUIsZ0JBRkssaUNBRUUsVUFBUztBQUFBLElBQ2QsS0FBSztBQUFBLElBQ0wsYUFBYTtBQUFBLElBQ2IsZ0JBQWdCO0FBQUEsRUFDbEI7OztBQ1hGLE1BQU8sb0NBQVAsY0FBNkIsV0FBVztBQUFBO0FBQUE7QUFBQTtBQUFBLElBU3RDLFVBQVU7QUFDUixjQUFRLElBQUksdUNBQXVDO0FBQUEsUUFDakQsU0FBUyxLQUFLO0FBQUEsUUFDZCxhQUFhLEtBQUs7QUFBQSxRQUNsQixrQkFBa0IsS0FBSztBQUFBLE1BQ3pCLENBQUM7QUFHRCxVQUFJLEtBQUssY0FBYztBQUNyQixhQUFLLG1CQUFtQjtBQUFBLE1BQzFCO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0Esa0JBQWtCO0FBQ2hCLFVBQUksS0FBSyxjQUFjO0FBQ3JCLGFBQUssbUJBQW1CO0FBQUEsTUFDMUI7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxxQkFBcUI7QUFDbkIsVUFBSSxDQUFDLEtBQUsscUJBQXFCLENBQUMsS0FBSyx1QkFBdUI7QUFDMUQ7QUFBQSxNQUNGO0FBRUEsWUFBTSxZQUFZLEtBQUssZUFBZTtBQUd0QyxXQUFLLG9CQUFvQixRQUFRLFlBQVU7QUF4RC9DLFlBQUFFLEtBQUE7QUF5RE0sZUFBTyxXQUFXLENBQUM7QUFHbkIsWUFBSSxXQUFXO0FBQ2IsaUJBQU8sVUFBVSxPQUFPLFVBQVU7QUFDbEMsaUJBQU8sZ0JBQWdCLE9BQU87QUFBQSxRQUNoQyxPQUFPO0FBQ0wsaUJBQU8sVUFBVSxJQUFJLFVBQVU7QUFDL0IsaUJBQU8sYUFBYSxXQUFTLE1BQUFBLE1BQUEsT0FBTyxZQUFQLGdCQUFBQSxJQUFnQixTQUFoQixtQkFBc0IsaUJBQWdCLHdDQUF3QztBQUFBLFFBQzdHO0FBQUEsTUFDRixDQUFDO0FBQUEsSUFDSDtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxhQUFhLE9BQU87QUFDbEIsVUFBSSxDQUFDLEtBQUssY0FBYztBQUN0QixlQUFPO0FBQUEsTUFDVDtBQUVBLFVBQUksQ0FBQyxLQUFLLHFCQUFxQixDQUFDLEtBQUssZUFBZSxTQUFTO0FBQzNELGNBQU0sZUFBZTtBQUNyQixjQUFNLGdCQUFnQjtBQUd0QixZQUFJLEtBQUssbUJBQW1CO0FBQzFCLGdCQUFNLGtCQUFrQixLQUFLLGVBQWUsUUFBUSxhQUFhO0FBQ2pFLGNBQUksaUJBQWlCO0FBQ25CLDRCQUFnQixVQUFVLElBQUksVUFBVSxpQkFBaUIsT0FBTyxTQUFTO0FBR3pFLHVCQUFXLE1BQU07QUFDZiw4QkFBZ0IsVUFBVSxPQUFPLFVBQVUsaUJBQWlCLE9BQU8sU0FBUztBQUFBLFlBQzlFLEdBQUcsR0FBSTtBQUFBLFVBQ1Q7QUFBQSxRQUNGO0FBRUEsZUFBTztBQUFBLE1BQ1Q7QUFFQSxhQUFPO0FBQUEsSUFDVDtBQUFBLEVBQ0Y7QUF0RkUsZ0JBREssbUNBQ0UsV0FBVSxDQUFDLFlBQVksY0FBYztBQUM1QyxnQkFGSyxtQ0FFRSxVQUFTO0FBQUEsSUFDZCxTQUFTO0FBQUEsRUFDWDs7O0FDbUJGLE1BQU0sV0FBTixNQUFNLFVBQVM7QUFBQSxJQUNiLGNBQWM7QUFFWixVQUFJLFVBQVMsVUFBVTtBQUNyQixlQUFPLFVBQVM7QUFBQSxNQUNsQjtBQUVBLFdBQUssWUFBWSxvQkFBSSxJQUFJO0FBQ3pCLFdBQUssUUFBUTtBQUNiLFdBQUssZUFBZSxDQUFDO0FBQ3JCLFdBQUssaUJBQWlCO0FBRXRCLGdCQUFTLFdBQVc7QUFBQSxJQUN0QjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsU0FBUyxTQUFTO0FBQ2hCLFdBQUssUUFBUTtBQUFBLElBQ2Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQVFBLEdBQUcsV0FBVyxTQUFTO0FBQ3JCLFVBQUksQ0FBQyxLQUFLLFVBQVUsSUFBSSxTQUFTLEdBQUc7QUFDbEMsYUFBSyxVQUFVLElBQUksV0FBVyxvQkFBSSxJQUFJLENBQUM7QUFBQSxNQUN6QztBQUVBLFdBQUssVUFBVSxJQUFJLFNBQVMsRUFBRSxJQUFJLE9BQU87QUFFekMsVUFBSSxLQUFLLE9BQU87QUFDZCxnQkFBUSxJQUFJLHVDQUF1QyxTQUFTLEtBQUs7QUFBQSxVQUMvRCxnQkFBZ0IsS0FBSyxVQUFVLElBQUksU0FBUyxFQUFFO0FBQUEsUUFDaEQsQ0FBQztBQUFBLE1BQ0g7QUFHQSxhQUFPLE1BQU0sS0FBSyxJQUFJLFdBQVcsT0FBTztBQUFBLElBQzFDO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFRQSxLQUFLLFdBQVcsU0FBUztBQUN2QixZQUFNLGNBQWMsQ0FBQyxTQUFTO0FBQzVCLGdCQUFRLElBQUk7QUFDWixhQUFLLElBQUksV0FBVyxXQUFXO0FBQUEsTUFDakM7QUFFQSxhQUFPLEtBQUssR0FBRyxXQUFXLFdBQVc7QUFBQSxJQUN2QztBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLElBQUksV0FBVyxTQUFTO0FBQ3RCLFVBQUksQ0FBQyxLQUFLLFVBQVUsSUFBSSxTQUFTLEdBQUc7QUFDbEM7QUFBQSxNQUNGO0FBRUEsWUFBTSxXQUFXLEtBQUssVUFBVSxJQUFJLFNBQVM7QUFDN0MsZUFBUyxPQUFPLE9BQU87QUFHdkIsVUFBSSxTQUFTLFNBQVMsR0FBRztBQUN2QixhQUFLLFVBQVUsT0FBTyxTQUFTO0FBQUEsTUFDakM7QUFFQSxVQUFJLEtBQUssT0FBTztBQUNkLGdCQUFRLElBQUksb0NBQW9DLFNBQVMsS0FBSztBQUFBLFVBQzVELGdCQUFnQixTQUFTO0FBQUEsUUFDM0IsQ0FBQztBQUFBLE1BQ0g7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU1BLE9BQU8sV0FBVztBQUNoQixVQUFJLEtBQUssVUFBVSxJQUFJLFNBQVMsR0FBRztBQUNqQyxhQUFLLFVBQVUsT0FBTyxTQUFTO0FBRS9CLFlBQUksS0FBSyxPQUFPO0FBQ2Qsa0JBQVEsSUFBSSx5Q0FBeUMsU0FBUyxHQUFHO0FBQUEsUUFDbkU7QUFBQSxNQUNGO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLEtBQUssV0FBVyxPQUFPLENBQUMsR0FBRztBQUN6QixZQUFNLFlBQVksS0FBSyxJQUFJO0FBRzNCLFdBQUssYUFBYSxLQUFLO0FBQUEsUUFDckI7QUFBQSxRQUNBO0FBQUEsUUFDQTtBQUFBLFFBQ0EsZ0JBQWdCLEtBQUssVUFBVSxJQUFJLFNBQVMsSUFBSSxLQUFLLFVBQVUsSUFBSSxTQUFTLEVBQUUsT0FBTztBQUFBLE1BQ3ZGLENBQUM7QUFHRCxVQUFJLEtBQUssYUFBYSxTQUFTLEtBQUssZ0JBQWdCO0FBQ2xELGFBQUssYUFBYSxNQUFNO0FBQUEsTUFDMUI7QUFFQSxVQUFJLEtBQUssT0FBTztBQUNkLGdCQUFRLElBQUksOEJBQThCLFNBQVMsS0FBSztBQUFBLFVBQ3REO0FBQUEsVUFDQSxnQkFBZ0IsS0FBSyxVQUFVLElBQUksU0FBUyxJQUFJLEtBQUssVUFBVSxJQUFJLFNBQVMsRUFBRSxPQUFPO0FBQUEsVUFDckYsV0FBVyxJQUFJLEtBQUssU0FBUyxFQUFFLFlBQVk7QUFBQSxRQUM3QyxDQUFDO0FBQUEsTUFDSDtBQUdBLFVBQUksS0FBSyxVQUFVLElBQUksU0FBUyxHQUFHO0FBQ2pDLGNBQU0sV0FBVyxNQUFNLEtBQUssS0FBSyxVQUFVLElBQUksU0FBUyxDQUFDO0FBRXpELGlCQUFTLFFBQVEsYUFBVztBQUMxQixjQUFJO0FBQ0Ysb0JBQVEsSUFBSTtBQUFBLFVBQ2QsU0FBU0MsUUFBTztBQUNkLG9CQUFRLE1BQU0sb0NBQW9DLFNBQVMsTUFBTUEsTUFBSztBQUFBLFVBRXhFO0FBQUEsUUFDRixDQUFDO0FBQUEsTUFDSCxXQUFXLEtBQUssT0FBTztBQUNyQixnQkFBUSxLQUFLLGdDQUFnQyxTQUFTLEdBQUc7QUFBQSxNQUMzRDtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBU0EsTUFBTSxVQUFVLFdBQVcsT0FBTyxDQUFDLEdBQUc7QUFDcEMsYUFBTyxJQUFJLFFBQVEsQ0FBQyxZQUFZO0FBQzlCLG1CQUFXLE1BQU07QUFDZixlQUFLLEtBQUssV0FBVyxJQUFJO0FBQ3pCLGtCQUFRO0FBQUEsUUFDVixHQUFHLENBQUM7QUFBQSxNQUNOLENBQUM7QUFBQSxJQUNIO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQVNBLFlBQVksV0FBVyxPQUFPLENBQUMsR0FBRyxRQUFRLEdBQUc7QUFDM0MsYUFBTyxXQUFXLE1BQU07QUFDdEIsYUFBSyxLQUFLLFdBQVcsSUFBSTtBQUFBLE1BQzNCLEdBQUcsS0FBSztBQUFBLElBQ1Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBU0EsUUFBUSxXQUFXLFVBQVUsS0FBTTtBQUNqQyxhQUFPLElBQUksUUFBUSxDQUFDLFNBQVMsV0FBVztBQUN0QyxjQUFNLFFBQVEsVUFBVSxJQUFJLFdBQVcsTUFBTTtBQUMzQyxlQUFLLElBQUksV0FBVyxPQUFPO0FBQzNCLGlCQUFPLElBQUksTUFBTSx5Q0FBeUMsU0FBUyxHQUFHLENBQUM7QUFBQSxRQUN6RSxHQUFHLE9BQU8sSUFBSTtBQUVkLGNBQU0sVUFBVSxDQUFDLFNBQVM7QUFDeEIsY0FBSTtBQUFPLHlCQUFhLEtBQUs7QUFDN0Isa0JBQVEsSUFBSTtBQUFBLFFBQ2Q7QUFFQSxhQUFLLEtBQUssV0FBVyxPQUFPO0FBQUEsTUFDOUIsQ0FBQztBQUFBLElBQ0g7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFPQSxhQUFhLFdBQVc7QUFDdEIsYUFBTyxLQUFLLFVBQVUsSUFBSSxTQUFTLEtBQUssS0FBSyxVQUFVLElBQUksU0FBUyxFQUFFLE9BQU87QUFBQSxJQUMvRTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLGtCQUFrQixXQUFXO0FBQzNCLGFBQU8sS0FBSyxVQUFVLElBQUksU0FBUyxJQUFJLEtBQUssVUFBVSxJQUFJLFNBQVMsRUFBRSxPQUFPO0FBQUEsSUFDOUU7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsc0JBQXNCO0FBQ3BCLGFBQU8sTUFBTSxLQUFLLEtBQUssVUFBVSxLQUFLLENBQUM7QUFBQSxJQUN6QztBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLGdCQUFnQixRQUFRLElBQUk7QUFDMUIsYUFBTyxLQUFLLGFBQWEsTUFBTSxDQUFDLEtBQUs7QUFBQSxJQUN2QztBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsZUFBZTtBQUNiLFdBQUssZUFBZSxDQUFDO0FBQUEsSUFDdkI7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLFdBQVc7QUFDVCxXQUFLLFVBQVUsTUFBTTtBQUVyQixVQUFJLEtBQUssT0FBTztBQUNkLGdCQUFRLElBQUksa0NBQWtDO0FBQUEsTUFDaEQ7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxhQUFhO0FBQ1gsY0FBUSxNQUFNLHVCQUF1QjtBQUNyQyxjQUFRLElBQUksc0JBQXNCLEtBQUssb0JBQW9CLENBQUM7QUFDNUQsY0FBUSxJQUFJLG9CQUFvQixNQUFNLEtBQUssS0FBSyxVQUFVLE9BQU8sQ0FBQyxFQUFFLE9BQU8sQ0FBQyxLQUFLLFFBQVEsTUFBTSxJQUFJLE1BQU0sQ0FBQyxDQUFDO0FBQzNHLGNBQVEsSUFBSSx1QkFBdUIsS0FBSyxhQUFhLE1BQU07QUFFM0QsY0FBUSxNQUFNLHNCQUFzQjtBQUNwQyxXQUFLLFVBQVUsUUFBUSxDQUFDLFVBQVUsY0FBYztBQUM5QyxnQkFBUSxJQUFJLEtBQUssU0FBUyxLQUFLLFNBQVMsSUFBSSxFQUFFO0FBQUEsTUFDaEQsQ0FBQztBQUNELGNBQVEsU0FBUztBQUVqQixjQUFRLE1BQU0sZ0JBQWdCO0FBQzlCLFdBQUssZ0JBQWdCLEVBQUUsRUFBRSxRQUFRLFdBQVM7QUFDeEMsZ0JBQVEsSUFBSSxLQUFLLE1BQU0sU0FBUyxLQUFLLE1BQU0sY0FBYyxpQkFBaUIsSUFBSSxLQUFLLE1BQU0sU0FBUyxFQUFFLG1CQUFtQixDQUFDLEVBQUU7QUFBQSxNQUM1SCxDQUFDO0FBQ0QsY0FBUSxTQUFTO0FBRWpCLGNBQVEsU0FBUztBQUFBLElBQ25CO0FBQUEsRUFDRjtBQUtBLE1BQUk7QUEzVEo7QUE2VEEsTUFBSSxPQUFPLFdBQVcsZUFBZSxPQUFPLFVBQVU7QUFFcEQsWUFBUSxJQUFJLHVFQUF1RTtBQUNuRixlQUFXLE9BQU87QUFBQSxFQUNwQixPQUFPO0FBRUwsWUFBUSxJQUFJLGtEQUFrRDtBQUM5RCxlQUFXLElBQUksU0FBUztBQUd4QixRQUFJLE9BQU8sV0FBVyxpQkFBZSxZQUFPLGFBQVAsbUJBQWlCLGNBQWEsYUFBYTtBQUM5RSxlQUFTLFNBQVMsSUFBSTtBQUFBLElBQ3hCO0FBR0EsUUFBSSxPQUFPLFdBQVcsYUFBYTtBQUNqQyxhQUFPLFdBQVc7QUFBQSxJQUNwQjtBQUFBLEVBQ0Y7OztBQ3JTTyxXQUFTLGFBQWEsZ0JBQWdCO0FBQzNDLFdBQU8sY0FBYyxlQUFlO0FBQUEsTUFDbEMsZUFBZSxNQUFNO0FBQ25CLGNBQU0sR0FBRyxJQUFJO0FBR2IsYUFBSyxxQkFBcUIsQ0FBQztBQUFBLE1BQzdCO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxNQVlBLE9BQU8sV0FBVyxTQUFTLFVBQVUsQ0FBQyxHQUFHO0FBQ3ZDLGNBQU0sRUFBRSxPQUFPLE1BQU0sSUFBSTtBQUd6QixjQUFNLGVBQWUsUUFBUSxLQUFLLElBQUk7QUFHdEMsY0FBTSxpQkFBaUIsS0FBSyxjQUFjLEtBQUssWUFBWTtBQUMzRCxjQUFNLGVBQWUsQ0FBQyxTQUFTO0FBQzdCLGNBQUksU0FBUyxPQUFPO0FBQ2xCLG9CQUFRLElBQUksSUFBSSxjQUFjLHFCQUFxQixTQUFTLEtBQUssSUFBSTtBQUFBLFVBQ3ZFO0FBQ0EsdUJBQWEsSUFBSTtBQUFBLFFBQ25CO0FBR0EsY0FBTSxpQkFBaUIsT0FDbkIsU0FBUyxLQUFLLFdBQVcsWUFBWSxJQUNyQyxTQUFTLEdBQUcsV0FBVyxZQUFZO0FBR3ZDLGFBQUssbUJBQW1CLEtBQUssRUFBRSxXQUFXLFNBQVMsY0FBYyxlQUFlLENBQUM7QUFHakYsZUFBTztBQUFBLE1BQ1Q7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsTUFVQSxXQUFXLFdBQVcsU0FBUztBQUM3QixlQUFPLEtBQUssT0FBTyxXQUFXLFNBQVMsRUFBRSxNQUFNLEtBQUssQ0FBQztBQUFBLE1BQ3ZEO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsTUFRQSxVQUFVLFdBQVcsT0FBTyxDQUFDLEdBQUc7QUFDOUIsY0FBTSxpQkFBaUIsS0FBSyxjQUFjLEtBQUssWUFBWTtBQUUzRCxZQUFJLFNBQVMsT0FBTztBQUNsQixrQkFBUSxJQUFJLElBQUksY0FBYyx5QkFBeUIsU0FBUyxLQUFLLElBQUk7QUFBQSxRQUMzRTtBQUVBLGlCQUFTLEtBQUssV0FBVyxJQUFJO0FBQUEsTUFDL0I7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLE1BU0EsTUFBTSxlQUFlLFdBQVcsT0FBTyxDQUFDLEdBQUc7QUFDekMsZUFBTyxTQUFTLFVBQVUsV0FBVyxJQUFJO0FBQUEsTUFDM0M7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsTUFVQSxNQUFNLGFBQWEsV0FBVyxVQUFVLEtBQU07QUFDNUMsZUFBTyxTQUFTLFFBQVEsV0FBVyxPQUFPO0FBQUEsTUFDNUM7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxNQVFBLGNBQWMsV0FBVyxTQUFTO0FBQ2hDLGlCQUFTLElBQUksV0FBVyxPQUFPO0FBRy9CLGFBQUsscUJBQXFCLEtBQUssbUJBQW1CO0FBQUEsVUFDaEQsY0FBWSxFQUFFLFNBQVMsY0FBYyxhQUFhLFNBQVMsWUFBWTtBQUFBLFFBQ3pFO0FBQUEsTUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsTUFNQSxtQkFBbUI7QUFDakIsYUFBSyxtQkFBbUIsUUFBUSxDQUFDLEVBQUUsZUFBZSxNQUFNO0FBQ3RELHlCQUFlO0FBQUEsUUFDakIsQ0FBQztBQUVELGFBQUsscUJBQXFCLENBQUM7QUFFM0IsWUFBSSxTQUFTLE9BQU87QUFDbEIsZ0JBQU0saUJBQWlCLEtBQUssY0FBYyxLQUFLLFlBQVk7QUFDM0Qsa0JBQVEsSUFBSSxJQUFJLGNBQWMsa0NBQWtDO0FBQUEsUUFDbEU7QUFBQSxNQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUEsTUFLQSxhQUFhO0FBQ1gsYUFBSyxpQkFBaUI7QUFHdEIsWUFBSSxNQUFNLFlBQVk7QUFDcEIsZ0JBQU0sV0FBVztBQUFBLFFBQ25CO0FBQUEsTUFDRjtBQUFBLElBQ0Y7QUFBQSxFQUNGOzs7QUNsS0EsTUFBTyxvQ0FBUCxjQUE2QixhQUFhLFVBQVUsRUFBRTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBWXBELFVBQVU7QUFDUixjQUFRLElBQUkscUNBQXFDO0FBR2pELFdBQUssT0FBTyw4QkFBOEIsS0FBSyxxQkFBcUIsS0FBSyxJQUFJLENBQUM7QUFDOUUsV0FBSyxPQUFPLGdDQUFnQyxLQUFLLHFCQUFxQixLQUFLLElBQUksQ0FBQztBQUNoRixXQUFLLE9BQU8sNEJBQTRCLEtBQUssbUJBQW1CLEtBQUssSUFBSSxDQUFDO0FBRzFFLFdBQUssU0FBUztBQUNkLFdBQUssV0FBVztBQUNoQixXQUFLLGlCQUFpQjtBQUN0QixXQUFLLG9CQUFvQjtBQUN6QixXQUFLLGlCQUFpQjtBQUFBLElBQ3hCO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxhQUFhO0FBQ1gsY0FBUSxJQUFJLHdDQUF3QztBQUtwRCxVQUFJLEtBQUssZ0JBQWdCO0FBQ3ZCLGFBQUssZUFBZSxRQUFRO0FBQzVCLGFBQUssaUJBQWlCO0FBQUEsTUFDeEI7QUFDQSxXQUFLLFdBQVc7QUFDaEIsV0FBSyxTQUFTO0FBQUEsSUFDaEI7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFnQkEsTUFBTSxxQkFBcUIsT0FBTztBQUNoQyxZQUFNLEVBQUUsZ0JBQWdCLElBQUksTUFBTTtBQUVsQyxjQUFRLElBQUksc0RBQXNELGVBQWU7QUFFakYsVUFBSSxDQUFDLEtBQUssZUFBZSxlQUFlLEdBQUc7QUFDekMsYUFBSyxhQUFhO0FBQ2xCO0FBQUEsTUFDRjtBQUdBLFdBQUssYUFBYTtBQUdsQixVQUFJLENBQUMsS0FBSyxRQUFRO0FBQ2hCLGNBQU0sS0FBSyxjQUFjO0FBQUEsTUFDM0I7QUFHQSxZQUFNLEtBQUsseUJBQXlCO0FBQUEsSUFDdEM7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBa0JBLE1BQU0sbUJBQW1CLE9BQU87QUFDOUIsWUFBTSxFQUFFLGVBQWUsVUFBVSxZQUFZLFNBQVMsSUFBSSxNQUFNO0FBRWhFLGNBQVEsSUFBSSxvREFBb0Q7QUFBQSxRQUM5RDtBQUFBLFFBQ0E7QUFBQSxRQUNBO0FBQUEsUUFDQTtBQUFBLE1BQ0YsQ0FBQztBQUVELFVBQUksQ0FBQyxLQUFLLGVBQWUsYUFBYSxHQUFHO0FBQ3ZDO0FBQUEsTUFDRjtBQUlBLFdBQUssVUFBVSxnQ0FBZ0M7QUFBQSxRQUM3QyxpQkFBaUI7QUFBQSxRQUNqQjtBQUFBLFFBQ0E7QUFBQSxRQUNBO0FBQUEsTUFDRixDQUFDO0FBQUEsSUFDSDtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFrQkEsTUFBTSxxQkFBcUIsT0FBTztBQUNoQyxZQUFNLEVBQUUsaUJBQWlCLGNBQWMsWUFBWSxRQUFRLElBQUksTUFBTTtBQUVyRSxjQUFRLElBQUksOENBQThDO0FBQUEsUUFDeEQ7QUFBQSxRQUNBLGNBQWMsZUFBZSxRQUFRO0FBQUEsUUFDckM7QUFBQSxRQUNBO0FBQUEsTUFDRixDQUFDO0FBRUQsVUFBSSxDQUFDLEtBQUssZUFBZSxlQUFlLEdBQUc7QUFDekM7QUFBQSxNQUNGO0FBR0EsV0FBSyxvQkFBb0I7QUFDekIsV0FBSyxpQkFBaUI7QUFHdEIsV0FBSyxXQUFXO0FBQ2hCLFdBQUssVUFBVTtBQUVmLFVBQUk7QUFFRixjQUFNLFNBQVMsTUFBTSxLQUFLLGVBQWUsWUFBWTtBQUVyRCxnQkFBUSxJQUFJLGdEQUFnRCxNQUFNO0FBR2xFLGFBQUssMEJBQTBCLE1BQU07QUFBQSxNQUN2QyxTQUFTQyxRQUFPO0FBQ2QsZ0JBQVEsTUFBTSw2Q0FBNkNBLE1BQUs7QUFHaEUsYUFBSyxVQUFVQSxPQUFNLE9BQU87QUFHNUIsYUFBSyx1QkFBdUJBLE1BQUs7QUFBQSxNQUNuQyxVQUFFO0FBQ0EsYUFBSyxXQUFXO0FBQUEsTUFDbEI7QUFBQSxJQUNGO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxlQUFlLGlCQUFpQjtBQUM5QixVQUFJLENBQUMsaUJBQWlCO0FBQ3BCLGVBQU87QUFBQSxNQUNUO0FBRUEsWUFBTSx1QkFBdUI7QUFBQSxRQUMzQjtBQUFBLFFBQ0E7QUFBQSxRQUNBO0FBQUEsTUFDRjtBQUVBLGFBQU8scUJBQXFCO0FBQUEsUUFBSyxZQUMvQixnQkFBZ0IsWUFBWSxFQUFFLFNBQVMsT0FBTyxZQUFZLENBQUM7QUFBQSxNQUM3RDtBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLE1BQU0sZ0JBQWdCO0FBQ3BCLFVBQUksT0FBTyxRQUFRO0FBQ2pCLGFBQUssU0FBUyxPQUFPLE9BQU8sS0FBSyxtQkFBbUI7QUFDcEQ7QUFBQSxNQUNGO0FBRUEsY0FBUSxJQUFJLG9EQUFvRDtBQUdoRSxZQUFNLElBQUksUUFBUSxDQUFDLFNBQVMsV0FBVztBQUNyQyxjQUFNLFNBQVMsU0FBUyxjQUFjLFFBQVE7QUFDOUMsZUFBTyxNQUFNO0FBQ2IsZUFBTyxRQUFRO0FBQ2YsZUFBTyxTQUFTO0FBQ2hCLGVBQU8sVUFBVTtBQUNqQixpQkFBUyxLQUFLLFlBQVksTUFBTTtBQUFBLE1BQ2xDLENBQUM7QUFFRCxXQUFLLFNBQVMsT0FBTyxPQUFPLEtBQUssbUJBQW1CO0FBQ3BELGNBQVEsSUFBSSxnREFBZ0Q7QUFBQSxJQUM5RDtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsTUFBTSwyQkFBMkI7QUFDL0IsVUFBSSxDQUFDLEtBQUssUUFBUTtBQUNoQixnQkFBUSxNQUFNLGlEQUFpRDtBQUMvRDtBQUFBLE1BQ0Y7QUFFQSxVQUFJLEtBQUssZ0JBQWdCO0FBRXZCO0FBQUEsTUFDRjtBQUVBLGNBQVEsSUFBSSwyREFBMkQ7QUFHdkUsV0FBSyxXQUFXLEtBQUssT0FBTyxTQUFTO0FBQUEsUUFDbkMsTUFBTTtBQUFBLFFBQ04sUUFBUTtBQUFBO0FBQUEsUUFDUixVQUFVO0FBQUEsUUFDVixZQUFZO0FBQUEsVUFDVixPQUFPO0FBQUEsUUFDVDtBQUFBLE1BQ0YsQ0FBQztBQUdELFdBQUssaUJBQWlCLEtBQUssU0FBUyxPQUFPLFNBQVM7QUFDcEQsV0FBSyxlQUFlLE1BQU0sS0FBSyxhQUFhO0FBRTVDLGNBQVEsSUFBSSx1REFBdUQ7QUFBQSxJQUNyRTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBUUEsTUFBTSxlQUFlLGNBQWM7QUE1UnJDLFVBQUFDLEtBQUE7QUE2UkksVUFBSSxDQUFDLEtBQUssVUFBVSxDQUFDLEtBQUssVUFBVTtBQUNsQyxjQUFNLElBQUksTUFBTSw0QkFBNEI7QUFBQSxNQUM5QztBQUVBLGNBQVEsSUFBSSw2REFBNkQ7QUFHekUsV0FBSyxTQUFTLE9BQU87QUFBQSxRQUNuQjtBQUFBLE1BQ0YsQ0FBQztBQUdELFlBQU0sU0FBUyxNQUFNLEtBQUssT0FBTyxlQUFlO0FBQUEsUUFDOUMsVUFBVSxLQUFLO0FBQUEsUUFDZixlQUFlO0FBQUEsVUFDYixZQUFZLEtBQUssa0JBQWtCLE9BQU8sU0FBUyxTQUFTO0FBQUEsUUFDOUQ7QUFBQSxRQUNBLFVBQVU7QUFBQTtBQUFBLE1BQ1osQ0FBQztBQUdELFVBQUksT0FBTyxPQUFPO0FBQ2hCLGNBQU0sSUFBSSxNQUFNLE9BQU8sTUFBTSxXQUFXLDZCQUE2QjtBQUFBLE1BQ3ZFO0FBRUEsWUFBSUEsTUFBQSxPQUFPLGtCQUFQLGdCQUFBQSxJQUFzQixZQUFXLGFBQWE7QUFDaEQsZUFBTztBQUFBLFVBQ0wsaUJBQWlCLE9BQU8sY0FBYztBQUFBLFVBQ3RDLFFBQVEsT0FBTyxjQUFjO0FBQUEsVUFDN0IsUUFBUSxPQUFPLGNBQWM7QUFBQSxVQUM3QixVQUFVLE9BQU8sY0FBYztBQUFBLFFBQ2pDO0FBQUEsTUFDRjtBQUdBLFlBQU0sSUFBSSxNQUFNLG9DQUFrQyxZQUFPLGtCQUFQLG1CQUFzQixXQUFVLFNBQVMsRUFBRTtBQUFBLElBQy9GO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSwwQkFBMEIsZUFBZTtBQUN2QyxXQUFLLFVBQVUsd0JBQXdCO0FBQUEsUUFDckMsVUFBVTtBQUFBLFFBQ1YsWUFBWSxLQUFLO0FBQUEsUUFDakIsU0FBUyxLQUFLO0FBQUEsUUFDZCxlQUFlLGNBQWM7QUFBQSxRQUM3QixVQUFVO0FBQUEsTUFDWixDQUFDO0FBRUQsY0FBUSxJQUFJLDhEQUE4RDtBQUFBLElBQzVFO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSx1QkFBdUJELFFBQU87QUFDNUIsV0FBSyxVQUFVLHFCQUFxQjtBQUFBLFFBQ2xDLFVBQVU7QUFBQSxRQUNWLFlBQVksS0FBSztBQUFBLFFBQ2pCLFNBQVMsS0FBSztBQUFBLFFBQ2QsT0FBT0EsT0FBTSxXQUFXO0FBQUEsUUFDeEIsV0FBV0EsT0FBTSxRQUFRO0FBQUEsTUFDM0IsQ0FBQztBQUVELGNBQVEsSUFBSSwyREFBMkQ7QUFBQSxJQUN6RTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFNQSxlQUFlO0FBRWIsV0FBSyxRQUFRLE1BQU0sVUFBVTtBQUc3QixVQUFJLEtBQUssa0JBQWtCO0FBQ3pCLGFBQUssY0FBYyxNQUFNLFVBQVU7QUFBQSxNQUNyQztBQUFBLElBQ0Y7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsZUFBZTtBQUViLFdBQUssUUFBUSxNQUFNLFVBQVU7QUFBQSxJQUMvQjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsYUFBYTtBQUNYLFVBQUksS0FBSyxpQkFBaUI7QUFDeEIsYUFBSyxhQUFhLE1BQU0sVUFBVTtBQUFBLE1BQ3BDO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsYUFBYTtBQUNYLFVBQUksS0FBSyxpQkFBaUI7QUFDeEIsYUFBSyxhQUFhLE1BQU0sVUFBVTtBQUFBLE1BQ3BDO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsVUFBVSxTQUFTO0FBQ2pCLFVBQUksS0FBSyxnQkFBZ0I7QUFDdkIsYUFBSyxZQUFZLGNBQWM7QUFDL0IsYUFBSyxZQUFZLE1BQU0sVUFBVTtBQUFBLE1BQ25DO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsWUFBWTtBQUNWLFVBQUksS0FBSyxnQkFBZ0I7QUFDdkIsYUFBSyxZQUFZLE1BQU0sVUFBVTtBQUNqQyxhQUFLLFlBQVksY0FBYztBQUFBLE1BQ2pDO0FBQUEsSUFDRjtBQUFBLEVBQ0Y7QUF2WUUsZ0JBREssbUNBQ0UsVUFBUztBQUFBLElBQ2QsZ0JBQWdCO0FBQUEsSUFDaEIsTUFBTTtBQUFBLElBQ04sV0FBVztBQUFBLEVBQ2I7QUFFQSxnQkFQSyxtQ0FPRSxXQUFVLENBQUMsV0FBVyxVQUFVLE9BQU87OztBQ0doRCxNQUFPLDRDQUFQLGNBQTZCLGFBQWEsVUFBVSxFQUFFO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFtQmxELFVBQVU7QUFDTixjQUFRLElBQUksb0NBQW9DO0FBQUEsUUFDNUMsVUFBVSxLQUFLO0FBQUEsUUFDZixlQUFlLEtBQUs7QUFBQSxRQUNwQixZQUFZLEtBQUs7QUFBQSxRQUNqQixVQUFVLEtBQUs7QUFBQSxNQUNuQixDQUFDO0FBR0QsV0FBSyxvQkFBb0I7QUFHekIsV0FBSyxrQkFBa0I7QUFBQSxJQUMzQjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLHNCQUFzQjtBQUVsQixXQUFLLE9BQU8scUJBQXFCLENBQUMsU0FBUztBQUN2QyxnQkFBUSxJQUFJLHlDQUF5QyxJQUFJO0FBQ3pELGFBQUssbUJBQW1CLElBQUk7QUFBQSxNQUNoQyxDQUFDO0FBR0QsV0FBSyxPQUFPLHlCQUF5QixDQUFDLFNBQVM7QUFDM0MsZ0JBQVEsSUFBSSw2Q0FBNkMsSUFBSTtBQUM3RCxhQUFLLFdBQVc7QUFBQSxNQUNwQixDQUFDO0FBRUQsV0FBSyxPQUFPLHVCQUF1QixDQUFDLFNBQVM7QUFDekMsZ0JBQVEsSUFBSSwyQ0FBMkMsSUFBSTtBQUMzRCxhQUFLLFdBQVc7QUFDaEIsYUFBSyxZQUFZO0FBQUEsTUFDckIsQ0FBQztBQUVELFdBQUssT0FBTyxvQkFBb0IsQ0FBQyxTQUFTO0FBQ3RDLGdCQUFRLElBQUksd0NBQXdDLElBQUk7QUFDeEQsYUFBSyxXQUFXO0FBQ2hCLGFBQUssVUFBVSxLQUFLLFdBQVcsMkJBQTJCO0FBQUEsTUFDOUQsQ0FBQztBQUdELFdBQUssT0FBTyw4QkFBOEIsQ0FBQyxTQUFTO0FBQ2hELGdCQUFRLElBQUksa0RBQWtELElBQUk7QUFDbEUsYUFBSywwQkFBMEIsSUFBSTtBQUFBLE1BQ3ZDLENBQUM7QUFBQSxJQUNMO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQXNCQSxNQUFNLGVBQWUsT0FBTztBQUN4QixZQUFNLGVBQWU7QUFFckIsY0FBUSxJQUFJLG1FQUFtRTtBQUkvRSxXQUFLLFVBQVUsNEJBQTRCO0FBQUEsUUFDdkMsZUFBZSxLQUFLO0FBQUEsUUFDcEIsVUFBVSxLQUFLO0FBQUEsUUFDZixZQUFZLEtBQUs7QUFBQSxRQUNqQixVQUFVLEtBQUs7QUFBQSxRQUNmLFdBQVc7QUFBQTtBQUFBLE1BQ2YsQ0FBQztBQUVELGNBQVEsSUFBSSwyRUFBMkU7QUFBQSxJQUMzRjtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLG1CQUFtQixNQUFNO0FBRXJCLFVBQUksS0FBSyxlQUFlLFFBQVc7QUFDL0IsYUFBSyxrQkFBa0IsS0FBSztBQUM1QixhQUFLLG1CQUFtQixLQUFLLFlBQVksS0FBSyxZQUFZLEtBQUssYUFBYTtBQUFBLE1BQ2hGO0FBR0EsVUFBSSxLQUFLLFVBQVU7QUFDZixhQUFLLGdCQUFnQixLQUFLO0FBQUEsTUFDOUI7QUFHQSxXQUFLLGtCQUFrQjtBQUFBLElBQzNCO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBT0EsMEJBQTBCLE1BQU07QUFDNUIsWUFBTSxXQUFXLEtBQUssb0JBQW9CLEtBQUs7QUFFL0MsVUFBSSxVQUFVO0FBRVYsYUFBSyxRQUFRLE1BQU0sVUFBVTtBQUFBLE1BQ2pDLE9BQU87QUFFSCxhQUFLLFFBQVEsTUFBTSxVQUFVO0FBQUEsTUFDakM7QUFBQSxJQUNKO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxtQkFBbUIsWUFBWSxVQUFVO0FBQ3JDLFlBQU0sZ0JBQWdCLEtBQUssbUJBQW1CLGNBQWMsZ0JBQWdCO0FBQzVFLFVBQUksZUFBZTtBQUNmLGNBQU0saUJBQWlCLEtBQUssWUFBWSxVQUFVO0FBQ2xELHNCQUFjLGNBQWMsR0FBRyxjQUFjLElBQUksUUFBUTtBQUFBLE1BQzdEO0FBQUEsSUFDSjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsWUFBWSxPQUFPO0FBQ2YsYUFBTyxXQUFXLEtBQUssRUFBRSxRQUFRLENBQUMsRUFBRSxRQUFRLEtBQUssR0FBRztBQUFBLElBQ3hEO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBT0Esb0JBQW9CO0FBQUEsSUFJcEI7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLGFBQWE7QUFDVCxjQUFRLElBQUksdUNBQXVDO0FBR25ELFlBQU0sZ0JBQWdCLEtBQUssbUJBQW1CLGNBQWMsaUJBQWlCO0FBQzdFLFlBQU0sZ0JBQWdCLEtBQUssbUJBQW1CLGNBQWMsaUJBQWlCO0FBRTdFLFVBQUk7QUFBZSxzQkFBYyxVQUFVLElBQUksUUFBUTtBQUN2RCxVQUFJO0FBQWUsc0JBQWMsVUFBVSxPQUFPLFFBQVE7QUFHMUQsV0FBSyxhQUFhLE1BQU0sVUFBVTtBQUdsQyxXQUFLLG1CQUFtQixXQUFXO0FBR25DLFdBQUssVUFBVTtBQUFBLElBQ25CO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxhQUFhO0FBQ1QsY0FBUSxJQUFJLHNDQUFzQztBQUdsRCxZQUFNLGdCQUFnQixLQUFLLG1CQUFtQixjQUFjLGlCQUFpQjtBQUM3RSxZQUFNLGdCQUFnQixLQUFLLG1CQUFtQixjQUFjLGlCQUFpQjtBQUU3RSxVQUFJO0FBQWUsc0JBQWMsVUFBVSxPQUFPLFFBQVE7QUFDMUQsVUFBSTtBQUFlLHNCQUFjLFVBQVUsSUFBSSxRQUFRO0FBR3ZELFdBQUssYUFBYSxNQUFNLFVBQVU7QUFHbEMsV0FBSyxrQkFBa0I7QUFBQSxJQUMzQjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EsVUFBVSxTQUFTO0FBQ2YsY0FBUSxNQUFNLGlDQUFpQyxPQUFPO0FBRXRELFVBQUksS0FBSyx1QkFBdUI7QUFDNUIsYUFBSyxtQkFBbUIsY0FBYztBQUFBLE1BQzFDO0FBRUEsV0FBSyxZQUFZLE1BQU0sVUFBVTtBQUdqQyxXQUFLLFlBQVksZUFBZTtBQUFBLFFBQzVCLFVBQVU7QUFBQSxRQUNWLE9BQU87QUFBQSxNQUNYLENBQUM7QUFBQSxJQUNMO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxZQUFZO0FBQ1IsV0FBSyxZQUFZLE1BQU0sVUFBVTtBQUFBLElBQ3JDO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxjQUFjO0FBQ1YsY0FBUSxJQUFJLDJDQUEyQztBQUd2RCxZQUFNLGFBQWEsS0FBSyxtQkFBbUIsY0FBYyxjQUFjO0FBQ3ZFLFVBQUksWUFBWTtBQUNaLG1CQUFXLFlBQVk7QUFBQSxNQUMzQjtBQUVBLFdBQUssbUJBQW1CLFVBQVUsT0FBTyxhQUFhO0FBQ3RELFdBQUssbUJBQW1CLFVBQVUsSUFBSSxhQUFhO0FBQUEsSUFJdkQ7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFPQSxhQUFhO0FBQ1QsY0FBUSxJQUFJLHFDQUFxQztBQUFBLElBSXJEO0FBQUEsRUFDSjtBQWxSSSxnQkFERywyQ0FDSSxXQUFVO0FBQUEsSUFDYjtBQUFBO0FBQUEsSUFDQTtBQUFBO0FBQUEsSUFDQTtBQUFBO0FBQUEsSUFDQTtBQUFBO0FBQUEsRUFDSjtBQUVBLGdCQVJHLDJDQVFJLFVBQVM7QUFBQSxJQUNaLFVBQVU7QUFBQTtBQUFBLElBQ1YsZUFBZTtBQUFBO0FBQUEsSUFDZixZQUFZO0FBQUE7QUFBQSxJQUNaLFVBQVU7QUFBQTtBQUFBLElBQ1YsV0FBVztBQUFBO0FBQUEsRUFDZjs7O0FDN0JKLFNBQU8sV0FBVyxZQUFZLE1BQU07QUFHcEMsV0FBUyxTQUFTLGdCQUFnQiwrQkFBcUI7QUFDdkQsV0FBUyxTQUFTLGdCQUFnQiwrQkFBcUI7QUFDdkQsV0FBUyxTQUFTLGtCQUFrQixpQ0FBdUI7QUFDM0QsV0FBUyxTQUFTLGtCQUFrQixpQ0FBdUI7QUFDM0QsV0FBUyxTQUFTLDBCQUEwQix5Q0FBOEI7QUFHMUUsTUFBSSxNQUF3QztBQUMxQyxhQUFTLFFBQVE7QUFDakIsWUFBUSxJQUFJLHlEQUF5RCxTQUFTLE9BQU8sbUJBQW1CO0FBQUEsRUFDMUc7QUFFQSxVQUFRLElBQUksNENBQTRDOyIsCiAgIm5hbWVzIjogWyJlcnJvciIsICJmZXRjaCIsICJtYXRjaCIsICJvbGRWYWx1ZSIsICJlcnJvciIsICJfYSIsICJjb25zdHJ1Y3RvciIsICJlbGVtZW50IiwgIl9hIiwgImVycm9yIiwgIl9hIiwgImVycm9yIiwgIl9hIiwgImVycm9yIiwgImVycm9yIiwgIl9hIl0KfQo=
