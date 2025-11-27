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
        publishableKey: this.publishableKeyValue ? this.publishableKeyValue.substring(0, 10) + "..." : "missing"
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
        this.card = this.elements.create("card");
        this.card.mount("#card-element");
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
        this.showError(error2.message || "Payment processing failed");
      } finally {
        this.hideLoading();
      }
    }
    /**
     * Handle Stripe Checkout flow (hosted payment page)
     * Used for wallet payments (Apple Pay, Google Pay)
     */
    async handleStripeCheckout() {
      if (!window.Stripe) {
        throw new Error("Stripe.js not loaded");
      }
      if (!this.hasPublishableKeyValue || !this.publishableKeyValue) {
        throw new Error("Stripe publishable key not configured");
      }
      const stripe = Stripe(this.publishableKeyValue);
      this.setStatus("Creating checkout session...");
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
        throw new Error(errorData.error || "Failed to create checkout session");
      }
      const data = await response.json();
      if (!data.id) {
        throw new Error("Invalid checkout session response");
      }
      console.log("Checkout Session created:", data.id);
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
      const stripeOrderController = this.getStripeOrderController();
      if (!stripeOrderController) {
        throw new Error("Stripe payment controller not found. Please refresh the page.");
      }
      if (!stripeOrderController.card || !stripeOrderController.stripe) {
        console.error("Payment form not ready:", {
          hasCard: !!stripeOrderController.card,
          hasStripe: !!stripeOrderController.stripe
        });
        throw new Error("Payment form not initialized. Please refresh the page.");
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
        throw new Error("Payment not completed");
      }
    }
    /**
     * Fetch payment intent creation URL and return response
     * @returns {Promise<Object>} Payment intent response with clientSecret, amount, currency
     * @throws {Error} If fetch fails or response is not ok
     */
    async handlePayment() {
      if (!this.hasUrlValue) {
        throw new Error("Payment URL is not configured");
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
        throw new Error("Invalid payment intent response");
      }
      return responseData;
    }
    /**
     * Show loading state on button
     */
    showLoading() {
      this.element.disabled = true;
      this.originalText = this.element.textContent;
      this.element.textContent = "Processing...";
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
        button.disabled = !isChecked;
        if (isChecked) {
          button.classList.remove("disabled");
          button.removeAttribute("title");
        } else {
          button.classList.add("disabled");
          button.setAttribute("title", "Please accept the terms and conditions");
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

  // resources/build/js/app.js
  window.Stimulus = Application.start();
  Stimulus.register("buy-now", buy_now_controller_default);
  Stimulus.register("stripe-order", stripe_order_controller_default);
  Stimulus.register("order-submit", order_submit_controller_default);
  Stimulus.register("agb-validation", agb_validation_controller_default);
  if (true) {
    Stimulus.debug = true;
    console.log("Stripe Module: Stimulus initialized with controllers:", Stimulus.router.modulesByIdentifier);
  }
  console.log("Stripe Module: JavaScript loaded and ready");
})();
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsiLi4vLi4vbm9kZV9tb2R1bGVzL0Bob3R3aXJlZC9zdGltdWx1cy9kaXN0L3N0aW11bHVzLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9jb250cm9sbGVycy9idXlfbm93X2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL2NvbnRyb2xsZXJzL3N0cmlwZV9vcmRlcl9jb250cm9sbGVyLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9jb250cm9sbGVycy9vcmRlcl9zdWJtaXRfY29udHJvbGxlci5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvY29udHJvbGxlcnMvYWdiX3ZhbGlkYXRpb25fY29udHJvbGxlci5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvYXBwLmpzIl0sCiAgInNvdXJjZXNDb250ZW50IjogWyIvKlxuU3RpbXVsdXMgMy4yLjFcbkNvcHlyaWdodCBcdTAwQTkgMjAyMyBCYXNlY2FtcCwgTExDXG4gKi9cbmNsYXNzIEV2ZW50TGlzdGVuZXIge1xuICAgIGNvbnN0cnVjdG9yKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICB0aGlzLmV2ZW50VGFyZ2V0ID0gZXZlbnRUYXJnZXQ7XG4gICAgICAgIHRoaXMuZXZlbnROYW1lID0gZXZlbnROYW1lO1xuICAgICAgICB0aGlzLmV2ZW50T3B0aW9ucyA9IGV2ZW50T3B0aW9ucztcbiAgICAgICAgdGhpcy51bm9yZGVyZWRCaW5kaW5ncyA9IG5ldyBTZXQoKTtcbiAgICB9XG4gICAgY29ubmVjdCgpIHtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldC5hZGRFdmVudExpc3RlbmVyKHRoaXMuZXZlbnROYW1lLCB0aGlzLCB0aGlzLmV2ZW50T3B0aW9ucyk7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3QoKSB7XG4gICAgICAgIHRoaXMuZXZlbnRUYXJnZXQucmVtb3ZlRXZlbnRMaXN0ZW5lcih0aGlzLmV2ZW50TmFtZSwgdGhpcywgdGhpcy5ldmVudE9wdGlvbnMpO1xuICAgIH1cbiAgICBiaW5kaW5nQ29ubmVjdGVkKGJpbmRpbmcpIHtcbiAgICAgICAgdGhpcy51bm9yZGVyZWRCaW5kaW5ncy5hZGQoYmluZGluZyk7XG4gICAgfVxuICAgIGJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZykge1xuICAgICAgICB0aGlzLnVub3JkZXJlZEJpbmRpbmdzLmRlbGV0ZShiaW5kaW5nKTtcbiAgICB9XG4gICAgaGFuZGxlRXZlbnQoZXZlbnQpIHtcbiAgICAgICAgY29uc3QgZXh0ZW5kZWRFdmVudCA9IGV4dGVuZEV2ZW50KGV2ZW50KTtcbiAgICAgICAgZm9yIChjb25zdCBiaW5kaW5nIG9mIHRoaXMuYmluZGluZ3MpIHtcbiAgICAgICAgICAgIGlmIChleHRlbmRlZEV2ZW50LmltbWVkaWF0ZVByb3BhZ2F0aW9uU3RvcHBlZCkge1xuICAgICAgICAgICAgICAgIGJyZWFrO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgYmluZGluZy5oYW5kbGVFdmVudChleHRlbmRlZEV2ZW50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBoYXNCaW5kaW5ncygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMudW5vcmRlcmVkQmluZGluZ3Muc2l6ZSA+IDA7XG4gICAgfVxuICAgIGdldCBiaW5kaW5ncygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy51bm9yZGVyZWRCaW5kaW5ncykuc29ydCgobGVmdCwgcmlnaHQpID0+IHtcbiAgICAgICAgICAgIGNvbnN0IGxlZnRJbmRleCA9IGxlZnQuaW5kZXgsIHJpZ2h0SW5kZXggPSByaWdodC5pbmRleDtcbiAgICAgICAgICAgIHJldHVybiBsZWZ0SW5kZXggPCByaWdodEluZGV4ID8gLTEgOiBsZWZ0SW5kZXggPiByaWdodEluZGV4ID8gMSA6IDA7XG4gICAgICAgIH0pO1xuICAgIH1cbn1cbmZ1bmN0aW9uIGV4dGVuZEV2ZW50KGV2ZW50KSB7XG4gICAgaWYgKFwiaW1tZWRpYXRlUHJvcGFnYXRpb25TdG9wcGVkXCIgaW4gZXZlbnQpIHtcbiAgICAgICAgcmV0dXJuIGV2ZW50O1xuICAgIH1cbiAgICBlbHNlIHtcbiAgICAgICAgY29uc3QgeyBzdG9wSW1tZWRpYXRlUHJvcGFnYXRpb24gfSA9IGV2ZW50O1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihldmVudCwge1xuICAgICAgICAgICAgaW1tZWRpYXRlUHJvcGFnYXRpb25TdG9wcGVkOiBmYWxzZSxcbiAgICAgICAgICAgIHN0b3BJbW1lZGlhdGVQcm9wYWdhdGlvbigpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmltbWVkaWF0ZVByb3BhZ2F0aW9uU3RvcHBlZCA9IHRydWU7XG4gICAgICAgICAgICAgICAgc3RvcEltbWVkaWF0ZVByb3BhZ2F0aW9uLmNhbGwodGhpcyk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9KTtcbiAgICB9XG59XG5cbmNsYXNzIERpc3BhdGNoZXIge1xuICAgIGNvbnN0cnVjdG9yKGFwcGxpY2F0aW9uKSB7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24gPSBhcHBsaWNhdGlvbjtcbiAgICAgICAgdGhpcy5ldmVudExpc3RlbmVyTWFwcyA9IG5ldyBNYXAoKTtcbiAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgICAgIHRoaXMuZXZlbnRMaXN0ZW5lcnMuZm9yRWFjaCgoZXZlbnRMaXN0ZW5lcikgPT4gZXZlbnRMaXN0ZW5lci5jb25uZWN0KCkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVycy5mb3JFYWNoKChldmVudExpc3RlbmVyKSA9PiBldmVudExpc3RlbmVyLmRpc2Nvbm5lY3QoKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGV2ZW50TGlzdGVuZXJzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmV2ZW50TGlzdGVuZXJNYXBzLnZhbHVlcygpKS5yZWR1Y2UoKGxpc3RlbmVycywgbWFwKSA9PiBsaXN0ZW5lcnMuY29uY2F0KEFycmF5LmZyb20obWFwLnZhbHVlcygpKSksIFtdKTtcbiAgICB9XG4gICAgYmluZGluZ0Nvbm5lY3RlZChiaW5kaW5nKSB7XG4gICAgICAgIHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKS5iaW5kaW5nQ29ubmVjdGVkKGJpbmRpbmcpO1xuICAgIH1cbiAgICBiaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcsIGNsZWFyRXZlbnRMaXN0ZW5lcnMgPSBmYWxzZSkge1xuICAgICAgICB0aGlzLmZldGNoRXZlbnRMaXN0ZW5lckZvckJpbmRpbmcoYmluZGluZykuYmluZGluZ0Rpc2Nvbm5lY3RlZChiaW5kaW5nKTtcbiAgICAgICAgaWYgKGNsZWFyRXZlbnRMaXN0ZW5lcnMpXG4gICAgICAgICAgICB0aGlzLmNsZWFyRXZlbnRMaXN0ZW5lcnNGb3JCaW5kaW5nKGJpbmRpbmcpO1xuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsID0ge30pIHtcbiAgICAgICAgdGhpcy5hcHBsaWNhdGlvbi5oYW5kbGVFcnJvcihlcnJvciwgYEVycm9yICR7bWVzc2FnZX1gLCBkZXRhaWwpO1xuICAgIH1cbiAgICBjbGVhckV2ZW50TGlzdGVuZXJzRm9yQmluZGluZyhiaW5kaW5nKSB7XG4gICAgICAgIGNvbnN0IGV2ZW50TGlzdGVuZXIgPSB0aGlzLmZldGNoRXZlbnRMaXN0ZW5lckZvckJpbmRpbmcoYmluZGluZyk7XG4gICAgICAgIGlmICghZXZlbnRMaXN0ZW5lci5oYXNCaW5kaW5ncygpKSB7XG4gICAgICAgICAgICBldmVudExpc3RlbmVyLmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMucmVtb3ZlTWFwcGVkRXZlbnRMaXN0ZW5lckZvcihiaW5kaW5nKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZW1vdmVNYXBwZWRFdmVudExpc3RlbmVyRm9yKGJpbmRpbmcpIHtcbiAgICAgICAgY29uc3QgeyBldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMgfSA9IGJpbmRpbmc7XG4gICAgICAgIGNvbnN0IGV2ZW50TGlzdGVuZXJNYXAgPSB0aGlzLmZldGNoRXZlbnRMaXN0ZW5lck1hcEZvckV2ZW50VGFyZ2V0KGV2ZW50VGFyZ2V0KTtcbiAgICAgICAgY29uc3QgY2FjaGVLZXkgPSB0aGlzLmNhY2hlS2V5KGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICAgICAgZXZlbnRMaXN0ZW5lck1hcC5kZWxldGUoY2FjaGVLZXkpO1xuICAgICAgICBpZiAoZXZlbnRMaXN0ZW5lck1hcC5zaXplID09IDApXG4gICAgICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJNYXBzLmRlbGV0ZShldmVudFRhcmdldCk7XG4gICAgfVxuICAgIGZldGNoRXZlbnRMaXN0ZW5lckZvckJpbmRpbmcoYmluZGluZykge1xuICAgICAgICBjb25zdCB7IGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyB9ID0gYmluZGluZztcbiAgICAgICAgcmV0dXJuIHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgfVxuICAgIGZldGNoRXZlbnRMaXN0ZW5lcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpIHtcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lck1hcCA9IHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyTWFwRm9yRXZlbnRUYXJnZXQoZXZlbnRUYXJnZXQpO1xuICAgICAgICBjb25zdCBjYWNoZUtleSA9IHRoaXMuY2FjaGVLZXkoZXZlbnROYW1lLCBldmVudE9wdGlvbnMpO1xuICAgICAgICBsZXQgZXZlbnRMaXN0ZW5lciA9IGV2ZW50TGlzdGVuZXJNYXAuZ2V0KGNhY2hlS2V5KTtcbiAgICAgICAgaWYgKCFldmVudExpc3RlbmVyKSB7XG4gICAgICAgICAgICBldmVudExpc3RlbmVyID0gdGhpcy5jcmVhdGVFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgICAgICBldmVudExpc3RlbmVyTWFwLnNldChjYWNoZUtleSwgZXZlbnRMaXN0ZW5lcik7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGV2ZW50TGlzdGVuZXI7XG4gICAgfVxuICAgIGNyZWF0ZUV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKSB7XG4gICAgICAgIGNvbnN0IGV2ZW50TGlzdGVuZXIgPSBuZXcgRXZlbnRMaXN0ZW5lcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpO1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBldmVudExpc3RlbmVyLmNvbm5lY3QoKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZXZlbnRMaXN0ZW5lcjtcbiAgICB9XG4gICAgZmV0Y2hFdmVudExpc3RlbmVyTWFwRm9yRXZlbnRUYXJnZXQoZXZlbnRUYXJnZXQpIHtcbiAgICAgICAgbGV0IGV2ZW50TGlzdGVuZXJNYXAgPSB0aGlzLmV2ZW50TGlzdGVuZXJNYXBzLmdldChldmVudFRhcmdldCk7XG4gICAgICAgIGlmICghZXZlbnRMaXN0ZW5lck1hcCkge1xuICAgICAgICAgICAgZXZlbnRMaXN0ZW5lck1hcCA9IG5ldyBNYXAoKTtcbiAgICAgICAgICAgIHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMuc2V0KGV2ZW50VGFyZ2V0LCBldmVudExpc3RlbmVyTWFwKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZXZlbnRMaXN0ZW5lck1hcDtcbiAgICB9XG4gICAgY2FjaGVLZXkoZXZlbnROYW1lLCBldmVudE9wdGlvbnMpIHtcbiAgICAgICAgY29uc3QgcGFydHMgPSBbZXZlbnROYW1lXTtcbiAgICAgICAgT2JqZWN0LmtleXMoZXZlbnRPcHRpb25zKVxuICAgICAgICAgICAgLnNvcnQoKVxuICAgICAgICAgICAgLmZvckVhY2goKGtleSkgPT4ge1xuICAgICAgICAgICAgcGFydHMucHVzaChgJHtldmVudE9wdGlvbnNba2V5XSA/IFwiXCIgOiBcIiFcIn0ke2tleX1gKTtcbiAgICAgICAgfSk7XG4gICAgICAgIHJldHVybiBwYXJ0cy5qb2luKFwiOlwiKTtcbiAgICB9XG59XG5cbmNvbnN0IGRlZmF1bHRBY3Rpb25EZXNjcmlwdG9yRmlsdGVycyA9IHtcbiAgICBzdG9wKHsgZXZlbnQsIHZhbHVlIH0pIHtcbiAgICAgICAgaWYgKHZhbHVlKVxuICAgICAgICAgICAgZXZlbnQuc3RvcFByb3BhZ2F0aW9uKCk7XG4gICAgICAgIHJldHVybiB0cnVlO1xuICAgIH0sXG4gICAgcHJldmVudCh7IGV2ZW50LCB2YWx1ZSB9KSB7XG4gICAgICAgIGlmICh2YWx1ZSlcbiAgICAgICAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KCk7XG4gICAgICAgIHJldHVybiB0cnVlO1xuICAgIH0sXG4gICAgc2VsZih7IGV2ZW50LCB2YWx1ZSwgZWxlbWVudCB9KSB7XG4gICAgICAgIGlmICh2YWx1ZSkge1xuICAgICAgICAgICAgcmV0dXJuIGVsZW1lbnQgPT09IGV2ZW50LnRhcmdldDtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICB9XG4gICAgfSxcbn07XG5jb25zdCBkZXNjcmlwdG9yUGF0dGVybiA9IC9eKD86KD86KFteLl0rPylcXCspPyguKz8pKD86XFwuKC4rPykpPyg/OkAod2luZG93fGRvY3VtZW50KSk/LT4pPyguKz8pKD86IyhbXjpdKz8pKSg/OjooLispKT8kLztcbmZ1bmN0aW9uIHBhcnNlQWN0aW9uRGVzY3JpcHRvclN0cmluZyhkZXNjcmlwdG9yU3RyaW5nKSB7XG4gICAgY29uc3Qgc291cmNlID0gZGVzY3JpcHRvclN0cmluZy50cmltKCk7XG4gICAgY29uc3QgbWF0Y2hlcyA9IHNvdXJjZS5tYXRjaChkZXNjcmlwdG9yUGF0dGVybikgfHwgW107XG4gICAgbGV0IGV2ZW50TmFtZSA9IG1hdGNoZXNbMl07XG4gICAgbGV0IGtleUZpbHRlciA9IG1hdGNoZXNbM107XG4gICAgaWYgKGtleUZpbHRlciAmJiAhW1wia2V5ZG93blwiLCBcImtleXVwXCIsIFwia2V5cHJlc3NcIl0uaW5jbHVkZXMoZXZlbnROYW1lKSkge1xuICAgICAgICBldmVudE5hbWUgKz0gYC4ke2tleUZpbHRlcn1gO1xuICAgICAgICBrZXlGaWx0ZXIgPSBcIlwiO1xuICAgIH1cbiAgICByZXR1cm4ge1xuICAgICAgICBldmVudFRhcmdldDogcGFyc2VFdmVudFRhcmdldChtYXRjaGVzWzRdKSxcbiAgICAgICAgZXZlbnROYW1lLFxuICAgICAgICBldmVudE9wdGlvbnM6IG1hdGNoZXNbN10gPyBwYXJzZUV2ZW50T3B0aW9ucyhtYXRjaGVzWzddKSA6IHt9LFxuICAgICAgICBpZGVudGlmaWVyOiBtYXRjaGVzWzVdLFxuICAgICAgICBtZXRob2ROYW1lOiBtYXRjaGVzWzZdLFxuICAgICAgICBrZXlGaWx0ZXI6IG1hdGNoZXNbMV0gfHwga2V5RmlsdGVyLFxuICAgIH07XG59XG5mdW5jdGlvbiBwYXJzZUV2ZW50VGFyZ2V0KGV2ZW50VGFyZ2V0TmFtZSkge1xuICAgIGlmIChldmVudFRhcmdldE5hbWUgPT0gXCJ3aW5kb3dcIikge1xuICAgICAgICByZXR1cm4gd2luZG93O1xuICAgIH1cbiAgICBlbHNlIGlmIChldmVudFRhcmdldE5hbWUgPT0gXCJkb2N1bWVudFwiKSB7XG4gICAgICAgIHJldHVybiBkb2N1bWVudDtcbiAgICB9XG59XG5mdW5jdGlvbiBwYXJzZUV2ZW50T3B0aW9ucyhldmVudE9wdGlvbnMpIHtcbiAgICByZXR1cm4gZXZlbnRPcHRpb25zXG4gICAgICAgIC5zcGxpdChcIjpcIilcbiAgICAgICAgLnJlZHVjZSgob3B0aW9ucywgdG9rZW4pID0+IE9iamVjdC5hc3NpZ24ob3B0aW9ucywgeyBbdG9rZW4ucmVwbGFjZSgvXiEvLCBcIlwiKV06ICEvXiEvLnRlc3QodG9rZW4pIH0pLCB7fSk7XG59XG5mdW5jdGlvbiBzdHJpbmdpZnlFdmVudFRhcmdldChldmVudFRhcmdldCkge1xuICAgIGlmIChldmVudFRhcmdldCA9PSB3aW5kb3cpIHtcbiAgICAgICAgcmV0dXJuIFwid2luZG93XCI7XG4gICAgfVxuICAgIGVsc2UgaWYgKGV2ZW50VGFyZ2V0ID09IGRvY3VtZW50KSB7XG4gICAgICAgIHJldHVybiBcImRvY3VtZW50XCI7XG4gICAgfVxufVxuXG5mdW5jdGlvbiBjYW1lbGl6ZSh2YWx1ZSkge1xuICAgIHJldHVybiB2YWx1ZS5yZXBsYWNlKC8oPzpbXy1dKShbYS16MC05XSkvZywgKF8sIGNoYXIpID0+IGNoYXIudG9VcHBlckNhc2UoKSk7XG59XG5mdW5jdGlvbiBuYW1lc3BhY2VDYW1lbGl6ZSh2YWx1ZSkge1xuICAgIHJldHVybiBjYW1lbGl6ZSh2YWx1ZS5yZXBsYWNlKC8tLS9nLCBcIi1cIikucmVwbGFjZSgvX18vZywgXCJfXCIpKTtcbn1cbmZ1bmN0aW9uIGNhcGl0YWxpemUodmFsdWUpIHtcbiAgICByZXR1cm4gdmFsdWUuY2hhckF0KDApLnRvVXBwZXJDYXNlKCkgKyB2YWx1ZS5zbGljZSgxKTtcbn1cbmZ1bmN0aW9uIGRhc2hlcml6ZSh2YWx1ZSkge1xuICAgIHJldHVybiB2YWx1ZS5yZXBsYWNlKC8oW0EtWl0pL2csIChfLCBjaGFyKSA9PiBgLSR7Y2hhci50b0xvd2VyQ2FzZSgpfWApO1xufVxuZnVuY3Rpb24gdG9rZW5pemUodmFsdWUpIHtcbiAgICByZXR1cm4gdmFsdWUubWF0Y2goL1teXFxzXSsvZykgfHwgW107XG59XG5cbmZ1bmN0aW9uIGlzU29tZXRoaW5nKG9iamVjdCkge1xuICAgIHJldHVybiBvYmplY3QgIT09IG51bGwgJiYgb2JqZWN0ICE9PSB1bmRlZmluZWQ7XG59XG5mdW5jdGlvbiBoYXNQcm9wZXJ0eShvYmplY3QsIHByb3BlcnR5KSB7XG4gICAgcmV0dXJuIE9iamVjdC5wcm90b3R5cGUuaGFzT3duUHJvcGVydHkuY2FsbChvYmplY3QsIHByb3BlcnR5KTtcbn1cblxuY29uc3QgYWxsTW9kaWZpZXJzID0gW1wibWV0YVwiLCBcImN0cmxcIiwgXCJhbHRcIiwgXCJzaGlmdFwiXTtcbmNsYXNzIEFjdGlvbiB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgaW5kZXgsIGRlc2NyaXB0b3IsIHNjaGVtYSkge1xuICAgICAgICB0aGlzLmVsZW1lbnQgPSBlbGVtZW50O1xuICAgICAgICB0aGlzLmluZGV4ID0gaW5kZXg7XG4gICAgICAgIHRoaXMuZXZlbnRUYXJnZXQgPSBkZXNjcmlwdG9yLmV2ZW50VGFyZ2V0IHx8IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuZXZlbnROYW1lID0gZGVzY3JpcHRvci5ldmVudE5hbWUgfHwgZ2V0RGVmYXVsdEV2ZW50TmFtZUZvckVsZW1lbnQoZWxlbWVudCkgfHwgZXJyb3IoXCJtaXNzaW5nIGV2ZW50IG5hbWVcIik7XG4gICAgICAgIHRoaXMuZXZlbnRPcHRpb25zID0gZGVzY3JpcHRvci5ldmVudE9wdGlvbnMgfHwge307XG4gICAgICAgIHRoaXMuaWRlbnRpZmllciA9IGRlc2NyaXB0b3IuaWRlbnRpZmllciB8fCBlcnJvcihcIm1pc3NpbmcgaWRlbnRpZmllclwiKTtcbiAgICAgICAgdGhpcy5tZXRob2ROYW1lID0gZGVzY3JpcHRvci5tZXRob2ROYW1lIHx8IGVycm9yKFwibWlzc2luZyBtZXRob2QgbmFtZVwiKTtcbiAgICAgICAgdGhpcy5rZXlGaWx0ZXIgPSBkZXNjcmlwdG9yLmtleUZpbHRlciB8fCBcIlwiO1xuICAgICAgICB0aGlzLnNjaGVtYSA9IHNjaGVtYTtcbiAgICB9XG4gICAgc3RhdGljIGZvclRva2VuKHRva2VuLCBzY2hlbWEpIHtcbiAgICAgICAgcmV0dXJuIG5ldyB0aGlzKHRva2VuLmVsZW1lbnQsIHRva2VuLmluZGV4LCBwYXJzZUFjdGlvbkRlc2NyaXB0b3JTdHJpbmcodG9rZW4uY29udGVudCksIHNjaGVtYSk7XG4gICAgfVxuICAgIHRvU3RyaW5nKCkge1xuICAgICAgICBjb25zdCBldmVudEZpbHRlciA9IHRoaXMua2V5RmlsdGVyID8gYC4ke3RoaXMua2V5RmlsdGVyfWAgOiBcIlwiO1xuICAgICAgICBjb25zdCBldmVudFRhcmdldCA9IHRoaXMuZXZlbnRUYXJnZXROYW1lID8gYEAke3RoaXMuZXZlbnRUYXJnZXROYW1lfWAgOiBcIlwiO1xuICAgICAgICByZXR1cm4gYCR7dGhpcy5ldmVudE5hbWV9JHtldmVudEZpbHRlcn0ke2V2ZW50VGFyZ2V0fS0+JHt0aGlzLmlkZW50aWZpZXJ9IyR7dGhpcy5tZXRob2ROYW1lfWA7XG4gICAgfVxuICAgIHNob3VsZElnbm9yZUtleWJvYXJkRXZlbnQoZXZlbnQpIHtcbiAgICAgICAgaWYgKCF0aGlzLmtleUZpbHRlcikge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGNvbnN0IGZpbHRlcnMgPSB0aGlzLmtleUZpbHRlci5zcGxpdChcIitcIik7XG4gICAgICAgIGlmICh0aGlzLmtleUZpbHRlckRpc3NhdGlzZmllZChldmVudCwgZmlsdGVycykpIHtcbiAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICB9XG4gICAgICAgIGNvbnN0IHN0YW5kYXJkRmlsdGVyID0gZmlsdGVycy5maWx0ZXIoKGtleSkgPT4gIWFsbE1vZGlmaWVycy5pbmNsdWRlcyhrZXkpKVswXTtcbiAgICAgICAgaWYgKCFzdGFuZGFyZEZpbHRlcikge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmICghaGFzUHJvcGVydHkodGhpcy5rZXlNYXBwaW5ncywgc3RhbmRhcmRGaWx0ZXIpKSB7XG4gICAgICAgICAgICBlcnJvcihgY29udGFpbnMgdW5rbm93biBrZXkgZmlsdGVyOiAke3RoaXMua2V5RmlsdGVyfWApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiB0aGlzLmtleU1hcHBpbmdzW3N0YW5kYXJkRmlsdGVyXS50b0xvd2VyQ2FzZSgpICE9PSBldmVudC5rZXkudG9Mb3dlckNhc2UoKTtcbiAgICB9XG4gICAgc2hvdWxkSWdub3JlTW91c2VFdmVudChldmVudCkge1xuICAgICAgICBpZiAoIXRoaXMua2V5RmlsdGVyKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgY29uc3QgZmlsdGVycyA9IFt0aGlzLmtleUZpbHRlcl07XG4gICAgICAgIGlmICh0aGlzLmtleUZpbHRlckRpc3NhdGlzZmllZChldmVudCwgZmlsdGVycykpIHtcbiAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBmYWxzZTtcbiAgICB9XG4gICAgZ2V0IHBhcmFtcygpIHtcbiAgICAgICAgY29uc3QgcGFyYW1zID0ge307XG4gICAgICAgIGNvbnN0IHBhdHRlcm4gPSBuZXcgUmVnRXhwKGBeZGF0YS0ke3RoaXMuaWRlbnRpZmllcn0tKC4rKS1wYXJhbSRgLCBcImlcIik7XG4gICAgICAgIGZvciAoY29uc3QgeyBuYW1lLCB2YWx1ZSB9IG9mIEFycmF5LmZyb20odGhpcy5lbGVtZW50LmF0dHJpYnV0ZXMpKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaCA9IG5hbWUubWF0Y2gocGF0dGVybik7XG4gICAgICAgICAgICBjb25zdCBrZXkgPSBtYXRjaCAmJiBtYXRjaFsxXTtcbiAgICAgICAgICAgIGlmIChrZXkpIHtcbiAgICAgICAgICAgICAgICBwYXJhbXNbY2FtZWxpemUoa2V5KV0gPSB0eXBlY2FzdCh2YWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHBhcmFtcztcbiAgICB9XG4gICAgZ2V0IGV2ZW50VGFyZ2V0TmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIHN0cmluZ2lmeUV2ZW50VGFyZ2V0KHRoaXMuZXZlbnRUYXJnZXQpO1xuICAgIH1cbiAgICBnZXQga2V5TWFwcGluZ3MoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjaGVtYS5rZXlNYXBwaW5ncztcbiAgICB9XG4gICAga2V5RmlsdGVyRGlzc2F0aXNmaWVkKGV2ZW50LCBmaWx0ZXJzKSB7XG4gICAgICAgIGNvbnN0IFttZXRhLCBjdHJsLCBhbHQsIHNoaWZ0XSA9IGFsbE1vZGlmaWVycy5tYXAoKG1vZGlmaWVyKSA9PiBmaWx0ZXJzLmluY2x1ZGVzKG1vZGlmaWVyKSk7XG4gICAgICAgIHJldHVybiBldmVudC5tZXRhS2V5ICE9PSBtZXRhIHx8IGV2ZW50LmN0cmxLZXkgIT09IGN0cmwgfHwgZXZlbnQuYWx0S2V5ICE9PSBhbHQgfHwgZXZlbnQuc2hpZnRLZXkgIT09IHNoaWZ0O1xuICAgIH1cbn1cbmNvbnN0IGRlZmF1bHRFdmVudE5hbWVzID0ge1xuICAgIGE6ICgpID0+IFwiY2xpY2tcIixcbiAgICBidXR0b246ICgpID0+IFwiY2xpY2tcIixcbiAgICBmb3JtOiAoKSA9PiBcInN1Ym1pdFwiLFxuICAgIGRldGFpbHM6ICgpID0+IFwidG9nZ2xlXCIsXG4gICAgaW5wdXQ6IChlKSA9PiAoZS5nZXRBdHRyaWJ1dGUoXCJ0eXBlXCIpID09IFwic3VibWl0XCIgPyBcImNsaWNrXCIgOiBcImlucHV0XCIpLFxuICAgIHNlbGVjdDogKCkgPT4gXCJjaGFuZ2VcIixcbiAgICB0ZXh0YXJlYTogKCkgPT4gXCJpbnB1dFwiLFxufTtcbmZ1bmN0aW9uIGdldERlZmF1bHRFdmVudE5hbWVGb3JFbGVtZW50KGVsZW1lbnQpIHtcbiAgICBjb25zdCB0YWdOYW1lID0gZWxlbWVudC50YWdOYW1lLnRvTG93ZXJDYXNlKCk7XG4gICAgaWYgKHRhZ05hbWUgaW4gZGVmYXVsdEV2ZW50TmFtZXMpIHtcbiAgICAgICAgcmV0dXJuIGRlZmF1bHRFdmVudE5hbWVzW3RhZ05hbWVdKGVsZW1lbnQpO1xuICAgIH1cbn1cbmZ1bmN0aW9uIGVycm9yKG1lc3NhZ2UpIHtcbiAgICB0aHJvdyBuZXcgRXJyb3IobWVzc2FnZSk7XG59XG5mdW5jdGlvbiB0eXBlY2FzdCh2YWx1ZSkge1xuICAgIHRyeSB7XG4gICAgICAgIHJldHVybiBKU09OLnBhcnNlKHZhbHVlKTtcbiAgICB9XG4gICAgY2F0Y2ggKG9fTykge1xuICAgICAgICByZXR1cm4gdmFsdWU7XG4gICAgfVxufVxuXG5jbGFzcyBCaW5kaW5nIHtcbiAgICBjb25zdHJ1Y3Rvcihjb250ZXh0LCBhY3Rpb24pIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICAgICAgdGhpcy5hY3Rpb24gPSBhY3Rpb247XG4gICAgfVxuICAgIGdldCBpbmRleCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYWN0aW9uLmluZGV4O1xuICAgIH1cbiAgICBnZXQgZXZlbnRUYXJnZXQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFjdGlvbi5ldmVudFRhcmdldDtcbiAgICB9XG4gICAgZ2V0IGV2ZW50T3B0aW9ucygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYWN0aW9uLmV2ZW50T3B0aW9ucztcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgaGFuZGxlRXZlbnQoZXZlbnQpIHtcbiAgICAgICAgY29uc3QgYWN0aW9uRXZlbnQgPSB0aGlzLnByZXBhcmVBY3Rpb25FdmVudChldmVudCk7XG4gICAgICAgIGlmICh0aGlzLndpbGxCZUludm9rZWRCeUV2ZW50KGV2ZW50KSAmJiB0aGlzLmFwcGx5RXZlbnRNb2RpZmllcnMoYWN0aW9uRXZlbnQpKSB7XG4gICAgICAgICAgICB0aGlzLmludm9rZVdpdGhFdmVudChhY3Rpb25FdmVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGV2ZW50TmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYWN0aW9uLmV2ZW50TmFtZTtcbiAgICB9XG4gICAgZ2V0IG1ldGhvZCgpIHtcbiAgICAgICAgY29uc3QgbWV0aG9kID0gdGhpcy5jb250cm9sbGVyW3RoaXMubWV0aG9kTmFtZV07XG4gICAgICAgIGlmICh0eXBlb2YgbWV0aG9kID09IFwiZnVuY3Rpb25cIikge1xuICAgICAgICAgICAgcmV0dXJuIG1ldGhvZDtcbiAgICAgICAgfVxuICAgICAgICB0aHJvdyBuZXcgRXJyb3IoYEFjdGlvbiBcIiR7dGhpcy5hY3Rpb259XCIgcmVmZXJlbmNlcyB1bmRlZmluZWQgbWV0aG9kIFwiJHt0aGlzLm1ldGhvZE5hbWV9XCJgKTtcbiAgICB9XG4gICAgYXBwbHlFdmVudE1vZGlmaWVycyhldmVudCkge1xuICAgICAgICBjb25zdCB7IGVsZW1lbnQgfSA9IHRoaXMuYWN0aW9uO1xuICAgICAgICBjb25zdCB7IGFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzIH0gPSB0aGlzLmNvbnRleHQuYXBwbGljYXRpb247XG4gICAgICAgIGNvbnN0IHsgY29udHJvbGxlciB9ID0gdGhpcy5jb250ZXh0O1xuICAgICAgICBsZXQgcGFzc2VzID0gdHJ1ZTtcbiAgICAgICAgZm9yIChjb25zdCBbbmFtZSwgdmFsdWVdIG9mIE9iamVjdC5lbnRyaWVzKHRoaXMuZXZlbnRPcHRpb25zKSkge1xuICAgICAgICAgICAgaWYgKG5hbWUgaW4gYWN0aW9uRGVzY3JpcHRvckZpbHRlcnMpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBmaWx0ZXIgPSBhY3Rpb25EZXNjcmlwdG9yRmlsdGVyc1tuYW1lXTtcbiAgICAgICAgICAgICAgICBwYXNzZXMgPSBwYXNzZXMgJiYgZmlsdGVyKHsgbmFtZSwgdmFsdWUsIGV2ZW50LCBlbGVtZW50LCBjb250cm9sbGVyIH0pO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgY29udGludWU7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHBhc3NlcztcbiAgICB9XG4gICAgcHJlcGFyZUFjdGlvbkV2ZW50KGV2ZW50KSB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKGV2ZW50LCB7IHBhcmFtczogdGhpcy5hY3Rpb24ucGFyYW1zIH0pO1xuICAgIH1cbiAgICBpbnZva2VXaXRoRXZlbnQoZXZlbnQpIHtcbiAgICAgICAgY29uc3QgeyB0YXJnZXQsIGN1cnJlbnRUYXJnZXQgfSA9IGV2ZW50O1xuICAgICAgICB0cnkge1xuICAgICAgICAgICAgdGhpcy5tZXRob2QuY2FsbCh0aGlzLmNvbnRyb2xsZXIsIGV2ZW50KTtcbiAgICAgICAgICAgIHRoaXMuY29udGV4dC5sb2dEZWJ1Z0FjdGl2aXR5KHRoaXMubWV0aG9kTmFtZSwgeyBldmVudCwgdGFyZ2V0LCBjdXJyZW50VGFyZ2V0LCBhY3Rpb246IHRoaXMubWV0aG9kTmFtZSB9KTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIGNvbnN0IHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCwgaW5kZXggfSA9IHRoaXM7XG4gICAgICAgICAgICBjb25zdCBkZXRhaWwgPSB7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXIsIGVsZW1lbnQsIGluZGV4LCBldmVudCB9O1xuICAgICAgICAgICAgdGhpcy5jb250ZXh0LmhhbmRsZUVycm9yKGVycm9yLCBgaW52b2tpbmcgYWN0aW9uIFwiJHt0aGlzLmFjdGlvbn1cImAsIGRldGFpbCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgd2lsbEJlSW52b2tlZEJ5RXZlbnQoZXZlbnQpIHtcbiAgICAgICAgY29uc3QgZXZlbnRUYXJnZXQgPSBldmVudC50YXJnZXQ7XG4gICAgICAgIGlmIChldmVudCBpbnN0YW5jZW9mIEtleWJvYXJkRXZlbnQgJiYgdGhpcy5hY3Rpb24uc2hvdWxkSWdub3JlS2V5Ym9hcmRFdmVudChldmVudCkpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBpZiAoZXZlbnQgaW5zdGFuY2VvZiBNb3VzZUV2ZW50ICYmIHRoaXMuYWN0aW9uLnNob3VsZElnbm9yZU1vdXNlRXZlbnQoZXZlbnQpKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKHRoaXMuZWxlbWVudCA9PT0gZXZlbnRUYXJnZXQpIHtcbiAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICB9XG4gICAgICAgIGVsc2UgaWYgKGV2ZW50VGFyZ2V0IGluc3RhbmNlb2YgRWxlbWVudCAmJiB0aGlzLmVsZW1lbnQuY29udGFpbnMoZXZlbnRUYXJnZXQpKSB7XG4gICAgICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5jb250YWluc0VsZW1lbnQoZXZlbnRUYXJnZXQpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuY29udGFpbnNFbGVtZW50KHRoaXMuYWN0aW9uLmVsZW1lbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmNvbnRyb2xsZXI7XG4gICAgfVxuICAgIGdldCBtZXRob2ROYW1lKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24ubWV0aG9kTmFtZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzY29wZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY29wZTtcbiAgICB9XG59XG5cbmNsYXNzIEVsZW1lbnRPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVySW5pdCA9IHsgYXR0cmlidXRlczogdHJ1ZSwgY2hpbGRMaXN0OiB0cnVlLCBzdWJ0cmVlOiB0cnVlIH07XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMuZWxlbWVudHMgPSBuZXcgU2V0KCk7XG4gICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlciA9IG5ldyBNdXRhdGlvbk9ic2VydmVyKChtdXRhdGlvbnMpID0+IHRoaXMucHJvY2Vzc011dGF0aW9ucyhtdXRhdGlvbnMpKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLm9ic2VydmUodGhpcy5lbGVtZW50LCB0aGlzLm11dGF0aW9uT2JzZXJ2ZXJJbml0KTtcbiAgICAgICAgICAgIHRoaXMucmVmcmVzaCgpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHBhdXNlKGNhbGxiYWNrKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBjYWxsYmFjaygpO1xuICAgICAgICBpZiAoIXRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLm9ic2VydmUodGhpcy5lbGVtZW50LCB0aGlzLm11dGF0aW9uT2JzZXJ2ZXJJbml0KTtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IHRydWU7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLnRha2VSZWNvcmRzKCk7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlcyA9IG5ldyBTZXQodGhpcy5tYXRjaEVsZW1lbnRzSW5UcmVlKCkpO1xuICAgICAgICAgICAgZm9yIChjb25zdCBlbGVtZW50IG9mIEFycmF5LmZyb20odGhpcy5lbGVtZW50cykpIHtcbiAgICAgICAgICAgICAgICBpZiAoIW1hdGNoZXMuaGFzKGVsZW1lbnQpKSB7XG4gICAgICAgICAgICAgICAgICAgIHRoaXMucmVtb3ZlRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgQXJyYXkuZnJvbShtYXRjaGVzKSkge1xuICAgICAgICAgICAgICAgIHRoaXMuYWRkRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IG11dGF0aW9uIG9mIG11dGF0aW9ucykge1xuICAgICAgICAgICAgICAgIHRoaXMucHJvY2Vzc011dGF0aW9uKG11dGF0aW9uKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzTXV0YXRpb24obXV0YXRpb24pIHtcbiAgICAgICAgaWYgKG11dGF0aW9uLnR5cGUgPT0gXCJhdHRyaWJ1dGVzXCIpIHtcbiAgICAgICAgICAgIHRoaXMucHJvY2Vzc0F0dHJpYnV0ZUNoYW5nZShtdXRhdGlvbi50YXJnZXQsIG11dGF0aW9uLmF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2UgaWYgKG11dGF0aW9uLnR5cGUgPT0gXCJjaGlsZExpc3RcIikge1xuICAgICAgICAgICAgdGhpcy5wcm9jZXNzUmVtb3ZlZE5vZGVzKG11dGF0aW9uLnJlbW92ZWROb2Rlcyk7XG4gICAgICAgICAgICB0aGlzLnByb2Nlc3NBZGRlZE5vZGVzKG11dGF0aW9uLmFkZGVkTm9kZXMpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NBdHRyaWJ1dGVDaGFuZ2UoZWxlbWVudCwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBpZiAodGhpcy5lbGVtZW50cy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkICYmIHRoaXMubWF0Y2hFbGVtZW50KGVsZW1lbnQpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50QXR0cmlidXRlQ2hhbmdlZChlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgIHRoaXMucmVtb3ZlRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICBlbHNlIGlmICh0aGlzLm1hdGNoRWxlbWVudChlbGVtZW50KSkge1xuICAgICAgICAgICAgdGhpcy5hZGRFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NSZW1vdmVkTm9kZXMobm9kZXMpIHtcbiAgICAgICAgZm9yIChjb25zdCBub2RlIG9mIEFycmF5LmZyb20obm9kZXMpKSB7XG4gICAgICAgICAgICBjb25zdCBlbGVtZW50ID0gdGhpcy5lbGVtZW50RnJvbU5vZGUobm9kZSk7XG4gICAgICAgICAgICBpZiAoZWxlbWVudCkge1xuICAgICAgICAgICAgICAgIHRoaXMucHJvY2Vzc1RyZWUoZWxlbWVudCwgdGhpcy5yZW1vdmVFbGVtZW50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzQWRkZWROb2Rlcyhub2Rlcykge1xuICAgICAgICBmb3IgKGNvbnN0IG5vZGUgb2YgQXJyYXkuZnJvbShub2RlcykpIHtcbiAgICAgICAgICAgIGNvbnN0IGVsZW1lbnQgPSB0aGlzLmVsZW1lbnRGcm9tTm9kZShub2RlKTtcbiAgICAgICAgICAgIGlmIChlbGVtZW50ICYmIHRoaXMuZWxlbWVudElzQWN0aXZlKGVsZW1lbnQpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzVHJlZShlbGVtZW50LCB0aGlzLmFkZEVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIG1hdGNoRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIHJldHVybiB0aGlzLmRlbGVnYXRlLm1hdGNoRWxlbWVudChlbGVtZW50KTtcbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlID0gdGhpcy5lbGVtZW50KSB7XG4gICAgICAgIHJldHVybiB0aGlzLmRlbGVnYXRlLm1hdGNoRWxlbWVudHNJblRyZWUodHJlZSk7XG4gICAgfVxuICAgIHByb2Nlc3NUcmVlKHRyZWUsIHByb2Nlc3Nvcikge1xuICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgdGhpcy5tYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUpKSB7XG4gICAgICAgICAgICBwcm9jZXNzb3IuY2FsbCh0aGlzLCBlbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50RnJvbU5vZGUobm9kZSkge1xuICAgICAgICBpZiAobm9kZS5ub2RlVHlwZSA9PSBOb2RlLkVMRU1FTlRfTk9ERSkge1xuICAgICAgICAgICAgcmV0dXJuIG5vZGU7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudElzQWN0aXZlKGVsZW1lbnQpIHtcbiAgICAgICAgaWYgKGVsZW1lbnQuaXNDb25uZWN0ZWQgIT0gdGhpcy5lbGVtZW50LmlzQ29ubmVjdGVkKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50LmNvbnRhaW5zKGVsZW1lbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGFkZEVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBpZiAoIXRoaXMuZWxlbWVudHMuaGFzKGVsZW1lbnQpKSB7XG4gICAgICAgICAgICBpZiAodGhpcy5lbGVtZW50SXNBY3RpdmUoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmVsZW1lbnRzLmFkZChlbGVtZW50KTtcbiAgICAgICAgICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5lbGVtZW50TWF0Y2hlZCkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkKGVsZW1lbnQpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICByZW1vdmVFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgaWYgKHRoaXMuZWxlbWVudHMuaGFzKGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLmVsZW1lbnRzLmRlbGV0ZShlbGVtZW50KTtcbiAgICAgICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRVbm1hdGNoZWQpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRVbm1hdGNoZWQoZWxlbWVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmNsYXNzIEF0dHJpYnV0ZU9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU5hbWUgPSBhdHRyaWJ1dGVOYW1lO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyID0gbmV3IEVsZW1lbnRPYnNlcnZlcihlbGVtZW50LCB0aGlzKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnRPYnNlcnZlci5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgc2VsZWN0b3IoKSB7XG4gICAgICAgIHJldHVybiBgWyR7dGhpcy5hdHRyaWJ1dGVOYW1lfV1gO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgcGF1c2UoY2FsbGJhY2spIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIucGF1c2UoY2FsbGJhY2spO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnJlZnJlc2goKTtcbiAgICB9XG4gICAgZ2V0IHN0YXJ0ZWQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnRPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICByZXR1cm4gZWxlbWVudC5oYXNBdHRyaWJ1dGUodGhpcy5hdHRyaWJ1dGVOYW1lKTtcbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKSB7XG4gICAgICAgIGNvbnN0IG1hdGNoID0gdGhpcy5tYXRjaEVsZW1lbnQodHJlZSkgPyBbdHJlZV0gOiBbXTtcbiAgICAgICAgY29uc3QgbWF0Y2hlcyA9IEFycmF5LmZyb20odHJlZS5xdWVyeVNlbGVjdG9yQWxsKHRoaXMuc2VsZWN0b3IpKTtcbiAgICAgICAgcmV0dXJuIG1hdGNoLmNvbmNhdChtYXRjaGVzKTtcbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWQoZWxlbWVudCkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5lbGVtZW50TWF0Y2hlZEF0dHJpYnV0ZSkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50TWF0Y2hlZEF0dHJpYnV0ZShlbGVtZW50LCB0aGlzLmF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWQoZWxlbWVudCkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5lbGVtZW50VW5tYXRjaGVkQXR0cmlidXRlKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUoZWxlbWVudCwgdGhpcy5hdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50QXR0cmlidXRlQ2hhbmdlZChlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRBdHRyaWJ1dGVWYWx1ZUNoYW5nZWQgJiYgdGhpcy5hdHRyaWJ1dGVOYW1lID09IGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZVZhbHVlQ2hhbmdlZChlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbn1cblxuZnVuY3Rpb24gYWRkKG1hcCwga2V5LCB2YWx1ZSkge1xuICAgIGZldGNoKG1hcCwga2V5KS5hZGQodmFsdWUpO1xufVxuZnVuY3Rpb24gZGVsKG1hcCwga2V5LCB2YWx1ZSkge1xuICAgIGZldGNoKG1hcCwga2V5KS5kZWxldGUodmFsdWUpO1xuICAgIHBydW5lKG1hcCwga2V5KTtcbn1cbmZ1bmN0aW9uIGZldGNoKG1hcCwga2V5KSB7XG4gICAgbGV0IHZhbHVlcyA9IG1hcC5nZXQoa2V5KTtcbiAgICBpZiAoIXZhbHVlcykge1xuICAgICAgICB2YWx1ZXMgPSBuZXcgU2V0KCk7XG4gICAgICAgIG1hcC5zZXQoa2V5LCB2YWx1ZXMpO1xuICAgIH1cbiAgICByZXR1cm4gdmFsdWVzO1xufVxuZnVuY3Rpb24gcHJ1bmUobWFwLCBrZXkpIHtcbiAgICBjb25zdCB2YWx1ZXMgPSBtYXAuZ2V0KGtleSk7XG4gICAgaWYgKHZhbHVlcyAhPSBudWxsICYmIHZhbHVlcy5zaXplID09IDApIHtcbiAgICAgICAgbWFwLmRlbGV0ZShrZXkpO1xuICAgIH1cbn1cblxuY2xhc3MgTXVsdGltYXAge1xuICAgIGNvbnN0cnVjdG9yKCkge1xuICAgICAgICB0aGlzLnZhbHVlc0J5S2V5ID0gbmV3IE1hcCgpO1xuICAgIH1cbiAgICBnZXQga2V5cygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy52YWx1ZXNCeUtleS5rZXlzKCkpO1xuICAgIH1cbiAgICBnZXQgdmFsdWVzKCkge1xuICAgICAgICBjb25zdCBzZXRzID0gQXJyYXkuZnJvbSh0aGlzLnZhbHVlc0J5S2V5LnZhbHVlcygpKTtcbiAgICAgICAgcmV0dXJuIHNldHMucmVkdWNlKCh2YWx1ZXMsIHNldCkgPT4gdmFsdWVzLmNvbmNhdChBcnJheS5mcm9tKHNldCkpLCBbXSk7XG4gICAgfVxuICAgIGdldCBzaXplKCkge1xuICAgICAgICBjb25zdCBzZXRzID0gQXJyYXkuZnJvbSh0aGlzLnZhbHVlc0J5S2V5LnZhbHVlcygpKTtcbiAgICAgICAgcmV0dXJuIHNldHMucmVkdWNlKChzaXplLCBzZXQpID0+IHNpemUgKyBzZXQuc2l6ZSwgMCk7XG4gICAgfVxuICAgIGFkZChrZXksIHZhbHVlKSB7XG4gICAgICAgIGFkZCh0aGlzLnZhbHVlc0J5S2V5LCBrZXksIHZhbHVlKTtcbiAgICB9XG4gICAgZGVsZXRlKGtleSwgdmFsdWUpIHtcbiAgICAgICAgZGVsKHRoaXMudmFsdWVzQnlLZXksIGtleSwgdmFsdWUpO1xuICAgIH1cbiAgICBoYXMoa2V5LCB2YWx1ZSkge1xuICAgICAgICBjb25zdCB2YWx1ZXMgPSB0aGlzLnZhbHVlc0J5S2V5LmdldChrZXkpO1xuICAgICAgICByZXR1cm4gdmFsdWVzICE9IG51bGwgJiYgdmFsdWVzLmhhcyh2YWx1ZSk7XG4gICAgfVxuICAgIGhhc0tleShrZXkpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMudmFsdWVzQnlLZXkuaGFzKGtleSk7XG4gICAgfVxuICAgIGhhc1ZhbHVlKHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHNldHMgPSBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkudmFsdWVzKCkpO1xuICAgICAgICByZXR1cm4gc2V0cy5zb21lKChzZXQpID0+IHNldC5oYXModmFsdWUpKTtcbiAgICB9XG4gICAgZ2V0VmFsdWVzRm9yS2V5KGtleSkge1xuICAgICAgICBjb25zdCB2YWx1ZXMgPSB0aGlzLnZhbHVlc0J5S2V5LmdldChrZXkpO1xuICAgICAgICByZXR1cm4gdmFsdWVzID8gQXJyYXkuZnJvbSh2YWx1ZXMpIDogW107XG4gICAgfVxuICAgIGdldEtleXNGb3JWYWx1ZSh2YWx1ZSkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnZhbHVlc0J5S2V5KVxuICAgICAgICAgICAgLmZpbHRlcigoW19rZXksIHZhbHVlc10pID0+IHZhbHVlcy5oYXModmFsdWUpKVxuICAgICAgICAgICAgLm1hcCgoW2tleSwgX3ZhbHVlc10pID0+IGtleSk7XG4gICAgfVxufVxuXG5jbGFzcyBJbmRleGVkTXVsdGltYXAgZXh0ZW5kcyBNdWx0aW1hcCB7XG4gICAgY29uc3RydWN0b3IoKSB7XG4gICAgICAgIHN1cGVyKCk7XG4gICAgICAgIHRoaXMua2V5c0J5VmFsdWUgPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIGdldCB2YWx1ZXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMua2V5c0J5VmFsdWUua2V5cygpKTtcbiAgICB9XG4gICAgYWRkKGtleSwgdmFsdWUpIHtcbiAgICAgICAgc3VwZXIuYWRkKGtleSwgdmFsdWUpO1xuICAgICAgICBhZGQodGhpcy5rZXlzQnlWYWx1ZSwgdmFsdWUsIGtleSk7XG4gICAgfVxuICAgIGRlbGV0ZShrZXksIHZhbHVlKSB7XG4gICAgICAgIHN1cGVyLmRlbGV0ZShrZXksIHZhbHVlKTtcbiAgICAgICAgZGVsKHRoaXMua2V5c0J5VmFsdWUsIHZhbHVlLCBrZXkpO1xuICAgIH1cbiAgICBoYXNWYWx1ZSh2YWx1ZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5rZXlzQnlWYWx1ZS5oYXModmFsdWUpO1xuICAgIH1cbiAgICBnZXRLZXlzRm9yVmFsdWUodmFsdWUpIHtcbiAgICAgICAgY29uc3Qgc2V0ID0gdGhpcy5rZXlzQnlWYWx1ZS5nZXQodmFsdWUpO1xuICAgICAgICByZXR1cm4gc2V0ID8gQXJyYXkuZnJvbShzZXQpIDogW107XG4gICAgfVxufVxuXG5jbGFzcyBTZWxlY3Rvck9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBzZWxlY3RvciwgZGVsZWdhdGUsIGRldGFpbHMpIHtcbiAgICAgICAgdGhpcy5fc2VsZWN0b3IgPSBzZWxlY3RvcjtcbiAgICAgICAgdGhpcy5kZXRhaWxzID0gZGV0YWlscztcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIgPSBuZXcgRWxlbWVudE9ic2VydmVyKGVsZW1lbnQsIHRoaXMpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMubWF0Y2hlc0J5RWxlbWVudCA9IG5ldyBNdWx0aW1hcCgpO1xuICAgIH1cbiAgICBnZXQgc3RhcnRlZCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0ZWQ7XG4gICAgfVxuICAgIGdldCBzZWxlY3RvcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuX3NlbGVjdG9yO1xuICAgIH1cbiAgICBzZXQgc2VsZWN0b3Ioc2VsZWN0b3IpIHtcbiAgICAgICAgdGhpcy5fc2VsZWN0b3IgPSBzZWxlY3RvcjtcbiAgICAgICAgdGhpcy5yZWZyZXNoKCk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5wYXVzZShjYWxsYmFjayk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudE9ic2VydmVyLmVsZW1lbnQ7XG4gICAgfVxuICAgIG1hdGNoRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlcyA9IGVsZW1lbnQubWF0Y2hlcyhzZWxlY3Rvcik7XG4gICAgICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zZWxlY3Rvck1hdGNoRWxlbWVudCkge1xuICAgICAgICAgICAgICAgIHJldHVybiBtYXRjaGVzICYmIHRoaXMuZGVsZWdhdGUuc2VsZWN0b3JNYXRjaEVsZW1lbnQoZWxlbWVudCwgdGhpcy5kZXRhaWxzKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIHJldHVybiBtYXRjaGVzO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgfVxuICAgIG1hdGNoRWxlbWVudHNJblRyZWUodHJlZSkge1xuICAgICAgICBjb25zdCB7IHNlbGVjdG9yIH0gPSB0aGlzO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoID0gdGhpcy5tYXRjaEVsZW1lbnQodHJlZSkgPyBbdHJlZV0gOiBbXTtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoZXMgPSBBcnJheS5mcm9tKHRyZWUucXVlcnlTZWxlY3RvckFsbChzZWxlY3RvcikpLmZpbHRlcigobWF0Y2gpID0+IHRoaXMubWF0Y2hFbGVtZW50KG1hdGNoKSk7XG4gICAgICAgICAgICByZXR1cm4gbWF0Y2guY29uY2F0KG1hdGNoZXMpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIFtdO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkKGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgeyBzZWxlY3RvciB9ID0gdGhpcztcbiAgICAgICAgaWYgKHNlbGVjdG9yKSB7XG4gICAgICAgICAgICB0aGlzLnNlbGVjdG9yTWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9ycyA9IHRoaXMubWF0Y2hlc0J5RWxlbWVudC5nZXRLZXlzRm9yVmFsdWUoZWxlbWVudCk7XG4gICAgICAgIGZvciAoY29uc3Qgc2VsZWN0b3Igb2Ygc2VsZWN0b3JzKSB7XG4gICAgICAgICAgICB0aGlzLnNlbGVjdG9yVW5tYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50QXR0cmlidXRlQ2hhbmdlZChlbGVtZW50LCBfYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBjb25zdCB7IHNlbGVjdG9yIH0gPSB0aGlzO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoZXMgPSB0aGlzLm1hdGNoRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoZWRCZWZvcmUgPSB0aGlzLm1hdGNoZXNCeUVsZW1lbnQuaGFzKHNlbGVjdG9yLCBlbGVtZW50KTtcbiAgICAgICAgICAgIGlmIChtYXRjaGVzICYmICFtYXRjaGVkQmVmb3JlKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zZWxlY3Rvck1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZWxzZSBpZiAoIW1hdGNoZXMgJiYgbWF0Y2hlZEJlZm9yZSkge1xuICAgICAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHNlbGVjdG9yTWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcikge1xuICAgICAgICB0aGlzLmRlbGVnYXRlLnNlbGVjdG9yTWF0Y2hlZChlbGVtZW50LCBzZWxlY3RvciwgdGhpcy5kZXRhaWxzKTtcbiAgICAgICAgdGhpcy5tYXRjaGVzQnlFbGVtZW50LmFkZChzZWxlY3RvciwgZWxlbWVudCk7XG4gICAgfVxuICAgIHNlbGVjdG9yVW5tYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUuc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IsIHRoaXMuZGV0YWlscyk7XG4gICAgICAgIHRoaXMubWF0Y2hlc0J5RWxlbWVudC5kZWxldGUoc2VsZWN0b3IsIGVsZW1lbnQpO1xuICAgIH1cbn1cblxuY2xhc3MgU3RyaW5nTWFwT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIHRoaXMuc3RyaW5nTWFwID0gbmV3IE1hcCgpO1xuICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIgPSBuZXcgTXV0YXRpb25PYnNlcnZlcigobXV0YXRpb25zKSA9PiB0aGlzLnByb2Nlc3NNdXRhdGlvbnMobXV0YXRpb25zKSk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5vYnNlcnZlKHRoaXMuZWxlbWVudCwgeyBhdHRyaWJ1dGVzOiB0cnVlLCBhdHRyaWJ1dGVPbGRWYWx1ZTogdHJ1ZSB9KTtcbiAgICAgICAgICAgIHRoaXMucmVmcmVzaCgpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci50YWtlUmVjb3JkcygpO1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGZvciAoY29uc3QgYXR0cmlidXRlTmFtZSBvZiB0aGlzLmtub3duQXR0cmlidXRlTmFtZXMpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnJlZnJlc2hBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSwgbnVsbCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc011dGF0aW9ucyhtdXRhdGlvbnMpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgZm9yIChjb25zdCBtdXRhdGlvbiBvZiBtdXRhdGlvbnMpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnByb2Nlc3NNdXRhdGlvbihtdXRhdGlvbik7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc011dGF0aW9uKG11dGF0aW9uKSB7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSBtdXRhdGlvbi5hdHRyaWJ1dGVOYW1lO1xuICAgICAgICBpZiAoYXR0cmlidXRlTmFtZSkge1xuICAgICAgICAgICAgdGhpcy5yZWZyZXNoQXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUsIG11dGF0aW9uLm9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoQXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUsIG9sZFZhbHVlKSB7XG4gICAgICAgIGNvbnN0IGtleSA9IHRoaXMuZGVsZWdhdGUuZ2V0U3RyaW5nTWFwS2V5Rm9yQXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICBpZiAoa2V5ICE9IG51bGwpIHtcbiAgICAgICAgICAgIGlmICghdGhpcy5zdHJpbmdNYXAuaGFzKGF0dHJpYnV0ZU5hbWUpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zdHJpbmdNYXBLZXlBZGRlZChrZXksIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgY29uc3QgdmFsdWUgPSB0aGlzLmVsZW1lbnQuZ2V0QXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICAgICAgaWYgKHRoaXMuc3RyaW5nTWFwLmdldChhdHRyaWJ1dGVOYW1lKSAhPSB2YWx1ZSkge1xuICAgICAgICAgICAgICAgIHRoaXMuc3RyaW5nTWFwVmFsdWVDaGFuZ2VkKHZhbHVlLCBrZXksIG9sZFZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmICh2YWx1ZSA9PSBudWxsKSB7XG4gICAgICAgICAgICAgICAgY29uc3Qgb2xkVmFsdWUgPSB0aGlzLnN0cmluZ01hcC5nZXQoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICAgICAgdGhpcy5zdHJpbmdNYXAuZGVsZXRlKGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICAgICAgICAgIGlmIChvbGRWYWx1ZSlcbiAgICAgICAgICAgICAgICAgICAgdGhpcy5zdHJpbmdNYXBLZXlSZW1vdmVkKGtleSwgYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zdHJpbmdNYXAuc2V0KGF0dHJpYnV0ZU5hbWUsIHZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBLZXlBZGRlZChrZXksIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwS2V5QWRkZWQpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwS2V5QWRkZWQoa2V5LCBhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBWYWx1ZUNoYW5nZWQodmFsdWUsIGtleSwgb2xkVmFsdWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwVmFsdWVDaGFuZ2VkKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLnN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwga2V5LCBvbGRWYWx1ZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RyaW5nTWFwS2V5UmVtb3ZlZChrZXksIGF0dHJpYnV0ZU5hbWUsIG9sZFZhbHVlKSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLnN0cmluZ01hcEtleVJlbW92ZWQpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwS2V5UmVtb3ZlZChrZXksIGF0dHJpYnV0ZU5hbWUsIG9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQga25vd25BdHRyaWJ1dGVOYW1lcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20obmV3IFNldCh0aGlzLmN1cnJlbnRBdHRyaWJ1dGVOYW1lcy5jb25jYXQodGhpcy5yZWNvcmRlZEF0dHJpYnV0ZU5hbWVzKSkpO1xuICAgIH1cbiAgICBnZXQgY3VycmVudEF0dHJpYnV0ZU5hbWVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnQuYXR0cmlidXRlcykubWFwKChhdHRyaWJ1dGUpID0+IGF0dHJpYnV0ZS5uYW1lKTtcbiAgICB9XG4gICAgZ2V0IHJlY29yZGVkQXR0cmlidXRlTmFtZXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuc3RyaW5nTWFwLmtleXMoKSk7XG4gICAgfVxufVxuXG5jbGFzcyBUb2tlbkxpc3RPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlciA9IG5ldyBBdHRyaWJ1dGVPYnNlcnZlcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCB0aGlzKTtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLnRva2Vuc0J5RWxlbWVudCA9IG5ldyBNdWx0aW1hcCgpO1xuICAgIH1cbiAgICBnZXQgc3RhcnRlZCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIuc3RhcnRlZDtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgcGF1c2UoY2FsbGJhY2spIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5wYXVzZShjYWxsYmFjayk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLnJlZnJlc2goKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBhdHRyaWJ1dGVOYW1lKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5hdHRyaWJ1dGVOYW1lO1xuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZEF0dHJpYnV0ZShlbGVtZW50KSB7XG4gICAgICAgIHRoaXMudG9rZW5zTWF0Y2hlZCh0aGlzLnJlYWRUb2tlbnNGb3JFbGVtZW50KGVsZW1lbnQpKTtcbiAgICB9XG4gICAgZWxlbWVudEF0dHJpYnV0ZVZhbHVlQ2hhbmdlZChlbGVtZW50KSB7XG4gICAgICAgIGNvbnN0IFt1bm1hdGNoZWRUb2tlbnMsIG1hdGNoZWRUb2tlbnNdID0gdGhpcy5yZWZyZXNoVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgdGhpcy50b2tlbnNVbm1hdGNoZWQodW5tYXRjaGVkVG9rZW5zKTtcbiAgICAgICAgdGhpcy50b2tlbnNNYXRjaGVkKG1hdGNoZWRUb2tlbnMpO1xuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQpIHtcbiAgICAgICAgdGhpcy50b2tlbnNVbm1hdGNoZWQodGhpcy50b2tlbnNCeUVsZW1lbnQuZ2V0VmFsdWVzRm9yS2V5KGVsZW1lbnQpKTtcbiAgICB9XG4gICAgdG9rZW5zTWF0Y2hlZCh0b2tlbnMpIHtcbiAgICAgICAgdG9rZW5zLmZvckVhY2goKHRva2VuKSA9PiB0aGlzLnRva2VuTWF0Y2hlZCh0b2tlbikpO1xuICAgIH1cbiAgICB0b2tlbnNVbm1hdGNoZWQodG9rZW5zKSB7XG4gICAgICAgIHRva2Vucy5mb3JFYWNoKCh0b2tlbikgPT4gdGhpcy50b2tlblVubWF0Y2hlZCh0b2tlbikpO1xuICAgIH1cbiAgICB0b2tlbk1hdGNoZWQodG9rZW4pIHtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZS50b2tlbk1hdGNoZWQodG9rZW4pO1xuICAgICAgICB0aGlzLnRva2Vuc0J5RWxlbWVudC5hZGQodG9rZW4uZWxlbWVudCwgdG9rZW4pO1xuICAgIH1cbiAgICB0b2tlblVubWF0Y2hlZCh0b2tlbikge1xuICAgICAgICB0aGlzLmRlbGVnYXRlLnRva2VuVW5tYXRjaGVkKHRva2VuKTtcbiAgICAgICAgdGhpcy50b2tlbnNCeUVsZW1lbnQuZGVsZXRlKHRva2VuLmVsZW1lbnQsIHRva2VuKTtcbiAgICB9XG4gICAgcmVmcmVzaFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCBwcmV2aW91c1Rva2VucyA9IHRoaXMudG9rZW5zQnlFbGVtZW50LmdldFZhbHVlc0ZvcktleShlbGVtZW50KTtcbiAgICAgICAgY29uc3QgY3VycmVudFRva2VucyA9IHRoaXMucmVhZFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgIGNvbnN0IGZpcnN0RGlmZmVyaW5nSW5kZXggPSB6aXAocHJldmlvdXNUb2tlbnMsIGN1cnJlbnRUb2tlbnMpLmZpbmRJbmRleCgoW3ByZXZpb3VzVG9rZW4sIGN1cnJlbnRUb2tlbl0pID0+ICF0b2tlbnNBcmVFcXVhbChwcmV2aW91c1Rva2VuLCBjdXJyZW50VG9rZW4pKTtcbiAgICAgICAgaWYgKGZpcnN0RGlmZmVyaW5nSW5kZXggPT0gLTEpIHtcbiAgICAgICAgICAgIHJldHVybiBbW10sIFtdXTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiBbcHJldmlvdXNUb2tlbnMuc2xpY2UoZmlyc3REaWZmZXJpbmdJbmRleCksIGN1cnJlbnRUb2tlbnMuc2xpY2UoZmlyc3REaWZmZXJpbmdJbmRleCldO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlYWRUb2tlbnNGb3JFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuYXR0cmlidXRlTmFtZTtcbiAgICAgICAgY29uc3QgdG9rZW5TdHJpbmcgPSBlbGVtZW50LmdldEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lKSB8fCBcIlwiO1xuICAgICAgICByZXR1cm4gcGFyc2VUb2tlblN0cmluZyh0b2tlblN0cmluZywgZWxlbWVudCwgYXR0cmlidXRlTmFtZSk7XG4gICAgfVxufVxuZnVuY3Rpb24gcGFyc2VUb2tlblN0cmluZyh0b2tlblN0cmluZywgZWxlbWVudCwgYXR0cmlidXRlTmFtZSkge1xuICAgIHJldHVybiB0b2tlblN0cmluZ1xuICAgICAgICAudHJpbSgpXG4gICAgICAgIC5zcGxpdCgvXFxzKy8pXG4gICAgICAgIC5maWx0ZXIoKGNvbnRlbnQpID0+IGNvbnRlbnQubGVuZ3RoKVxuICAgICAgICAubWFwKChjb250ZW50LCBpbmRleCkgPT4gKHsgZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgY29udGVudCwgaW5kZXggfSkpO1xufVxuZnVuY3Rpb24gemlwKGxlZnQsIHJpZ2h0KSB7XG4gICAgY29uc3QgbGVuZ3RoID0gTWF0aC5tYXgobGVmdC5sZW5ndGgsIHJpZ2h0Lmxlbmd0aCk7XG4gICAgcmV0dXJuIEFycmF5LmZyb20oeyBsZW5ndGggfSwgKF8sIGluZGV4KSA9PiBbbGVmdFtpbmRleF0sIHJpZ2h0W2luZGV4XV0pO1xufVxuZnVuY3Rpb24gdG9rZW5zQXJlRXF1YWwobGVmdCwgcmlnaHQpIHtcbiAgICByZXR1cm4gbGVmdCAmJiByaWdodCAmJiBsZWZ0LmluZGV4ID09IHJpZ2h0LmluZGV4ICYmIGxlZnQuY29udGVudCA9PSByaWdodC5jb250ZW50O1xufVxuXG5jbGFzcyBWYWx1ZUxpc3RPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlciA9IG5ldyBUb2tlbkxpc3RPYnNlcnZlcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCB0aGlzKTtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLnBhcnNlUmVzdWx0c0J5VG9rZW4gPSBuZXcgV2Vha01hcCgpO1xuICAgICAgICB0aGlzLnZhbHVlc0J5VG9rZW5CeUVsZW1lbnQgPSBuZXcgV2Vha01hcCgpO1xuICAgIH1cbiAgICBnZXQgc3RhcnRlZCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuc3RhcnRlZDtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGF0dHJpYnV0ZU5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnRva2VuTGlzdE9ic2VydmVyLmF0dHJpYnV0ZU5hbWU7XG4gICAgfVxuICAgIHRva2VuTWF0Y2hlZCh0b2tlbikge1xuICAgICAgICBjb25zdCB7IGVsZW1lbnQgfSA9IHRva2VuO1xuICAgICAgICBjb25zdCB7IHZhbHVlIH0gPSB0aGlzLmZldGNoUGFyc2VSZXN1bHRGb3JUb2tlbih0b2tlbik7XG4gICAgICAgIGlmICh2YWx1ZSkge1xuICAgICAgICAgICAgdGhpcy5mZXRjaFZhbHVlc0J5VG9rZW5Gb3JFbGVtZW50KGVsZW1lbnQpLnNldCh0b2tlbiwgdmFsdWUpO1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50TWF0Y2hlZFZhbHVlKGVsZW1lbnQsIHZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICB0b2tlblVubWF0Y2hlZCh0b2tlbikge1xuICAgICAgICBjb25zdCB7IGVsZW1lbnQgfSA9IHRva2VuO1xuICAgICAgICBjb25zdCB7IHZhbHVlIH0gPSB0aGlzLmZldGNoUGFyc2VSZXN1bHRGb3JUb2tlbih0b2tlbik7XG4gICAgICAgIGlmICh2YWx1ZSkge1xuICAgICAgICAgICAgdGhpcy5mZXRjaFZhbHVlc0J5VG9rZW5Gb3JFbGVtZW50KGVsZW1lbnQpLmRlbGV0ZSh0b2tlbik7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRVbm1hdGNoZWRWYWx1ZShlbGVtZW50LCB2YWx1ZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZmV0Y2hQYXJzZVJlc3VsdEZvclRva2VuKHRva2VuKSB7XG4gICAgICAgIGxldCBwYXJzZVJlc3VsdCA9IHRoaXMucGFyc2VSZXN1bHRzQnlUb2tlbi5nZXQodG9rZW4pO1xuICAgICAgICBpZiAoIXBhcnNlUmVzdWx0KSB7XG4gICAgICAgICAgICBwYXJzZVJlc3VsdCA9IHRoaXMucGFyc2VUb2tlbih0b2tlbik7XG4gICAgICAgICAgICB0aGlzLnBhcnNlUmVzdWx0c0J5VG9rZW4uc2V0KHRva2VuLCBwYXJzZVJlc3VsdCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHBhcnNlUmVzdWx0O1xuICAgIH1cbiAgICBmZXRjaFZhbHVlc0J5VG9rZW5Gb3JFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgbGV0IHZhbHVlc0J5VG9rZW4gPSB0aGlzLnZhbHVlc0J5VG9rZW5CeUVsZW1lbnQuZ2V0KGVsZW1lbnQpO1xuICAgICAgICBpZiAoIXZhbHVlc0J5VG9rZW4pIHtcbiAgICAgICAgICAgIHZhbHVlc0J5VG9rZW4gPSBuZXcgTWFwKCk7XG4gICAgICAgICAgICB0aGlzLnZhbHVlc0J5VG9rZW5CeUVsZW1lbnQuc2V0KGVsZW1lbnQsIHZhbHVlc0J5VG9rZW4pO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiB2YWx1ZXNCeVRva2VuO1xuICAgIH1cbiAgICBwYXJzZVRva2VuKHRva2VuKSB7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICBjb25zdCB2YWx1ZSA9IHRoaXMuZGVsZWdhdGUucGFyc2VWYWx1ZUZvclRva2VuKHRva2VuKTtcbiAgICAgICAgICAgIHJldHVybiB7IHZhbHVlIH07XG4gICAgICAgIH1cbiAgICAgICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICByZXR1cm4geyBlcnJvciB9O1xuICAgICAgICB9XG4gICAgfVxufVxuXG5jbGFzcyBCaW5kaW5nT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5iaW5kaW5nc0J5QWN0aW9uID0gbmV3IE1hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnZhbHVlTGlzdE9ic2VydmVyKSB7XG4gICAgICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyID0gbmV3IFZhbHVlTGlzdE9ic2VydmVyKHRoaXMuZWxlbWVudCwgdGhpcy5hY3Rpb25BdHRyaWJ1dGUsIHRoaXMpO1xuICAgICAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIGlmICh0aGlzLnZhbHVlTGlzdE9ic2VydmVyKSB7XG4gICAgICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICAgICAgICAgIGRlbGV0ZSB0aGlzLnZhbHVlTGlzdE9ic2VydmVyO1xuICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0QWxsQWN0aW9ucygpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCBhY3Rpb25BdHRyaWJ1dGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjaGVtYS5hY3Rpb25BdHRyaWJ1dGU7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NoZW1hO1xuICAgIH1cbiAgICBnZXQgYmluZGluZ3MoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuYmluZGluZ3NCeUFjdGlvbi52YWx1ZXMoKSk7XG4gICAgfVxuICAgIGNvbm5lY3RBY3Rpb24oYWN0aW9uKSB7XG4gICAgICAgIGNvbnN0IGJpbmRpbmcgPSBuZXcgQmluZGluZyh0aGlzLmNvbnRleHQsIGFjdGlvbik7XG4gICAgICAgIHRoaXMuYmluZGluZ3NCeUFjdGlvbi5zZXQoYWN0aW9uLCBiaW5kaW5nKTtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZS5iaW5kaW5nQ29ubmVjdGVkKGJpbmRpbmcpO1xuICAgIH1cbiAgICBkaXNjb25uZWN0QWN0aW9uKGFjdGlvbikge1xuICAgICAgICBjb25zdCBiaW5kaW5nID0gdGhpcy5iaW5kaW5nc0J5QWN0aW9uLmdldChhY3Rpb24pO1xuICAgICAgICBpZiAoYmluZGluZykge1xuICAgICAgICAgICAgdGhpcy5iaW5kaW5nc0J5QWN0aW9uLmRlbGV0ZShhY3Rpb24pO1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5iaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RBbGxBY3Rpb25zKCkge1xuICAgICAgICB0aGlzLmJpbmRpbmdzLmZvckVhY2goKGJpbmRpbmcpID0+IHRoaXMuZGVsZWdhdGUuYmluZGluZ0Rpc2Nvbm5lY3RlZChiaW5kaW5nLCB0cnVlKSk7XG4gICAgICAgIHRoaXMuYmluZGluZ3NCeUFjdGlvbi5jbGVhcigpO1xuICAgIH1cbiAgICBwYXJzZVZhbHVlRm9yVG9rZW4odG9rZW4pIHtcbiAgICAgICAgY29uc3QgYWN0aW9uID0gQWN0aW9uLmZvclRva2VuKHRva2VuLCB0aGlzLnNjaGVtYSk7XG4gICAgICAgIGlmIChhY3Rpb24uaWRlbnRpZmllciA9PSB0aGlzLmlkZW50aWZpZXIpIHtcbiAgICAgICAgICAgIHJldHVybiBhY3Rpb247XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWRWYWx1ZShlbGVtZW50LCBhY3Rpb24pIHtcbiAgICAgICAgdGhpcy5jb25uZWN0QWN0aW9uKGFjdGlvbik7XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRWYWx1ZShlbGVtZW50LCBhY3Rpb24pIHtcbiAgICAgICAgdGhpcy5kaXNjb25uZWN0QWN0aW9uKGFjdGlvbik7XG4gICAgfVxufVxuXG5jbGFzcyBWYWx1ZU9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3Rvcihjb250ZXh0LCByZWNlaXZlcikge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLnJlY2VpdmVyID0gcmVjZWl2ZXI7XG4gICAgICAgIHRoaXMuc3RyaW5nTWFwT2JzZXJ2ZXIgPSBuZXcgU3RyaW5nTWFwT2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzKTtcbiAgICAgICAgdGhpcy52YWx1ZURlc2NyaXB0b3JNYXAgPSB0aGlzLmNvbnRyb2xsZXIudmFsdWVEZXNjcmlwdG9yTWFwO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXBPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB0aGlzLmludm9rZUNoYW5nZWRDYWxsYmFja3NGb3JEZWZhdWx0VmFsdWVzKCk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuc3RyaW5nTWFwT2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5jb250cm9sbGVyO1xuICAgIH1cbiAgICBnZXRTdHJpbmdNYXBLZXlGb3JBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBpZiAoYXR0cmlidXRlTmFtZSBpbiB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcCkge1xuICAgICAgICAgICAgcmV0dXJuIHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwW2F0dHJpYnV0ZU5hbWVdLm5hbWU7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RyaW5nTWFwS2V5QWRkZWQoa2V5LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcFthdHRyaWJ1dGVOYW1lXTtcbiAgICAgICAgaWYgKCF0aGlzLmhhc1ZhbHVlKGtleSkpIHtcbiAgICAgICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKGtleSwgZGVzY3JpcHRvci53cml0ZXIodGhpcy5yZWNlaXZlcltrZXldKSwgZGVzY3JpcHRvci53cml0ZXIoZGVzY3JpcHRvci5kZWZhdWx0VmFsdWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBWYWx1ZUNoYW5nZWQodmFsdWUsIG5hbWUsIG9sZFZhbHVlKSB7XG4gICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck5hbWVNYXBbbmFtZV07XG4gICAgICAgIGlmICh2YWx1ZSA9PT0gbnVsbClcbiAgICAgICAgICAgIHJldHVybjtcbiAgICAgICAgaWYgKG9sZFZhbHVlID09PSBudWxsKSB7XG4gICAgICAgICAgICBvbGRWYWx1ZSA9IGRlc2NyaXB0b3Iud3JpdGVyKGRlc2NyaXB0b3IuZGVmYXVsdFZhbHVlKTtcbiAgICAgICAgfVxuICAgICAgICB0aGlzLmludm9rZUNoYW5nZWRDYWxsYmFjayhuYW1lLCB2YWx1ZSwgb2xkVmFsdWUpO1xuICAgIH1cbiAgICBzdHJpbmdNYXBLZXlSZW1vdmVkKGtleSwgYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFtrZXldO1xuICAgICAgICBpZiAodGhpcy5oYXNWYWx1ZShrZXkpKSB7XG4gICAgICAgICAgICB0aGlzLmludm9rZUNoYW5nZWRDYWxsYmFjayhrZXksIGRlc2NyaXB0b3Iud3JpdGVyKHRoaXMucmVjZWl2ZXJba2V5XSksIG9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKGtleSwgZGVzY3JpcHRvci53cml0ZXIoZGVzY3JpcHRvci5kZWZhdWx0VmFsdWUpLCBvbGRWYWx1ZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgaW52b2tlQ2hhbmdlZENhbGxiYWNrc0ZvckRlZmF1bHRWYWx1ZXMoKSB7XG4gICAgICAgIGZvciAoY29uc3QgeyBrZXksIG5hbWUsIGRlZmF1bHRWYWx1ZSwgd3JpdGVyIH0gb2YgdGhpcy52YWx1ZURlc2NyaXB0b3JzKSB7XG4gICAgICAgICAgICBpZiAoZGVmYXVsdFZhbHVlICE9IHVuZGVmaW5lZCAmJiAhdGhpcy5jb250cm9sbGVyLmRhdGEuaGFzKGtleSkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmludm9rZUNoYW5nZWRDYWxsYmFjayhuYW1lLCB3cml0ZXIoZGVmYXVsdFZhbHVlKSwgdW5kZWZpbmVkKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBpbnZva2VDaGFuZ2VkQ2FsbGJhY2sobmFtZSwgcmF3VmFsdWUsIHJhd09sZFZhbHVlKSB7XG4gICAgICAgIGNvbnN0IGNoYW5nZWRNZXRob2ROYW1lID0gYCR7bmFtZX1DaGFuZ2VkYDtcbiAgICAgICAgY29uc3QgY2hhbmdlZE1ldGhvZCA9IHRoaXMucmVjZWl2ZXJbY2hhbmdlZE1ldGhvZE5hbWVdO1xuICAgICAgICBpZiAodHlwZW9mIGNoYW5nZWRNZXRob2QgPT0gXCJmdW5jdGlvblwiKSB7XG4gICAgICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JOYW1lTWFwW25hbWVdO1xuICAgICAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgICAgICBjb25zdCB2YWx1ZSA9IGRlc2NyaXB0b3IucmVhZGVyKHJhd1ZhbHVlKTtcbiAgICAgICAgICAgICAgICBsZXQgb2xkVmFsdWUgPSByYXdPbGRWYWx1ZTtcbiAgICAgICAgICAgICAgICBpZiAocmF3T2xkVmFsdWUpIHtcbiAgICAgICAgICAgICAgICAgICAgb2xkVmFsdWUgPSBkZXNjcmlwdG9yLnJlYWRlcihyYXdPbGRWYWx1ZSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGNoYW5nZWRNZXRob2QuY2FsbCh0aGlzLnJlY2VpdmVyLCB2YWx1ZSwgb2xkVmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICAgICAgaWYgKGVycm9yIGluc3RhbmNlb2YgVHlwZUVycm9yKSB7XG4gICAgICAgICAgICAgICAgICAgIGVycm9yLm1lc3NhZ2UgPSBgU3RpbXVsdXMgVmFsdWUgXCIke3RoaXMuY29udGV4dC5pZGVudGlmaWVyfS4ke2Rlc2NyaXB0b3IubmFtZX1cIiAtICR7ZXJyb3IubWVzc2FnZX1gO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB0aHJvdyBlcnJvcjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgdmFsdWVEZXNjcmlwdG9ycygpIHtcbiAgICAgICAgY29uc3QgeyB2YWx1ZURlc2NyaXB0b3JNYXAgfSA9IHRoaXM7XG4gICAgICAgIHJldHVybiBPYmplY3Qua2V5cyh2YWx1ZURlc2NyaXB0b3JNYXApLm1hcCgoa2V5KSA9PiB2YWx1ZURlc2NyaXB0b3JNYXBba2V5XSk7XG4gICAgfVxuICAgIGdldCB2YWx1ZURlc2NyaXB0b3JOYW1lTWFwKCkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9ycyA9IHt9O1xuICAgICAgICBPYmplY3Qua2V5cyh0aGlzLnZhbHVlRGVzY3JpcHRvck1hcCkuZm9yRWFjaCgoa2V5KSA9PiB7XG4gICAgICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JNYXBba2V5XTtcbiAgICAgICAgICAgIGRlc2NyaXB0b3JzW2Rlc2NyaXB0b3IubmFtZV0gPSBkZXNjcmlwdG9yO1xuICAgICAgICB9KTtcbiAgICAgICAgcmV0dXJuIGRlc2NyaXB0b3JzO1xuICAgIH1cbiAgICBoYXNWYWx1ZShhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck5hbWVNYXBbYXR0cmlidXRlTmFtZV07XG4gICAgICAgIGNvbnN0IGhhc01ldGhvZE5hbWUgPSBgaGFzJHtjYXBpdGFsaXplKGRlc2NyaXB0b3IubmFtZSl9YDtcbiAgICAgICAgcmV0dXJuIHRoaXMucmVjZWl2ZXJbaGFzTWV0aG9kTmFtZV07XG4gICAgfVxufVxuXG5jbGFzcyBUYXJnZXRPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLnRhcmdldHNCeU5hbWUgPSBuZXcgTXVsdGltYXAoKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy50b2tlbkxpc3RPYnNlcnZlcikge1xuICAgICAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlciA9IG5ldyBUb2tlbkxpc3RPYnNlcnZlcih0aGlzLmVsZW1lbnQsIHRoaXMuYXR0cmlidXRlTmFtZSwgdGhpcyk7XG4gICAgICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdEFsbFRhcmdldHMoKTtcbiAgICAgICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICAgICAgZGVsZXRlIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXI7XG4gICAgICAgIH1cbiAgICB9XG4gICAgdG9rZW5NYXRjaGVkKHsgZWxlbWVudCwgY29udGVudDogbmFtZSB9KSB7XG4gICAgICAgIGlmICh0aGlzLnNjb3BlLmNvbnRhaW5zRWxlbWVudChlbGVtZW50KSkge1xuICAgICAgICAgICAgdGhpcy5jb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHRva2VuVW5tYXRjaGVkKHsgZWxlbWVudCwgY29udGVudDogbmFtZSB9KSB7XG4gICAgICAgIHRoaXMuZGlzY29ubmVjdFRhcmdldChlbGVtZW50LCBuYW1lKTtcbiAgICB9XG4gICAgY29ubmVjdFRhcmdldChlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgaWYgKCF0aGlzLnRhcmdldHNCeU5hbWUuaGFzKG5hbWUsIGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLnRhcmdldHNCeU5hbWUuYWRkKG5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy50b2tlbkxpc3RPYnNlcnZlcikgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUudGFyZ2V0Q29ubmVjdGVkKGVsZW1lbnQsIG5hbWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpIHtcbiAgICAgICAgdmFyIF9hO1xuICAgICAgICBpZiAodGhpcy50YXJnZXRzQnlOYW1lLmhhcyhuYW1lLCBlbGVtZW50KSkge1xuICAgICAgICAgICAgdGhpcy50YXJnZXRzQnlOYW1lLmRlbGV0ZShuYW1lLCBlbGVtZW50KTtcbiAgICAgICAgICAgIChfYSA9IHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIpID09PSBudWxsIHx8IF9hID09PSB2b2lkIDAgPyB2b2lkIDAgOiBfYS5wYXVzZSgoKSA9PiB0aGlzLmRlbGVnYXRlLnRhcmdldERpc2Nvbm5lY3RlZChlbGVtZW50LCBuYW1lKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZGlzY29ubmVjdEFsbFRhcmdldHMoKSB7XG4gICAgICAgIGZvciAoY29uc3QgbmFtZSBvZiB0aGlzLnRhcmdldHNCeU5hbWUua2V5cykge1xuICAgICAgICAgICAgZm9yIChjb25zdCBlbGVtZW50IG9mIHRoaXMudGFyZ2V0c0J5TmFtZS5nZXRWYWx1ZXNGb3JLZXkobmFtZSkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGF0dHJpYnV0ZU5hbWUoKSB7XG4gICAgICAgIHJldHVybiBgZGF0YS0ke3RoaXMuY29udGV4dC5pZGVudGlmaWVyfS10YXJnZXRgO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgc2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NvcGU7XG4gICAgfVxufVxuXG5mdW5jdGlvbiByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgcHJvcGVydHlOYW1lKSB7XG4gICAgY29uc3QgYW5jZXN0b3JzID0gZ2V0QW5jZXN0b3JzRm9yQ29uc3RydWN0b3IoY29uc3RydWN0b3IpO1xuICAgIHJldHVybiBBcnJheS5mcm9tKGFuY2VzdG9ycy5yZWR1Y2UoKHZhbHVlcywgY29uc3RydWN0b3IpID0+IHtcbiAgICAgICAgZ2V0T3duU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIHByb3BlcnR5TmFtZSkuZm9yRWFjaCgobmFtZSkgPT4gdmFsdWVzLmFkZChuYW1lKSk7XG4gICAgICAgIHJldHVybiB2YWx1ZXM7XG4gICAgfSwgbmV3IFNldCgpKSk7XG59XG5mdW5jdGlvbiByZWFkSW5oZXJpdGFibGVTdGF0aWNPYmplY3RQYWlycyhjb25zdHJ1Y3RvciwgcHJvcGVydHlOYW1lKSB7XG4gICAgY29uc3QgYW5jZXN0b3JzID0gZ2V0QW5jZXN0b3JzRm9yQ29uc3RydWN0b3IoY29uc3RydWN0b3IpO1xuICAgIHJldHVybiBhbmNlc3RvcnMucmVkdWNlKChwYWlycywgY29uc3RydWN0b3IpID0+IHtcbiAgICAgICAgcGFpcnMucHVzaCguLi5nZXRPd25TdGF0aWNPYmplY3RQYWlycyhjb25zdHJ1Y3RvciwgcHJvcGVydHlOYW1lKSk7XG4gICAgICAgIHJldHVybiBwYWlycztcbiAgICB9LCBbXSk7XG59XG5mdW5jdGlvbiBnZXRBbmNlc3RvcnNGb3JDb25zdHJ1Y3Rvcihjb25zdHJ1Y3Rvcikge1xuICAgIGNvbnN0IGFuY2VzdG9ycyA9IFtdO1xuICAgIHdoaWxlIChjb25zdHJ1Y3Rvcikge1xuICAgICAgICBhbmNlc3RvcnMucHVzaChjb25zdHJ1Y3Rvcik7XG4gICAgICAgIGNvbnN0cnVjdG9yID0gT2JqZWN0LmdldFByb3RvdHlwZU9mKGNvbnN0cnVjdG9yKTtcbiAgICB9XG4gICAgcmV0dXJuIGFuY2VzdG9ycy5yZXZlcnNlKCk7XG59XG5mdW5jdGlvbiBnZXRPd25TdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgcHJvcGVydHlOYW1lKSB7XG4gICAgY29uc3QgZGVmaW5pdGlvbiA9IGNvbnN0cnVjdG9yW3Byb3BlcnR5TmFtZV07XG4gICAgcmV0dXJuIEFycmF5LmlzQXJyYXkoZGVmaW5pdGlvbikgPyBkZWZpbml0aW9uIDogW107XG59XG5mdW5jdGlvbiBnZXRPd25TdGF0aWNPYmplY3RQYWlycyhjb25zdHJ1Y3RvciwgcHJvcGVydHlOYW1lKSB7XG4gICAgY29uc3QgZGVmaW5pdGlvbiA9IGNvbnN0cnVjdG9yW3Byb3BlcnR5TmFtZV07XG4gICAgcmV0dXJuIGRlZmluaXRpb24gPyBPYmplY3Qua2V5cyhkZWZpbml0aW9uKS5tYXAoKGtleSkgPT4gW2tleSwgZGVmaW5pdGlvbltrZXldXSkgOiBbXTtcbn1cblxuY2xhc3MgT3V0bGV0T2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMub3V0bGV0c0J5TmFtZSA9IG5ldyBNdWx0aW1hcCgpO1xuICAgICAgICB0aGlzLm91dGxldEVsZW1lbnRzQnlOYW1lID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcCA9IG5ldyBNYXAoKTtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcCA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm91dGxldERlZmluaXRpb25zLmZvckVhY2goKG91dGxldE5hbWUpID0+IHtcbiAgICAgICAgICAgICAgICB0aGlzLnNldHVwU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgICAgICAgICB0aGlzLnNldHVwQXR0cmlidXRlT2JzZXJ2ZXJGb3JPdXRsZXQob3V0bGV0TmFtZSk7XG4gICAgICAgICAgICB9KTtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IHRydWU7XG4gICAgICAgICAgICB0aGlzLmRlcGVuZGVudENvbnRleHRzLmZvckVhY2goKGNvbnRleHQpID0+IGNvbnRleHQucmVmcmVzaCgpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuZm9yRWFjaCgob2JzZXJ2ZXIpID0+IG9ic2VydmVyLnJlZnJlc2goKSk7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuZm9yRWFjaCgob2JzZXJ2ZXIpID0+IG9ic2VydmVyLnJlZnJlc2goKSk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0QWxsT3V0bGV0cygpO1xuICAgICAgICAgICAgdGhpcy5zdG9wU2VsZWN0b3JPYnNlcnZlcnMoKTtcbiAgICAgICAgICAgIHRoaXMuc3RvcEF0dHJpYnV0ZU9ic2VydmVycygpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0b3BTZWxlY3Rvck9ic2VydmVycygpIHtcbiAgICAgICAgaWYgKHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5zaXplID4gMCkge1xuICAgICAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmZvckVhY2goKG9ic2VydmVyKSA9PiBvYnNlcnZlci5zdG9wKCkpO1xuICAgICAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmNsZWFyKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcEF0dHJpYnV0ZU9ic2VydmVycygpIHtcbiAgICAgICAgaWYgKHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuc2l6ZSA+IDApIHtcbiAgICAgICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuZm9yRWFjaCgob2JzZXJ2ZXIpID0+IG9ic2VydmVyLnN0b3AoKSk7XG4gICAgICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwLmNsZWFyKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIF9zZWxlY3RvciwgeyBvdXRsZXROYW1lIH0pIHtcbiAgICAgICAgY29uc3Qgb3V0bGV0ID0gdGhpcy5nZXRPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIGlmIChvdXRsZXQpIHtcbiAgICAgICAgICAgIHRoaXMuY29ubmVjdE91dGxldChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHNlbGVjdG9yVW5tYXRjaGVkKGVsZW1lbnQsIF9zZWxlY3RvciwgeyBvdXRsZXROYW1lIH0pIHtcbiAgICAgICAgY29uc3Qgb3V0bGV0ID0gdGhpcy5nZXRPdXRsZXRGcm9tTWFwKGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgICAgICBpZiAob3V0bGV0KSB7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzZWxlY3Rvck1hdGNoRWxlbWVudChlbGVtZW50LCB7IG91dGxldE5hbWUgfSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuc2VsZWN0b3Iob3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IGhhc091dGxldCA9IHRoaXMuaGFzT3V0bGV0KGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgICAgICBjb25zdCBoYXNPdXRsZXRDb250cm9sbGVyID0gZWxlbWVudC5tYXRjaGVzKGBbJHt0aGlzLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlfX49JHtvdXRsZXROYW1lfV1gKTtcbiAgICAgICAgaWYgKHNlbGVjdG9yKSB7XG4gICAgICAgICAgICByZXR1cm4gaGFzT3V0bGV0ICYmIGhhc091dGxldENvbnRyb2xsZXIgJiYgZWxlbWVudC5tYXRjaGVzKHNlbGVjdG9yKTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZEF0dHJpYnV0ZShfZWxlbWVudCwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBjb25zdCBvdXRsZXROYW1lID0gdGhpcy5nZXRPdXRsZXROYW1lRnJvbU91dGxldEF0dHJpYnV0ZU5hbWUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIGlmIChvdXRsZXROYW1lKSB7XG4gICAgICAgICAgICB0aGlzLnVwZGF0ZVNlbGVjdG9yT2JzZXJ2ZXJGb3JPdXRsZXQob3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudEF0dHJpYnV0ZVZhbHVlQ2hhbmdlZChfZWxlbWVudCwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBjb25zdCBvdXRsZXROYW1lID0gdGhpcy5nZXRPdXRsZXROYW1lRnJvbU91dGxldEF0dHJpYnV0ZU5hbWUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIGlmIChvdXRsZXROYW1lKSB7XG4gICAgICAgICAgICB0aGlzLnVwZGF0ZVNlbGVjdG9yT2JzZXJ2ZXJGb3JPdXRsZXQob3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZEF0dHJpYnV0ZShfZWxlbWVudCwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBjb25zdCBvdXRsZXROYW1lID0gdGhpcy5nZXRPdXRsZXROYW1lRnJvbU91dGxldEF0dHJpYnV0ZU5hbWUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIGlmIChvdXRsZXROYW1lKSB7XG4gICAgICAgICAgICB0aGlzLnVwZGF0ZVNlbGVjdG9yT2JzZXJ2ZXJGb3JPdXRsZXQob3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgY29ubmVjdE91dGxldChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpIHtcbiAgICAgICAgdmFyIF9hO1xuICAgICAgICBpZiAoIXRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuaGFzKG91dGxldE5hbWUsIGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLm91dGxldHNCeU5hbWUuYWRkKG91dGxldE5hbWUsIG91dGxldCk7XG4gICAgICAgICAgICB0aGlzLm91dGxldEVsZW1lbnRzQnlOYW1lLmFkZChvdXRsZXROYW1lLCBlbGVtZW50KTtcbiAgICAgICAgICAgIChfYSA9IHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5nZXQob3V0bGV0TmFtZSkpID09PSBudWxsIHx8IF9hID09PSB2b2lkIDAgPyB2b2lkIDAgOiBfYS5wYXVzZSgoKSA9PiB0aGlzLmRlbGVnYXRlLm91dGxldENvbm5lY3RlZChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICh0aGlzLm91dGxldEVsZW1lbnRzQnlOYW1lLmhhcyhvdXRsZXROYW1lLCBlbGVtZW50KSkge1xuICAgICAgICAgICAgdGhpcy5vdXRsZXRzQnlOYW1lLmRlbGV0ZShvdXRsZXROYW1lLCBvdXRsZXQpO1xuICAgICAgICAgICAgdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5kZWxldGUob3V0bGV0TmFtZSwgZWxlbWVudCk7XG4gICAgICAgICAgICAoX2EgPSB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXBcbiAgICAgICAgICAgICAgICAuZ2V0KG91dGxldE5hbWUpKSA9PT0gbnVsbCB8fCBfYSA9PT0gdm9pZCAwID8gdm9pZCAwIDogX2EucGF1c2UoKCkgPT4gdGhpcy5kZWxlZ2F0ZS5vdXRsZXREaXNjb25uZWN0ZWQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZGlzY29ubmVjdEFsbE91dGxldHMoKSB7XG4gICAgICAgIGZvciAoY29uc3Qgb3V0bGV0TmFtZSBvZiB0aGlzLm91dGxldEVsZW1lbnRzQnlOYW1lLmtleXMpIHtcbiAgICAgICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiB0aGlzLm91dGxldEVsZW1lbnRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShvdXRsZXROYW1lKSkge1xuICAgICAgICAgICAgICAgIGZvciAoY29uc3Qgb3V0bGV0IG9mIHRoaXMub3V0bGV0c0J5TmFtZS5nZXRWYWx1ZXNGb3JLZXkob3V0bGV0TmFtZSkpIHtcbiAgICAgICAgICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHVwZGF0ZVNlbGVjdG9yT2JzZXJ2ZXJGb3JPdXRsZXQob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBvYnNlcnZlciA9IHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5nZXQob3V0bGV0TmFtZSk7XG4gICAgICAgIGlmIChvYnNlcnZlcikge1xuICAgICAgICAgICAgb2JzZXJ2ZXIuc2VsZWN0b3IgPSB0aGlzLnNlbGVjdG9yKG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHNldHVwU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5zZWxlY3RvcihvdXRsZXROYW1lKTtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3JPYnNlcnZlciA9IG5ldyBTZWxlY3Rvck9ic2VydmVyKGRvY3VtZW50LmJvZHksIHNlbGVjdG9yLCB0aGlzLCB7IG91dGxldE5hbWUgfSk7XG4gICAgICAgIHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5zZXQob3V0bGV0TmFtZSwgc2VsZWN0b3JPYnNlcnZlcik7XG4gICAgICAgIHNlbGVjdG9yT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgc2V0dXBBdHRyaWJ1dGVPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLmF0dHJpYnV0ZU5hbWVGb3JPdXRsZXROYW1lKG91dGxldE5hbWUpO1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVPYnNlcnZlciA9IG5ldyBBdHRyaWJ1dGVPYnNlcnZlcih0aGlzLnNjb3BlLmVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwLnNldChvdXRsZXROYW1lLCBhdHRyaWJ1dGVPYnNlcnZlcik7XG4gICAgICAgIGF0dHJpYnV0ZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHNlbGVjdG9yKG91dGxldE5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUub3V0bGV0cy5nZXRTZWxlY3RvckZvck91dGxldE5hbWUob3V0bGV0TmFtZSk7XG4gICAgfVxuICAgIGF0dHJpYnV0ZU5hbWVGb3JPdXRsZXROYW1lKG91dGxldE5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuc2NoZW1hLm91dGxldEF0dHJpYnV0ZUZvclNjb3BlKHRoaXMuaWRlbnRpZmllciwgb3V0bGV0TmFtZSk7XG4gICAgfVxuICAgIGdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm91dGxldERlZmluaXRpb25zLmZpbmQoKG91dGxldE5hbWUpID0+IHRoaXMuYXR0cmlidXRlTmFtZUZvck91dGxldE5hbWUob3V0bGV0TmFtZSkgPT09IGF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbiAgICBnZXQgb3V0bGV0RGVwZW5kZW5jaWVzKCkge1xuICAgICAgICBjb25zdCBkZXBlbmRlbmNpZXMgPSBuZXcgTXVsdGltYXAoKTtcbiAgICAgICAgdGhpcy5yb3V0ZXIubW9kdWxlcy5mb3JFYWNoKChtb2R1bGUpID0+IHtcbiAgICAgICAgICAgIGNvbnN0IGNvbnN0cnVjdG9yID0gbW9kdWxlLmRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yO1xuICAgICAgICAgICAgY29uc3Qgb3V0bGV0cyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBcIm91dGxldHNcIik7XG4gICAgICAgICAgICBvdXRsZXRzLmZvckVhY2goKG91dGxldCkgPT4gZGVwZW5kZW5jaWVzLmFkZChvdXRsZXQsIG1vZHVsZS5pZGVudGlmaWVyKSk7XG4gICAgICAgIH0pO1xuICAgICAgICByZXR1cm4gZGVwZW5kZW5jaWVzO1xuICAgIH1cbiAgICBnZXQgb3V0bGV0RGVmaW5pdGlvbnMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm91dGxldERlcGVuZGVuY2llcy5nZXRLZXlzRm9yVmFsdWUodGhpcy5pZGVudGlmaWVyKTtcbiAgICB9XG4gICAgZ2V0IGRlcGVuZGVudENvbnRyb2xsZXJJZGVudGlmaWVycygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0RGVwZW5kZW5jaWVzLmdldFZhbHVlc0ZvcktleSh0aGlzLmlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBnZXQgZGVwZW5kZW50Q29udGV4dHMoKSB7XG4gICAgICAgIGNvbnN0IGlkZW50aWZpZXJzID0gdGhpcy5kZXBlbmRlbnRDb250cm9sbGVySWRlbnRpZmllcnM7XG4gICAgICAgIHJldHVybiB0aGlzLnJvdXRlci5jb250ZXh0cy5maWx0ZXIoKGNvbnRleHQpID0+IGlkZW50aWZpZXJzLmluY2x1ZGVzKGNvbnRleHQuaWRlbnRpZmllcikpO1xuICAgIH1cbiAgICBoYXNPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gISF0aGlzLmdldE91dGxldChlbGVtZW50LCBvdXRsZXROYW1lKSB8fCAhIXRoaXMuZ2V0T3V0bGV0RnJvbU1hcChlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgZ2V0T3V0bGV0KGVsZW1lbnQsIG91dGxldE5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24uZ2V0Q29udHJvbGxlckZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBnZXRPdXRsZXRGcm9tTWFwKGVsZW1lbnQsIG91dGxldE5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0c0J5TmFtZS5nZXRWYWx1ZXNGb3JLZXkob3V0bGV0TmFtZSkuZmluZCgob3V0bGV0KSA9PiBvdXRsZXQuZWxlbWVudCA9PT0gZWxlbWVudCk7XG4gICAgfVxuICAgIGdldCBzY29wZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY29wZTtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY2hlbWE7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCBhcHBsaWNhdGlvbigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5hcHBsaWNhdGlvbjtcbiAgICB9XG4gICAgZ2V0IHJvdXRlcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24ucm91dGVyO1xuICAgIH1cbn1cblxuY2xhc3MgQ29udGV4dCB7XG4gICAgY29uc3RydWN0b3IobW9kdWxlLCBzY29wZSkge1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkgPSAoZnVuY3Rpb25OYW1lLCBkZXRhaWwgPSB7fSkgPT4ge1xuICAgICAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50IH0gPSB0aGlzO1xuICAgICAgICAgICAgZGV0YWlsID0gT2JqZWN0LmFzc2lnbih7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXIsIGVsZW1lbnQgfSwgZGV0YWlsKTtcbiAgICAgICAgICAgIHRoaXMuYXBwbGljYXRpb24ubG9nRGVidWdBY3Rpdml0eSh0aGlzLmlkZW50aWZpZXIsIGZ1bmN0aW9uTmFtZSwgZGV0YWlsKTtcbiAgICAgICAgfTtcbiAgICAgICAgdGhpcy5tb2R1bGUgPSBtb2R1bGU7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICAgICAgdGhpcy5jb250cm9sbGVyID0gbmV3IG1vZHVsZS5jb250cm9sbGVyQ29uc3RydWN0b3IodGhpcyk7XG4gICAgICAgIHRoaXMuYmluZGluZ09ic2VydmVyID0gbmV3IEJpbmRpbmdPYnNlcnZlcih0aGlzLCB0aGlzLmRpc3BhdGNoZXIpO1xuICAgICAgICB0aGlzLnZhbHVlT2JzZXJ2ZXIgPSBuZXcgVmFsdWVPYnNlcnZlcih0aGlzLCB0aGlzLmNvbnRyb2xsZXIpO1xuICAgICAgICB0aGlzLnRhcmdldE9ic2VydmVyID0gbmV3IFRhcmdldE9ic2VydmVyKHRoaXMsIHRoaXMpO1xuICAgICAgICB0aGlzLm91dGxldE9ic2VydmVyID0gbmV3IE91dGxldE9ic2VydmVyKHRoaXMsIHRoaXMpO1xuICAgICAgICB0cnkge1xuICAgICAgICAgICAgdGhpcy5jb250cm9sbGVyLmluaXRpYWxpemUoKTtcbiAgICAgICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImluaXRpYWxpemVcIik7XG4gICAgICAgIH1cbiAgICAgICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICB0aGlzLmhhbmRsZUVycm9yKGVycm9yLCBcImluaXRpYWxpemluZyBjb250cm9sbGVyXCIpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGNvbm5lY3QoKSB7XG4gICAgICAgIHRoaXMuYmluZGluZ09ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMudmFsdWVPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB0aGlzLnRhcmdldE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMub3V0bGV0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIHRoaXMuY29udHJvbGxlci5jb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJjb25uZWN0XCIpO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgdGhpcy5oYW5kbGVFcnJvcihlcnJvciwgXCJjb25uZWN0aW5nIGNvbnRyb2xsZXJcIik7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3QoKSB7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLmNvbnRyb2xsZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiZGlzY29ubmVjdFwiKTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHRoaXMuaGFuZGxlRXJyb3IoZXJyb3IsIFwiZGlzY29ubmVjdGluZyBjb250cm9sbGVyXCIpO1xuICAgICAgICB9XG4gICAgICAgIHRoaXMub3V0bGV0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLnRhcmdldE9ic2VydmVyLnN0b3AoKTtcbiAgICAgICAgdGhpcy52YWx1ZU9ic2VydmVyLnN0b3AoKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nT2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICBnZXQgYXBwbGljYXRpb24oKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm1vZHVsZS5hcHBsaWNhdGlvbjtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm1vZHVsZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5zY2hlbWE7XG4gICAgfVxuICAgIGdldCBkaXNwYXRjaGVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5kaXNwYXRjaGVyO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IHBhcmVudEVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnQucGFyZW50RWxlbWVudDtcbiAgICB9XG4gICAgaGFuZGxlRXJyb3IoZXJyb3IsIG1lc3NhZ2UsIGRldGFpbCA9IHt9KSB7XG4gICAgICAgIGNvbnN0IHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9ID0gdGhpcztcbiAgICAgICAgZGV0YWlsID0gT2JqZWN0LmFzc2lnbih7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXIsIGVsZW1lbnQgfSwgZGV0YWlsKTtcbiAgICAgICAgdGhpcy5hcHBsaWNhdGlvbi5oYW5kbGVFcnJvcihlcnJvciwgYEVycm9yICR7bWVzc2FnZX1gLCBkZXRhaWwpO1xuICAgIH1cbiAgICB0YXJnZXRDb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZX1UYXJnZXRDb25uZWN0ZWRgLCBlbGVtZW50KTtcbiAgICB9XG4gICAgdGFyZ2V0RGlzY29ubmVjdGVkKGVsZW1lbnQsIG5hbWUpIHtcbiAgICAgICAgdGhpcy5pbnZva2VDb250cm9sbGVyTWV0aG9kKGAke25hbWV9VGFyZ2V0RGlzY29ubmVjdGVkYCwgZWxlbWVudCk7XG4gICAgfVxuICAgIG91dGxldENvbm5lY3RlZChvdXRsZXQsIGVsZW1lbnQsIG5hbWUpIHtcbiAgICAgICAgdGhpcy5pbnZva2VDb250cm9sbGVyTWV0aG9kKGAke25hbWVzcGFjZUNhbWVsaXplKG5hbWUpfU91dGxldENvbm5lY3RlZGAsIG91dGxldCwgZWxlbWVudCk7XG4gICAgfVxuICAgIG91dGxldERpc2Nvbm5lY3RlZChvdXRsZXQsIGVsZW1lbnQsIG5hbWUpIHtcbiAgICAgICAgdGhpcy5pbnZva2VDb250cm9sbGVyTWV0aG9kKGAke25hbWVzcGFjZUNhbWVsaXplKG5hbWUpfU91dGxldERpc2Nvbm5lY3RlZGAsIG91dGxldCwgZWxlbWVudCk7XG4gICAgfVxuICAgIGludm9rZUNvbnRyb2xsZXJNZXRob2QobWV0aG9kTmFtZSwgLi4uYXJncykge1xuICAgICAgICBjb25zdCBjb250cm9sbGVyID0gdGhpcy5jb250cm9sbGVyO1xuICAgICAgICBpZiAodHlwZW9mIGNvbnRyb2xsZXJbbWV0aG9kTmFtZV0gPT0gXCJmdW5jdGlvblwiKSB7XG4gICAgICAgICAgICBjb250cm9sbGVyW21ldGhvZE5hbWVdKC4uLmFyZ3MpO1xuICAgICAgICB9XG4gICAgfVxufVxuXG5mdW5jdGlvbiBibGVzcyhjb25zdHJ1Y3Rvcikge1xuICAgIHJldHVybiBzaGFkb3coY29uc3RydWN0b3IsIGdldEJsZXNzZWRQcm9wZXJ0aWVzKGNvbnN0cnVjdG9yKSk7XG59XG5mdW5jdGlvbiBzaGFkb3coY29uc3RydWN0b3IsIHByb3BlcnRpZXMpIHtcbiAgICBjb25zdCBzaGFkb3dDb25zdHJ1Y3RvciA9IGV4dGVuZChjb25zdHJ1Y3Rvcik7XG4gICAgY29uc3Qgc2hhZG93UHJvcGVydGllcyA9IGdldFNoYWRvd1Byb3BlcnRpZXMoY29uc3RydWN0b3IucHJvdG90eXBlLCBwcm9wZXJ0aWVzKTtcbiAgICBPYmplY3QuZGVmaW5lUHJvcGVydGllcyhzaGFkb3dDb25zdHJ1Y3Rvci5wcm90b3R5cGUsIHNoYWRvd1Byb3BlcnRpZXMpO1xuICAgIHJldHVybiBzaGFkb3dDb25zdHJ1Y3Rvcjtcbn1cbmZ1bmN0aW9uIGdldEJsZXNzZWRQcm9wZXJ0aWVzKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgYmxlc3NpbmdzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwiYmxlc3NpbmdzXCIpO1xuICAgIHJldHVybiBibGVzc2luZ3MucmVkdWNlKChibGVzc2VkUHJvcGVydGllcywgYmxlc3NpbmcpID0+IHtcbiAgICAgICAgY29uc3QgcHJvcGVydGllcyA9IGJsZXNzaW5nKGNvbnN0cnVjdG9yKTtcbiAgICAgICAgZm9yIChjb25zdCBrZXkgaW4gcHJvcGVydGllcykge1xuICAgICAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IGJsZXNzZWRQcm9wZXJ0aWVzW2tleV0gfHwge307XG4gICAgICAgICAgICBibGVzc2VkUHJvcGVydGllc1trZXldID0gT2JqZWN0LmFzc2lnbihkZXNjcmlwdG9yLCBwcm9wZXJ0aWVzW2tleV0pO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBibGVzc2VkUHJvcGVydGllcztcbiAgICB9LCB7fSk7XG59XG5mdW5jdGlvbiBnZXRTaGFkb3dQcm9wZXJ0aWVzKHByb3RvdHlwZSwgcHJvcGVydGllcykge1xuICAgIHJldHVybiBnZXRPd25LZXlzKHByb3BlcnRpZXMpLnJlZHVjZSgoc2hhZG93UHJvcGVydGllcywga2V5KSA9PiB7XG4gICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSBnZXRTaGFkb3dlZERlc2NyaXB0b3IocHJvdG90eXBlLCBwcm9wZXJ0aWVzLCBrZXkpO1xuICAgICAgICBpZiAoZGVzY3JpcHRvcikge1xuICAgICAgICAgICAgT2JqZWN0LmFzc2lnbihzaGFkb3dQcm9wZXJ0aWVzLCB7IFtrZXldOiBkZXNjcmlwdG9yIH0pO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBzaGFkb3dQcm9wZXJ0aWVzO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIGdldFNoYWRvd2VkRGVzY3JpcHRvcihwcm90b3R5cGUsIHByb3BlcnRpZXMsIGtleSkge1xuICAgIGNvbnN0IHNoYWRvd2luZ0Rlc2NyaXB0b3IgPSBPYmplY3QuZ2V0T3duUHJvcGVydHlEZXNjcmlwdG9yKHByb3RvdHlwZSwga2V5KTtcbiAgICBjb25zdCBzaGFkb3dlZEJ5VmFsdWUgPSBzaGFkb3dpbmdEZXNjcmlwdG9yICYmIFwidmFsdWVcIiBpbiBzaGFkb3dpbmdEZXNjcmlwdG9yO1xuICAgIGlmICghc2hhZG93ZWRCeVZhbHVlKSB7XG4gICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSBPYmplY3QuZ2V0T3duUHJvcGVydHlEZXNjcmlwdG9yKHByb3BlcnRpZXMsIGtleSkudmFsdWU7XG4gICAgICAgIGlmIChzaGFkb3dpbmdEZXNjcmlwdG9yKSB7XG4gICAgICAgICAgICBkZXNjcmlwdG9yLmdldCA9IHNoYWRvd2luZ0Rlc2NyaXB0b3IuZ2V0IHx8IGRlc2NyaXB0b3IuZ2V0O1xuICAgICAgICAgICAgZGVzY3JpcHRvci5zZXQgPSBzaGFkb3dpbmdEZXNjcmlwdG9yLnNldCB8fCBkZXNjcmlwdG9yLnNldDtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZGVzY3JpcHRvcjtcbiAgICB9XG59XG5jb25zdCBnZXRPd25LZXlzID0gKCgpID0+IHtcbiAgICBpZiAodHlwZW9mIE9iamVjdC5nZXRPd25Qcm9wZXJ0eVN5bWJvbHMgPT0gXCJmdW5jdGlvblwiKSB7XG4gICAgICAgIHJldHVybiAob2JqZWN0KSA9PiBbLi4uT2JqZWN0LmdldE93blByb3BlcnR5TmFtZXMob2JqZWN0KSwgLi4uT2JqZWN0LmdldE93blByb3BlcnR5U3ltYm9scyhvYmplY3QpXTtcbiAgICB9XG4gICAgZWxzZSB7XG4gICAgICAgIHJldHVybiBPYmplY3QuZ2V0T3duUHJvcGVydHlOYW1lcztcbiAgICB9XG59KSgpO1xuY29uc3QgZXh0ZW5kID0gKCgpID0+IHtcbiAgICBmdW5jdGlvbiBleHRlbmRXaXRoUmVmbGVjdChjb25zdHJ1Y3Rvcikge1xuICAgICAgICBmdW5jdGlvbiBleHRlbmRlZCgpIHtcbiAgICAgICAgICAgIHJldHVybiBSZWZsZWN0LmNvbnN0cnVjdChjb25zdHJ1Y3RvciwgYXJndW1lbnRzLCBuZXcudGFyZ2V0KTtcbiAgICAgICAgfVxuICAgICAgICBleHRlbmRlZC5wcm90b3R5cGUgPSBPYmplY3QuY3JlYXRlKGNvbnN0cnVjdG9yLnByb3RvdHlwZSwge1xuICAgICAgICAgICAgY29uc3RydWN0b3I6IHsgdmFsdWU6IGV4dGVuZGVkIH0sXG4gICAgICAgIH0pO1xuICAgICAgICBSZWZsZWN0LnNldFByb3RvdHlwZU9mKGV4dGVuZGVkLCBjb25zdHJ1Y3Rvcik7XG4gICAgICAgIHJldHVybiBleHRlbmRlZDtcbiAgICB9XG4gICAgZnVuY3Rpb24gdGVzdFJlZmxlY3RFeHRlbnNpb24oKSB7XG4gICAgICAgIGNvbnN0IGEgPSBmdW5jdGlvbiAoKSB7XG4gICAgICAgICAgICB0aGlzLmEuY2FsbCh0aGlzKTtcbiAgICAgICAgfTtcbiAgICAgICAgY29uc3QgYiA9IGV4dGVuZFdpdGhSZWZsZWN0KGEpO1xuICAgICAgICBiLnByb3RvdHlwZS5hID0gZnVuY3Rpb24gKCkgeyB9O1xuICAgICAgICByZXR1cm4gbmV3IGIoKTtcbiAgICB9XG4gICAgdHJ5IHtcbiAgICAgICAgdGVzdFJlZmxlY3RFeHRlbnNpb24oKTtcbiAgICAgICAgcmV0dXJuIGV4dGVuZFdpdGhSZWZsZWN0O1xuICAgIH1cbiAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgcmV0dXJuIChjb25zdHJ1Y3RvcikgPT4gY2xhc3MgZXh0ZW5kZWQgZXh0ZW5kcyBjb25zdHJ1Y3RvciB7XG4gICAgICAgIH07XG4gICAgfVxufSkoKTtcblxuZnVuY3Rpb24gYmxlc3NEZWZpbml0aW9uKGRlZmluaXRpb24pIHtcbiAgICByZXR1cm4ge1xuICAgICAgICBpZGVudGlmaWVyOiBkZWZpbml0aW9uLmlkZW50aWZpZXIsXG4gICAgICAgIGNvbnRyb2xsZXJDb25zdHJ1Y3RvcjogYmxlc3MoZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3IpLFxuICAgIH07XG59XG5cbmNsYXNzIE1vZHVsZSB7XG4gICAgY29uc3RydWN0b3IoYXBwbGljYXRpb24sIGRlZmluaXRpb24pIHtcbiAgICAgICAgdGhpcy5hcHBsaWNhdGlvbiA9IGFwcGxpY2F0aW9uO1xuICAgICAgICB0aGlzLmRlZmluaXRpb24gPSBibGVzc0RlZmluaXRpb24oZGVmaW5pdGlvbik7XG4gICAgICAgIHRoaXMuY29udGV4dHNCeVNjb3BlID0gbmV3IFdlYWtNYXAoKTtcbiAgICAgICAgdGhpcy5jb25uZWN0ZWRDb250ZXh0cyA9IG5ldyBTZXQoKTtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmRlZmluaXRpb24uaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJDb25zdHJ1Y3RvcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3I7XG4gICAgfVxuICAgIGdldCBjb250ZXh0cygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5jb25uZWN0ZWRDb250ZXh0cyk7XG4gICAgfVxuICAgIGNvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpIHtcbiAgICAgICAgY29uc3QgY29udGV4dCA9IHRoaXMuZmV0Y2hDb250ZXh0Rm9yU2NvcGUoc2NvcGUpO1xuICAgICAgICB0aGlzLmNvbm5lY3RlZENvbnRleHRzLmFkZChjb250ZXh0KTtcbiAgICAgICAgY29udGV4dC5jb25uZWN0KCk7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpIHtcbiAgICAgICAgY29uc3QgY29udGV4dCA9IHRoaXMuY29udGV4dHNCeVNjb3BlLmdldChzY29wZSk7XG4gICAgICAgIGlmIChjb250ZXh0KSB7XG4gICAgICAgICAgICB0aGlzLmNvbm5lY3RlZENvbnRleHRzLmRlbGV0ZShjb250ZXh0KTtcbiAgICAgICAgICAgIGNvbnRleHQuZGlzY29ubmVjdCgpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGZldGNoQ29udGV4dEZvclNjb3BlKHNjb3BlKSB7XG4gICAgICAgIGxldCBjb250ZXh0ID0gdGhpcy5jb250ZXh0c0J5U2NvcGUuZ2V0KHNjb3BlKTtcbiAgICAgICAgaWYgKCFjb250ZXh0KSB7XG4gICAgICAgICAgICBjb250ZXh0ID0gbmV3IENvbnRleHQodGhpcywgc2NvcGUpO1xuICAgICAgICAgICAgdGhpcy5jb250ZXh0c0J5U2NvcGUuc2V0KHNjb3BlLCBjb250ZXh0KTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gY29udGV4dDtcbiAgICB9XG59XG5cbmNsYXNzIENsYXNzTWFwIHtcbiAgICBjb25zdHJ1Y3RvcihzY29wZSkge1xuICAgICAgICB0aGlzLnNjb3BlID0gc2NvcGU7XG4gICAgfVxuICAgIGhhcyhuYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmRhdGEuaGFzKHRoaXMuZ2V0RGF0YUtleShuYW1lKSk7XG4gICAgfVxuICAgIGdldChuYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmdldEFsbChuYW1lKVswXTtcbiAgICB9XG4gICAgZ2V0QWxsKG5hbWUpIHtcbiAgICAgICAgY29uc3QgdG9rZW5TdHJpbmcgPSB0aGlzLmRhdGEuZ2V0KHRoaXMuZ2V0RGF0YUtleShuYW1lKSkgfHwgXCJcIjtcbiAgICAgICAgcmV0dXJuIHRva2VuaXplKHRva2VuU3RyaW5nKTtcbiAgICB9XG4gICAgZ2V0QXR0cmlidXRlTmFtZShuYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmRhdGEuZ2V0QXR0cmlidXRlTmFtZUZvcktleSh0aGlzLmdldERhdGFLZXkobmFtZSkpO1xuICAgIH1cbiAgICBnZXREYXRhS2V5KG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIGAke25hbWV9LWNsYXNzYDtcbiAgICB9XG4gICAgZ2V0IGRhdGEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmRhdGE7XG4gICAgfVxufVxuXG5jbGFzcyBEYXRhTWFwIHtcbiAgICBjb25zdHJ1Y3RvcihzY29wZSkge1xuICAgICAgICB0aGlzLnNjb3BlID0gc2NvcGU7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0KGtleSkge1xuICAgICAgICBjb25zdCBuYW1lID0gdGhpcy5nZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KGtleSk7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnQuZ2V0QXR0cmlidXRlKG5hbWUpO1xuICAgIH1cbiAgICBzZXQoa2V5LCB2YWx1ZSkge1xuICAgICAgICBjb25zdCBuYW1lID0gdGhpcy5nZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KGtleSk7XG4gICAgICAgIHRoaXMuZWxlbWVudC5zZXRBdHRyaWJ1dGUobmFtZSwgdmFsdWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5nZXQoa2V5KTtcbiAgICB9XG4gICAgaGFzKGtleSkge1xuICAgICAgICBjb25zdCBuYW1lID0gdGhpcy5nZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KGtleSk7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnQuaGFzQXR0cmlidXRlKG5hbWUpO1xuICAgIH1cbiAgICBkZWxldGUoa2V5KSB7XG4gICAgICAgIGlmICh0aGlzLmhhcyhrZXkpKSB7XG4gICAgICAgICAgICBjb25zdCBuYW1lID0gdGhpcy5nZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KGtleSk7XG4gICAgICAgICAgICB0aGlzLmVsZW1lbnQucmVtb3ZlQXR0cmlidXRlKG5hbWUpO1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0QXR0cmlidXRlTmFtZUZvcktleShrZXkpIHtcbiAgICAgICAgcmV0dXJuIGBkYXRhLSR7dGhpcy5pZGVudGlmaWVyfS0ke2Rhc2hlcml6ZShrZXkpfWA7XG4gICAgfVxufVxuXG5jbGFzcyBHdWlkZSB7XG4gICAgY29uc3RydWN0b3IobG9nZ2VyKSB7XG4gICAgICAgIHRoaXMud2FybmVkS2V5c0J5T2JqZWN0ID0gbmV3IFdlYWtNYXAoKTtcbiAgICAgICAgdGhpcy5sb2dnZXIgPSBsb2dnZXI7XG4gICAgfVxuICAgIHdhcm4ob2JqZWN0LCBrZXksIG1lc3NhZ2UpIHtcbiAgICAgICAgbGV0IHdhcm5lZEtleXMgPSB0aGlzLndhcm5lZEtleXNCeU9iamVjdC5nZXQob2JqZWN0KTtcbiAgICAgICAgaWYgKCF3YXJuZWRLZXlzKSB7XG4gICAgICAgICAgICB3YXJuZWRLZXlzID0gbmV3IFNldCgpO1xuICAgICAgICAgICAgdGhpcy53YXJuZWRLZXlzQnlPYmplY3Quc2V0KG9iamVjdCwgd2FybmVkS2V5cyk7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKCF3YXJuZWRLZXlzLmhhcyhrZXkpKSB7XG4gICAgICAgICAgICB3YXJuZWRLZXlzLmFkZChrZXkpO1xuICAgICAgICAgICAgdGhpcy5sb2dnZXIud2FybihtZXNzYWdlLCBvYmplY3QpO1xuICAgICAgICB9XG4gICAgfVxufVxuXG5mdW5jdGlvbiBhdHRyaWJ1dGVWYWx1ZUNvbnRhaW5zVG9rZW4oYXR0cmlidXRlTmFtZSwgdG9rZW4pIHtcbiAgICByZXR1cm4gYFske2F0dHJpYnV0ZU5hbWV9fj1cIiR7dG9rZW59XCJdYDtcbn1cblxuY2xhc3MgVGFyZ2V0U2V0IHtcbiAgICBjb25zdHJ1Y3RvcihzY29wZSkge1xuICAgICAgICB0aGlzLnNjb3BlID0gc2NvcGU7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuc2NoZW1hO1xuICAgIH1cbiAgICBoYXModGFyZ2V0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5maW5kKHRhcmdldE5hbWUpICE9IG51bGw7XG4gICAgfVxuICAgIGZpbmQoLi4udGFyZ2V0TmFtZXMpIHtcbiAgICAgICAgcmV0dXJuIHRhcmdldE5hbWVzLnJlZHVjZSgodGFyZ2V0LCB0YXJnZXROYW1lKSA9PiB0YXJnZXQgfHwgdGhpcy5maW5kVGFyZ2V0KHRhcmdldE5hbWUpIHx8IHRoaXMuZmluZExlZ2FjeVRhcmdldCh0YXJnZXROYW1lKSwgdW5kZWZpbmVkKTtcbiAgICB9XG4gICAgZmluZEFsbCguLi50YXJnZXROYW1lcykge1xuICAgICAgICByZXR1cm4gdGFyZ2V0TmFtZXMucmVkdWNlKCh0YXJnZXRzLCB0YXJnZXROYW1lKSA9PiBbXG4gICAgICAgICAgICAuLi50YXJnZXRzLFxuICAgICAgICAgICAgLi4udGhpcy5maW5kQWxsVGFyZ2V0cyh0YXJnZXROYW1lKSxcbiAgICAgICAgICAgIC4uLnRoaXMuZmluZEFsbExlZ2FjeVRhcmdldHModGFyZ2V0TmFtZSksXG4gICAgICAgIF0sIFtdKTtcbiAgICB9XG4gICAgZmluZFRhcmdldCh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSk7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmZpbmRFbGVtZW50KHNlbGVjdG9yKTtcbiAgICB9XG4gICAgZmluZEFsbFRhcmdldHModGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5maW5kQWxsRWxlbWVudHMoc2VsZWN0b3IpO1xuICAgIH1cbiAgICBnZXRTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEudGFyZ2V0QXR0cmlidXRlRm9yU2NvcGUodGhpcy5pZGVudGlmaWVyKTtcbiAgICAgICAgcmV0dXJuIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbihhdHRyaWJ1dGVOYW1lLCB0YXJnZXROYW1lKTtcbiAgICB9XG4gICAgZmluZExlZ2FjeVRhcmdldCh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRMZWdhY3lTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSk7XG4gICAgICAgIHJldHVybiB0aGlzLmRlcHJlY2F0ZSh0aGlzLnNjb3BlLmZpbmRFbGVtZW50KHNlbGVjdG9yKSwgdGFyZ2V0TmFtZSk7XG4gICAgfVxuICAgIGZpbmRBbGxMZWdhY3lUYXJnZXRzKHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldExlZ2FjeVNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yKS5tYXAoKGVsZW1lbnQpID0+IHRoaXMuZGVwcmVjYXRlKGVsZW1lbnQsIHRhcmdldE5hbWUpKTtcbiAgICB9XG4gICAgZ2V0TGVnYWN5U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3QgdGFyZ2V0RGVzY3JpcHRvciA9IGAke3RoaXMuaWRlbnRpZmllcn0uJHt0YXJnZXROYW1lfWA7XG4gICAgICAgIHJldHVybiBhdHRyaWJ1dGVWYWx1ZUNvbnRhaW5zVG9rZW4odGhpcy5zY2hlbWEudGFyZ2V0QXR0cmlidXRlLCB0YXJnZXREZXNjcmlwdG9yKTtcbiAgICB9XG4gICAgZGVwcmVjYXRlKGVsZW1lbnQsIHRhcmdldE5hbWUpIHtcbiAgICAgICAgaWYgKGVsZW1lbnQpIHtcbiAgICAgICAgICAgIGNvbnN0IHsgaWRlbnRpZmllciB9ID0gdGhpcztcbiAgICAgICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGU7XG4gICAgICAgICAgICBjb25zdCByZXZpc2VkQXR0cmlidXRlTmFtZSA9IHRoaXMuc2NoZW1hLnRhcmdldEF0dHJpYnV0ZUZvclNjb3BlKGlkZW50aWZpZXIpO1xuICAgICAgICAgICAgdGhpcy5ndWlkZS53YXJuKGVsZW1lbnQsIGB0YXJnZXQ6JHt0YXJnZXROYW1lfWAsIGBQbGVhc2UgcmVwbGFjZSAke2F0dHJpYnV0ZU5hbWV9PVwiJHtpZGVudGlmaWVyfS4ke3RhcmdldE5hbWV9XCIgd2l0aCAke3JldmlzZWRBdHRyaWJ1dGVOYW1lfT1cIiR7dGFyZ2V0TmFtZX1cIi4gYCArXG4gICAgICAgICAgICAgICAgYFRoZSAke2F0dHJpYnV0ZU5hbWV9IGF0dHJpYnV0ZSBpcyBkZXByZWNhdGVkIGFuZCB3aWxsIGJlIHJlbW92ZWQgaW4gYSBmdXR1cmUgdmVyc2lvbiBvZiBTdGltdWx1cy5gKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGd1aWRlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5ndWlkZTtcbiAgICB9XG59XG5cbmNsYXNzIE91dGxldFNldCB7XG4gICAgY29uc3RydWN0b3Ioc2NvcGUsIGNvbnRyb2xsZXJFbGVtZW50KSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICAgICAgdGhpcy5jb250cm9sbGVyRWxlbWVudCA9IGNvbnRyb2xsZXJFbGVtZW50O1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLnNjaGVtYTtcbiAgICB9XG4gICAgaGFzKG91dGxldE5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZmluZChvdXRsZXROYW1lKSAhPSBudWxsO1xuICAgIH1cbiAgICBmaW5kKC4uLm91dGxldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiBvdXRsZXROYW1lcy5yZWR1Y2UoKG91dGxldCwgb3V0bGV0TmFtZSkgPT4gb3V0bGV0IHx8IHRoaXMuZmluZE91dGxldChvdXRsZXROYW1lKSwgdW5kZWZpbmVkKTtcbiAgICB9XG4gICAgZmluZEFsbCguLi5vdXRsZXROYW1lcykge1xuICAgICAgICByZXR1cm4gb3V0bGV0TmFtZXMucmVkdWNlKChvdXRsZXRzLCBvdXRsZXROYW1lKSA9PiBbLi4ub3V0bGV0cywgLi4udGhpcy5maW5kQWxsT3V0bGV0cyhvdXRsZXROYW1lKV0sIFtdKTtcbiAgICB9XG4gICAgZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuc2NoZW1hLm91dGxldEF0dHJpYnV0ZUZvclNjb3BlKHRoaXMuaWRlbnRpZmllciwgb3V0bGV0TmFtZSk7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRyb2xsZXJFbGVtZW50LmdldEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lKTtcbiAgICB9XG4gICAgZmluZE91dGxldChvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRTZWxlY3RvckZvck91dGxldE5hbWUob3V0bGV0TmFtZSk7XG4gICAgICAgIGlmIChzZWxlY3RvcilcbiAgICAgICAgICAgIHJldHVybiB0aGlzLmZpbmRFbGVtZW50KHNlbGVjdG9yLCBvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgZmluZEFsbE91dGxldHMob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG91dGxldE5hbWUpO1xuICAgICAgICByZXR1cm4gc2VsZWN0b3IgPyB0aGlzLmZpbmRBbGxFbGVtZW50cyhzZWxlY3Rvciwgb3V0bGV0TmFtZSkgOiBbXTtcbiAgICB9XG4gICAgZmluZEVsZW1lbnQoc2VsZWN0b3IsIG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3QgZWxlbWVudHMgPSB0aGlzLnNjb3BlLnF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpO1xuICAgICAgICByZXR1cm4gZWxlbWVudHMuZmlsdGVyKChlbGVtZW50KSA9PiB0aGlzLm1hdGNoZXNFbGVtZW50KGVsZW1lbnQsIHNlbGVjdG9yLCBvdXRsZXROYW1lKSlbMF07XG4gICAgfVxuICAgIGZpbmRBbGxFbGVtZW50cyhzZWxlY3Rvciwgb3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBlbGVtZW50cyA9IHRoaXMuc2NvcGUucXVlcnlFbGVtZW50cyhzZWxlY3Rvcik7XG4gICAgICAgIHJldHVybiBlbGVtZW50cy5maWx0ZXIoKGVsZW1lbnQpID0+IHRoaXMubWF0Y2hlc0VsZW1lbnQoZWxlbWVudCwgc2VsZWN0b3IsIG91dGxldE5hbWUpKTtcbiAgICB9XG4gICAgbWF0Y2hlc0VsZW1lbnQoZWxlbWVudCwgc2VsZWN0b3IsIG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3QgY29udHJvbGxlckF0dHJpYnV0ZSA9IGVsZW1lbnQuZ2V0QXR0cmlidXRlKHRoaXMuc2NvcGUuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGUpIHx8IFwiXCI7XG4gICAgICAgIHJldHVybiBlbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpICYmIGNvbnRyb2xsZXJBdHRyaWJ1dGUuc3BsaXQoXCIgXCIpLmluY2x1ZGVzKG91dGxldE5hbWUpO1xuICAgIH1cbn1cblxuY2xhc3MgU2NvcGUge1xuICAgIGNvbnN0cnVjdG9yKHNjaGVtYSwgZWxlbWVudCwgaWRlbnRpZmllciwgbG9nZ2VyKSB7XG4gICAgICAgIHRoaXMudGFyZ2V0cyA9IG5ldyBUYXJnZXRTZXQodGhpcyk7XG4gICAgICAgIHRoaXMuY2xhc3NlcyA9IG5ldyBDbGFzc01hcCh0aGlzKTtcbiAgICAgICAgdGhpcy5kYXRhID0gbmV3IERhdGFNYXAodGhpcyk7XG4gICAgICAgIHRoaXMuY29udGFpbnNFbGVtZW50ID0gKGVsZW1lbnQpID0+IHtcbiAgICAgICAgICAgIHJldHVybiBlbGVtZW50LmNsb3Nlc3QodGhpcy5jb250cm9sbGVyU2VsZWN0b3IpID09PSB0aGlzLmVsZW1lbnQ7XG4gICAgICAgIH07XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgICAgICB0aGlzLmVsZW1lbnQgPSBlbGVtZW50O1xuICAgICAgICB0aGlzLmlkZW50aWZpZXIgPSBpZGVudGlmaWVyO1xuICAgICAgICB0aGlzLmd1aWRlID0gbmV3IEd1aWRlKGxvZ2dlcik7XG4gICAgICAgIHRoaXMub3V0bGV0cyA9IG5ldyBPdXRsZXRTZXQodGhpcy5kb2N1bWVudFNjb3BlLCBlbGVtZW50KTtcbiAgICB9XG4gICAgZmluZEVsZW1lbnQoc2VsZWN0b3IpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5tYXRjaGVzKHNlbGVjdG9yKSA/IHRoaXMuZWxlbWVudCA6IHRoaXMucXVlcnlFbGVtZW50cyhzZWxlY3RvcikuZmluZCh0aGlzLmNvbnRhaW5zRWxlbWVudCk7XG4gICAgfVxuICAgIGZpbmRBbGxFbGVtZW50cyhzZWxlY3Rvcikge1xuICAgICAgICByZXR1cm4gW1xuICAgICAgICAgICAgLi4uKHRoaXMuZWxlbWVudC5tYXRjaGVzKHNlbGVjdG9yKSA/IFt0aGlzLmVsZW1lbnRdIDogW10pLFxuICAgICAgICAgICAgLi4udGhpcy5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKS5maWx0ZXIodGhpcy5jb250YWluc0VsZW1lbnQpLFxuICAgICAgICBdO1xuICAgIH1cbiAgICBxdWVyeUVsZW1lbnRzKHNlbGVjdG9yKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuZWxlbWVudC5xdWVyeVNlbGVjdG9yQWxsKHNlbGVjdG9yKSk7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVyU2VsZWN0b3IoKSB7XG4gICAgICAgIHJldHVybiBhdHRyaWJ1dGVWYWx1ZUNvbnRhaW5zVG9rZW4odGhpcy5zY2hlbWEuY29udHJvbGxlckF0dHJpYnV0ZSwgdGhpcy5pZGVudGlmaWVyKTtcbiAgICB9XG4gICAgZ2V0IGlzRG9jdW1lbnRTY29wZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudCA9PT0gZG9jdW1lbnQuZG9jdW1lbnRFbGVtZW50O1xuICAgIH1cbiAgICBnZXQgZG9jdW1lbnRTY29wZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuaXNEb2N1bWVudFNjb3BlXG4gICAgICAgICAgICA/IHRoaXNcbiAgICAgICAgICAgIDogbmV3IFNjb3BlKHRoaXMuc2NoZW1hLCBkb2N1bWVudC5kb2N1bWVudEVsZW1lbnQsIHRoaXMuaWRlbnRpZmllciwgdGhpcy5ndWlkZS5sb2dnZXIpO1xuICAgIH1cbn1cblxuY2xhc3MgU2NvcGVPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgc2NoZW1hLCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmVsZW1lbnQgPSBlbGVtZW50O1xuICAgICAgICB0aGlzLnNjaGVtYSA9IHNjaGVtYTtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyID0gbmV3IFZhbHVlTGlzdE9ic2VydmVyKHRoaXMuZWxlbWVudCwgdGhpcy5jb250cm9sbGVyQXR0cmlidXRlLCB0aGlzKTtcbiAgICAgICAgdGhpcy5zY29wZXNCeUlkZW50aWZpZXJCeUVsZW1lbnQgPSBuZXcgV2Vha01hcCgpO1xuICAgICAgICB0aGlzLnNjb3BlUmVmZXJlbmNlQ291bnRzID0gbmV3IFdlYWtNYXAoKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVyQXR0cmlidXRlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY2hlbWEuY29udHJvbGxlckF0dHJpYnV0ZTtcbiAgICB9XG4gICAgcGFyc2VWYWx1ZUZvclRva2VuKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCwgY29udGVudDogaWRlbnRpZmllciB9ID0gdG9rZW47XG4gICAgICAgIHJldHVybiB0aGlzLnBhcnNlVmFsdWVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICB9XG4gICAgcGFyc2VWYWx1ZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgY29uc3Qgc2NvcGVzQnlJZGVudGlmaWVyID0gdGhpcy5mZXRjaFNjb3Blc0J5SWRlbnRpZmllckZvckVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgIGxldCBzY29wZSA9IHNjb3Blc0J5SWRlbnRpZmllci5nZXQoaWRlbnRpZmllcik7XG4gICAgICAgIGlmICghc2NvcGUpIHtcbiAgICAgICAgICAgIHNjb3BlID0gdGhpcy5kZWxlZ2F0ZS5jcmVhdGVTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xuICAgICAgICAgICAgc2NvcGVzQnlJZGVudGlmaWVyLnNldChpZGVudGlmaWVyLCBzY29wZSk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHNjb3BlO1xuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZFZhbHVlKGVsZW1lbnQsIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHJlZmVyZW5jZUNvdW50ID0gKHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMuZ2V0KHZhbHVlKSB8fCAwKSArIDE7XG4gICAgICAgIHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMuc2V0KHZhbHVlLCByZWZlcmVuY2VDb3VudCk7XG4gICAgICAgIGlmIChyZWZlcmVuY2VDb3VudCA9PSAxKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLnNjb3BlQ29ubmVjdGVkKHZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpIHtcbiAgICAgICAgY29uc3QgcmVmZXJlbmNlQ291bnQgPSB0aGlzLnNjb3BlUmVmZXJlbmNlQ291bnRzLmdldCh2YWx1ZSk7XG4gICAgICAgIGlmIChyZWZlcmVuY2VDb3VudCkge1xuICAgICAgICAgICAgdGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5zZXQodmFsdWUsIHJlZmVyZW5jZUNvdW50IC0gMSk7XG4gICAgICAgICAgICBpZiAocmVmZXJlbmNlQ291bnQgPT0gMSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc2NvcGVEaXNjb25uZWN0ZWQodmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGZldGNoU2NvcGVzQnlJZGVudGlmaWVyRm9yRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGxldCBzY29wZXNCeUlkZW50aWZpZXIgPSB0aGlzLnNjb3Blc0J5SWRlbnRpZmllckJ5RWxlbWVudC5nZXQoZWxlbWVudCk7XG4gICAgICAgIGlmICghc2NvcGVzQnlJZGVudGlmaWVyKSB7XG4gICAgICAgICAgICBzY29wZXNCeUlkZW50aWZpZXIgPSBuZXcgTWFwKCk7XG4gICAgICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllckJ5RWxlbWVudC5zZXQoZWxlbWVudCwgc2NvcGVzQnlJZGVudGlmaWVyKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gc2NvcGVzQnlJZGVudGlmaWVyO1xuICAgIH1cbn1cblxuY2xhc3MgUm91dGVyIHtcbiAgICBjb25zdHJ1Y3RvcihhcHBsaWNhdGlvbikge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uID0gYXBwbGljYXRpb247XG4gICAgICAgIHRoaXMuc2NvcGVPYnNlcnZlciA9IG5ldyBTY29wZU9ic2VydmVyKHRoaXMuZWxlbWVudCwgdGhpcy5zY2hlbWEsIHRoaXMpO1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllciA9IG5ldyBNdWx0aW1hcCgpO1xuICAgICAgICB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIgPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5zY2hlbWE7XG4gICAgfVxuICAgIGdldCBsb2dnZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmxvZ2dlcjtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJBdHRyaWJ1dGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlO1xuICAgIH1cbiAgICBnZXQgbW9kdWxlcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLnZhbHVlcygpKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRleHRzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5tb2R1bGVzLnJlZHVjZSgoY29udGV4dHMsIG1vZHVsZSkgPT4gY29udGV4dHMuY29uY2F0KG1vZHVsZS5jb250ZXh0cyksIFtdKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMuc2NvcGVPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnNjb3BlT2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICBsb2FkRGVmaW5pdGlvbihkZWZpbml0aW9uKSB7XG4gICAgICAgIHRoaXMudW5sb2FkSWRlbnRpZmllcihkZWZpbml0aW9uLmlkZW50aWZpZXIpO1xuICAgICAgICBjb25zdCBtb2R1bGUgPSBuZXcgTW9kdWxlKHRoaXMuYXBwbGljYXRpb24sIGRlZmluaXRpb24pO1xuICAgICAgICB0aGlzLmNvbm5lY3RNb2R1bGUobW9kdWxlKTtcbiAgICAgICAgY29uc3QgYWZ0ZXJMb2FkID0gZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3IuYWZ0ZXJMb2FkO1xuICAgICAgICBpZiAoYWZ0ZXJMb2FkKSB7XG4gICAgICAgICAgICBhZnRlckxvYWQuY2FsbChkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvciwgZGVmaW5pdGlvbi5pZGVudGlmaWVyLCB0aGlzLmFwcGxpY2F0aW9uKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICB1bmxvYWRJZGVudGlmaWVyKGlkZW50aWZpZXIpIHtcbiAgICAgICAgY29uc3QgbW9kdWxlID0gdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmdldChpZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKG1vZHVsZSkge1xuICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0TW9kdWxlKG1vZHVsZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0Q29udGV4dEZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgY29uc3QgbW9kdWxlID0gdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmdldChpZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKG1vZHVsZSkge1xuICAgICAgICAgICAgcmV0dXJuIG1vZHVsZS5jb250ZXh0cy5maW5kKChjb250ZXh0KSA9PiBjb250ZXh0LmVsZW1lbnQgPT0gZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvcG9zZVRvQ29ubmVjdFNjb3BlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBzY29wZSA9IHRoaXMuc2NvcGVPYnNlcnZlci5wYXJzZVZhbHVlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcik7XG4gICAgICAgIGlmIChzY29wZSkge1xuICAgICAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyLmVsZW1lbnRNYXRjaGVkVmFsdWUoc2NvcGUuZWxlbWVudCwgc2NvcGUpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgY29uc29sZS5lcnJvcihgQ291bGRuJ3QgZmluZCBvciBjcmVhdGUgc2NvcGUgZm9yIGlkZW50aWZpZXI6IFwiJHtpZGVudGlmaWVyfVwiIGFuZCBlbGVtZW50OmAsIGVsZW1lbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwpIHtcbiAgICAgICAgdGhpcy5hcHBsaWNhdGlvbi5oYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsKTtcbiAgICB9XG4gICAgY3JlYXRlU2NvcGVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgICAgIHJldHVybiBuZXcgU2NvcGUodGhpcy5zY2hlbWEsIGVsZW1lbnQsIGlkZW50aWZpZXIsIHRoaXMubG9nZ2VyKTtcbiAgICB9XG4gICAgc2NvcGVDb25uZWN0ZWQoc2NvcGUpIHtcbiAgICAgICAgdGhpcy5zY29wZXNCeUlkZW50aWZpZXIuYWRkKHNjb3BlLmlkZW50aWZpZXIsIHNjb3BlKTtcbiAgICAgICAgY29uc3QgbW9kdWxlID0gdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmdldChzY29wZS5pZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKG1vZHVsZSkge1xuICAgICAgICAgICAgbW9kdWxlLmNvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHNjb3BlRGlzY29ubmVjdGVkKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmRlbGV0ZShzY29wZS5pZGVudGlmaWVyLCBzY29wZSk7XG4gICAgICAgIGNvbnN0IG1vZHVsZSA9IHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5nZXQoc2NvcGUuaWRlbnRpZmllcik7XG4gICAgICAgIGlmIChtb2R1bGUpIHtcbiAgICAgICAgICAgIG1vZHVsZS5kaXNjb25uZWN0Q29udGV4dEZvclNjb3BlKHNjb3BlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBjb25uZWN0TW9kdWxlKG1vZHVsZSkge1xuICAgICAgICB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuc2V0KG1vZHVsZS5pZGVudGlmaWVyLCBtb2R1bGUpO1xuICAgICAgICBjb25zdCBzY29wZXMgPSB0aGlzLnNjb3Blc0J5SWRlbnRpZmllci5nZXRWYWx1ZXNGb3JLZXkobW9kdWxlLmlkZW50aWZpZXIpO1xuICAgICAgICBzY29wZXMuZm9yRWFjaCgoc2NvcGUpID0+IG1vZHVsZS5jb25uZWN0Q29udGV4dEZvclNjb3BlKHNjb3BlKSk7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RNb2R1bGUobW9kdWxlKSB7XG4gICAgICAgIHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5kZWxldGUobW9kdWxlLmlkZW50aWZpZXIpO1xuICAgICAgICBjb25zdCBzY29wZXMgPSB0aGlzLnNjb3Blc0J5SWRlbnRpZmllci5nZXRWYWx1ZXNGb3JLZXkobW9kdWxlLmlkZW50aWZpZXIpO1xuICAgICAgICBzY29wZXMuZm9yRWFjaCgoc2NvcGUpID0+IG1vZHVsZS5kaXNjb25uZWN0Q29udGV4dEZvclNjb3BlKHNjb3BlKSk7XG4gICAgfVxufVxuXG5jb25zdCBkZWZhdWx0U2NoZW1hID0ge1xuICAgIGNvbnRyb2xsZXJBdHRyaWJ1dGU6IFwiZGF0YS1jb250cm9sbGVyXCIsXG4gICAgYWN0aW9uQXR0cmlidXRlOiBcImRhdGEtYWN0aW9uXCIsXG4gICAgdGFyZ2V0QXR0cmlidXRlOiBcImRhdGEtdGFyZ2V0XCIsXG4gICAgdGFyZ2V0QXR0cmlidXRlRm9yU2NvcGU6IChpZGVudGlmaWVyKSA9PiBgZGF0YS0ke2lkZW50aWZpZXJ9LXRhcmdldGAsXG4gICAgb3V0bGV0QXR0cmlidXRlRm9yU2NvcGU6IChpZGVudGlmaWVyLCBvdXRsZXQpID0+IGBkYXRhLSR7aWRlbnRpZmllcn0tJHtvdXRsZXR9LW91dGxldGAsXG4gICAga2V5TWFwcGluZ3M6IE9iamVjdC5hc3NpZ24oT2JqZWN0LmFzc2lnbih7IGVudGVyOiBcIkVudGVyXCIsIHRhYjogXCJUYWJcIiwgZXNjOiBcIkVzY2FwZVwiLCBzcGFjZTogXCIgXCIsIHVwOiBcIkFycm93VXBcIiwgZG93bjogXCJBcnJvd0Rvd25cIiwgbGVmdDogXCJBcnJvd0xlZnRcIiwgcmlnaHQ6IFwiQXJyb3dSaWdodFwiLCBob21lOiBcIkhvbWVcIiwgZW5kOiBcIkVuZFwiLCBwYWdlX3VwOiBcIlBhZ2VVcFwiLCBwYWdlX2Rvd246IFwiUGFnZURvd25cIiB9LCBvYmplY3RGcm9tRW50cmllcyhcImFiY2RlZmdoaWprbG1ub3BxcnN0dXZ3eHl6XCIuc3BsaXQoXCJcIikubWFwKChjKSA9PiBbYywgY10pKSksIG9iamVjdEZyb21FbnRyaWVzKFwiMDEyMzQ1Njc4OVwiLnNwbGl0KFwiXCIpLm1hcCgobikgPT4gW24sIG5dKSkpLFxufTtcbmZ1bmN0aW9uIG9iamVjdEZyb21FbnRyaWVzKGFycmF5KSB7XG4gICAgcmV0dXJuIGFycmF5LnJlZHVjZSgobWVtbywgW2ssIHZdKSA9PiAoT2JqZWN0LmFzc2lnbihPYmplY3QuYXNzaWduKHt9LCBtZW1vKSwgeyBba106IHYgfSkpLCB7fSk7XG59XG5cbmNsYXNzIEFwcGxpY2F0aW9uIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50ID0gZG9jdW1lbnQuZG9jdW1lbnRFbGVtZW50LCBzY2hlbWEgPSBkZWZhdWx0U2NoZW1hKSB7XG4gICAgICAgIHRoaXMubG9nZ2VyID0gY29uc29sZTtcbiAgICAgICAgdGhpcy5kZWJ1ZyA9IGZhbHNlO1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkgPSAoaWRlbnRpZmllciwgZnVuY3Rpb25OYW1lLCBkZXRhaWwgPSB7fSkgPT4ge1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVidWcpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmxvZ0Zvcm1hdHRlZE1lc3NhZ2UoaWRlbnRpZmllciwgZnVuY3Rpb25OYW1lLCBkZXRhaWwpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9O1xuICAgICAgICB0aGlzLmVsZW1lbnQgPSBlbGVtZW50O1xuICAgICAgICB0aGlzLnNjaGVtYSA9IHNjaGVtYTtcbiAgICAgICAgdGhpcy5kaXNwYXRjaGVyID0gbmV3IERpc3BhdGNoZXIodGhpcyk7XG4gICAgICAgIHRoaXMucm91dGVyID0gbmV3IFJvdXRlcih0aGlzKTtcbiAgICAgICAgdGhpcy5hY3Rpb25EZXNjcmlwdG9yRmlsdGVycyA9IE9iamVjdC5hc3NpZ24oe30sIGRlZmF1bHRBY3Rpb25EZXNjcmlwdG9yRmlsdGVycyk7XG4gICAgfVxuICAgIHN0YXRpYyBzdGFydChlbGVtZW50LCBzY2hlbWEpIHtcbiAgICAgICAgY29uc3QgYXBwbGljYXRpb24gPSBuZXcgdGhpcyhlbGVtZW50LCBzY2hlbWEpO1xuICAgICAgICBhcHBsaWNhdGlvbi5zdGFydCgpO1xuICAgICAgICByZXR1cm4gYXBwbGljYXRpb247XG4gICAgfVxuICAgIGFzeW5jIHN0YXJ0KCkge1xuICAgICAgICBhd2FpdCBkb21SZWFkeSgpO1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJhcHBsaWNhdGlvblwiLCBcInN0YXJ0aW5nXCIpO1xuICAgICAgICB0aGlzLmRpc3BhdGNoZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy5yb3V0ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiYXBwbGljYXRpb25cIiwgXCJzdGFydFwiKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiYXBwbGljYXRpb25cIiwgXCJzdG9wcGluZ1wiKTtcbiAgICAgICAgdGhpcy5kaXNwYXRjaGVyLnN0b3AoKTtcbiAgICAgICAgdGhpcy5yb3V0ZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJhcHBsaWNhdGlvblwiLCBcInN0b3BcIik7XG4gICAgfVxuICAgIHJlZ2lzdGVyKGlkZW50aWZpZXIsIGNvbnRyb2xsZXJDb25zdHJ1Y3Rvcikge1xuICAgICAgICB0aGlzLmxvYWQoeyBpZGVudGlmaWVyLCBjb250cm9sbGVyQ29uc3RydWN0b3IgfSk7XG4gICAgfVxuICAgIHJlZ2lzdGVyQWN0aW9uT3B0aW9uKG5hbWUsIGZpbHRlcikge1xuICAgICAgICB0aGlzLmFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzW25hbWVdID0gZmlsdGVyO1xuICAgIH1cbiAgICBsb2FkKGhlYWQsIC4uLnJlc3QpIHtcbiAgICAgICAgY29uc3QgZGVmaW5pdGlvbnMgPSBBcnJheS5pc0FycmF5KGhlYWQpID8gaGVhZCA6IFtoZWFkLCAuLi5yZXN0XTtcbiAgICAgICAgZGVmaW5pdGlvbnMuZm9yRWFjaCgoZGVmaW5pdGlvbikgPT4ge1xuICAgICAgICAgICAgaWYgKGRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yLnNob3VsZExvYWQpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnJvdXRlci5sb2FkRGVmaW5pdGlvbihkZWZpbml0aW9uKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfSk7XG4gICAgfVxuICAgIHVubG9hZChoZWFkLCAuLi5yZXN0KSB7XG4gICAgICAgIGNvbnN0IGlkZW50aWZpZXJzID0gQXJyYXkuaXNBcnJheShoZWFkKSA/IGhlYWQgOiBbaGVhZCwgLi4ucmVzdF07XG4gICAgICAgIGlkZW50aWZpZXJzLmZvckVhY2goKGlkZW50aWZpZXIpID0+IHRoaXMucm91dGVyLnVubG9hZElkZW50aWZpZXIoaWRlbnRpZmllcikpO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlcnMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnJvdXRlci5jb250ZXh0cy5tYXAoKGNvbnRleHQpID0+IGNvbnRleHQuY29udHJvbGxlcik7XG4gICAgfVxuICAgIGdldENvbnRyb2xsZXJGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgICAgIGNvbnN0IGNvbnRleHQgPSB0aGlzLnJvdXRlci5nZXRDb250ZXh0Rm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcik7XG4gICAgICAgIHJldHVybiBjb250ZXh0ID8gY29udGV4dC5jb250cm9sbGVyIDogbnVsbDtcbiAgICB9XG4gICAgaGFuZGxlRXJyb3IoZXJyb3IsIG1lc3NhZ2UsIGRldGFpbCkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIHRoaXMubG9nZ2VyLmVycm9yKGAlc1xcblxcbiVvXFxuXFxuJW9gLCBtZXNzYWdlLCBlcnJvciwgZGV0YWlsKTtcbiAgICAgICAgKF9hID0gd2luZG93Lm9uZXJyb3IpID09PSBudWxsIHx8IF9hID09PSB2b2lkIDAgPyB2b2lkIDAgOiBfYS5jYWxsKHdpbmRvdywgbWVzc2FnZSwgXCJcIiwgMCwgMCwgZXJyb3IpO1xuICAgIH1cbiAgICBsb2dGb3JtYXR0ZWRNZXNzYWdlKGlkZW50aWZpZXIsIGZ1bmN0aW9uTmFtZSwgZGV0YWlsID0ge30pIHtcbiAgICAgICAgZGV0YWlsID0gT2JqZWN0LmFzc2lnbih7IGFwcGxpY2F0aW9uOiB0aGlzIH0sIGRldGFpbCk7XG4gICAgICAgIHRoaXMubG9nZ2VyLmdyb3VwQ29sbGFwc2VkKGAke2lkZW50aWZpZXJ9ICMke2Z1bmN0aW9uTmFtZX1gKTtcbiAgICAgICAgdGhpcy5sb2dnZXIubG9nKFwiZGV0YWlsczpcIiwgT2JqZWN0LmFzc2lnbih7fSwgZGV0YWlsKSk7XG4gICAgICAgIHRoaXMubG9nZ2VyLmdyb3VwRW5kKCk7XG4gICAgfVxufVxuZnVuY3Rpb24gZG9tUmVhZHkoKSB7XG4gICAgcmV0dXJuIG5ldyBQcm9taXNlKChyZXNvbHZlKSA9PiB7XG4gICAgICAgIGlmIChkb2N1bWVudC5yZWFkeVN0YXRlID09IFwibG9hZGluZ1wiKSB7XG4gICAgICAgICAgICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKFwiRE9NQ29udGVudExvYWRlZFwiLCAoKSA9PiByZXNvbHZlKCkpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmVzb2x2ZSgpO1xuICAgICAgICB9XG4gICAgfSk7XG59XG5cbmZ1bmN0aW9uIENsYXNzUHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgY2xhc3NlcyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBcImNsYXNzZXNcIik7XG4gICAgcmV0dXJuIGNsYXNzZXMucmVkdWNlKChwcm9wZXJ0aWVzLCBjbGFzc0RlZmluaXRpb24pID0+IHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24ocHJvcGVydGllcywgcHJvcGVydGllc0ZvckNsYXNzRGVmaW5pdGlvbihjbGFzc0RlZmluaXRpb24pKTtcbiAgICB9LCB7fSk7XG59XG5mdW5jdGlvbiBwcm9wZXJ0aWVzRm9yQ2xhc3NEZWZpbml0aW9uKGtleSkge1xuICAgIHJldHVybiB7XG4gICAgICAgIFtgJHtrZXl9Q2xhc3NgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IHsgY2xhc3NlcyB9ID0gdGhpcztcbiAgICAgICAgICAgICAgICBpZiAoY2xhc3Nlcy5oYXMoa2V5KSkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gY2xhc3Nlcy5nZXQoa2V5KTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IGF0dHJpYnV0ZSA9IGNsYXNzZXMuZ2V0QXR0cmlidXRlTmFtZShrZXkpO1xuICAgICAgICAgICAgICAgICAgICB0aHJvdyBuZXcgRXJyb3IoYE1pc3NpbmcgYXR0cmlidXRlIFwiJHthdHRyaWJ1dGV9XCJgKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7a2V5fUNsYXNzZXNgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLmNsYXNzZXMuZ2V0QWxsKGtleSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYGhhcyR7Y2FwaXRhbGl6ZShrZXkpfUNsYXNzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy5jbGFzc2VzLmhhcyhrZXkpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICB9O1xufVxuXG5mdW5jdGlvbiBPdXRsZXRQcm9wZXJ0aWVzQmxlc3NpbmcoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBvdXRsZXRzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwib3V0bGV0c1wiKTtcbiAgICByZXR1cm4gb3V0bGV0cy5yZWR1Y2UoKHByb3BlcnRpZXMsIG91dGxldERlZmluaXRpb24pID0+IHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24ocHJvcGVydGllcywgcHJvcGVydGllc0Zvck91dGxldERlZmluaXRpb24ob3V0bGV0RGVmaW5pdGlvbikpO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIGdldE91dGxldENvbnRyb2xsZXIoY29udHJvbGxlciwgZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgIHJldHVybiBjb250cm9sbGVyLmFwcGxpY2F0aW9uLmdldENvbnRyb2xsZXJGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbn1cbmZ1bmN0aW9uIGdldENvbnRyb2xsZXJBbmRFbnN1cmVDb25uZWN0ZWRTY29wZShjb250cm9sbGVyLCBlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgbGV0IG91dGxldENvbnRyb2xsZXIgPSBnZXRPdXRsZXRDb250cm9sbGVyKGNvbnRyb2xsZXIsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICByZXR1cm4gb3V0bGV0Q29udHJvbGxlcjtcbiAgICBjb250cm9sbGVyLmFwcGxpY2F0aW9uLnJvdXRlci5wcm9wb3NlVG9Db25uZWN0U2NvcGVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICBvdXRsZXRDb250cm9sbGVyID0gZ2V0T3V0bGV0Q29udHJvbGxlcihjb250cm9sbGVyLCBlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICBpZiAob3V0bGV0Q29udHJvbGxlcilcbiAgICAgICAgcmV0dXJuIG91dGxldENvbnRyb2xsZXI7XG59XG5mdW5jdGlvbiBwcm9wZXJ0aWVzRm9yT3V0bGV0RGVmaW5pdGlvbihuYW1lKSB7XG4gICAgY29uc3QgY2FtZWxpemVkTmFtZSA9IG5hbWVzcGFjZUNhbWVsaXplKG5hbWUpO1xuICAgIHJldHVybiB7XG4gICAgICAgIFtgJHtjYW1lbGl6ZWROYW1lfU91dGxldGBdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgY29uc3Qgb3V0bGV0RWxlbWVudCA9IHRoaXMub3V0bGV0cy5maW5kKG5hbWUpO1xuICAgICAgICAgICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5vdXRsZXRzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShuYW1lKTtcbiAgICAgICAgICAgICAgICBpZiAob3V0bGV0RWxlbWVudCkge1xuICAgICAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRDb250cm9sbGVyID0gZ2V0Q29udHJvbGxlckFuZEVuc3VyZUNvbm5lY3RlZFNjb3BlKHRoaXMsIG91dGxldEVsZW1lbnQsIG5hbWUpO1xuICAgICAgICAgICAgICAgICAgICBpZiAob3V0bGV0Q29udHJvbGxlcilcbiAgICAgICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xuICAgICAgICAgICAgICAgICAgICB0aHJvdyBuZXcgRXJyb3IoYFRoZSBwcm92aWRlZCBvdXRsZXQgZWxlbWVudCBpcyBtaXNzaW5nIGFuIG91dGxldCBjb250cm9sbGVyIFwiJHtuYW1lfVwiIGluc3RhbmNlIGZvciBob3N0IGNvbnRyb2xsZXIgXCIke3RoaXMuaWRlbnRpZmllcn1cImApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICB0aHJvdyBuZXcgRXJyb3IoYE1pc3Npbmcgb3V0bGV0IGVsZW1lbnQgXCIke25hbWV9XCIgZm9yIGhvc3QgY29udHJvbGxlciBcIiR7dGhpcy5pZGVudGlmaWVyfVwiLiBTdGltdWx1cyBjb3VsZG4ndCBmaW5kIGEgbWF0Y2hpbmcgb3V0bGV0IGVsZW1lbnQgdXNpbmcgc2VsZWN0b3IgXCIke3NlbGVjdG9yfVwiLmApO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2Ake2NhbWVsaXplZE5hbWV9T3V0bGV0c2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgY29uc3Qgb3V0bGV0cyA9IHRoaXMub3V0bGV0cy5maW5kQWxsKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmIChvdXRsZXRzLmxlbmd0aCA+IDApIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIG91dGxldHNcbiAgICAgICAgICAgICAgICAgICAgICAgIC5tYXAoKG91dGxldEVsZW1lbnQpID0+IHtcbiAgICAgICAgICAgICAgICAgICAgICAgIGNvbnN0IG91dGxldENvbnRyb2xsZXIgPSBnZXRDb250cm9sbGVyQW5kRW5zdXJlQ29ubmVjdGVkU2NvcGUodGhpcywgb3V0bGV0RWxlbWVudCwgbmFtZSk7XG4gICAgICAgICAgICAgICAgICAgICAgICBpZiAob3V0bGV0Q29udHJvbGxlcilcbiAgICAgICAgICAgICAgICAgICAgICAgICAgICByZXR1cm4gb3V0bGV0Q29udHJvbGxlcjtcbiAgICAgICAgICAgICAgICAgICAgICAgIGNvbnNvbGUud2FybihgVGhlIHByb3ZpZGVkIG91dGxldCBlbGVtZW50IGlzIG1pc3NpbmcgYW4gb3V0bGV0IGNvbnRyb2xsZXIgXCIke25hbWV9XCIgaW5zdGFuY2UgZm9yIGhvc3QgY29udHJvbGxlciBcIiR7dGhpcy5pZGVudGlmaWVyfVwiYCwgb3V0bGV0RWxlbWVudCk7XG4gICAgICAgICAgICAgICAgICAgIH0pXG4gICAgICAgICAgICAgICAgICAgICAgICAuZmlsdGVyKChjb250cm9sbGVyKSA9PiBjb250cm9sbGVyKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgcmV0dXJuIFtdO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2Ake2NhbWVsaXplZE5hbWV9T3V0bGV0RWxlbWVudGBdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgY29uc3Qgb3V0bGV0RWxlbWVudCA9IHRoaXMub3V0bGV0cy5maW5kKG5hbWUpO1xuICAgICAgICAgICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5vdXRsZXRzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShuYW1lKTtcbiAgICAgICAgICAgICAgICBpZiAob3V0bGV0RWxlbWVudCkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gb3V0bGV0RWxlbWVudDtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyBvdXRsZXQgZWxlbWVudCBcIiR7bmFtZX1cIiBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCIuIFN0aW11bHVzIGNvdWxkbid0IGZpbmQgYSBtYXRjaGluZyBvdXRsZXQgZWxlbWVudCB1c2luZyBzZWxlY3RvciBcIiR7c2VsZWN0b3J9XCIuYCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2Ake2NhbWVsaXplZE5hbWV9T3V0bGV0RWxlbWVudHNgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLm91dGxldHMuZmluZEFsbChuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKGNhbWVsaXplZE5hbWUpfU91dGxldGBdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0cy5oYXMobmFtZSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5cbmZ1bmN0aW9uIFRhcmdldFByb3BlcnRpZXNCbGVzc2luZyhjb25zdHJ1Y3Rvcikge1xuICAgIGNvbnN0IHRhcmdldHMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJ0YXJnZXRzXCIpO1xuICAgIHJldHVybiB0YXJnZXRzLnJlZHVjZSgocHJvcGVydGllcywgdGFyZ2V0RGVmaW5pdGlvbikgPT4ge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihwcm9wZXJ0aWVzLCBwcm9wZXJ0aWVzRm9yVGFyZ2V0RGVmaW5pdGlvbih0YXJnZXREZWZpbml0aW9uKSk7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gcHJvcGVydGllc0ZvclRhcmdldERlZmluaXRpb24obmFtZSkge1xuICAgIHJldHVybiB7XG4gICAgICAgIFtgJHtuYW1lfVRhcmdldGBdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgdGFyZ2V0ID0gdGhpcy50YXJnZXRzLmZpbmQobmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKHRhcmdldCkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gdGFyZ2V0O1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBNaXNzaW5nIHRhcmdldCBlbGVtZW50IFwiJHtuYW1lfVwiIGZvciBcIiR7dGhpcy5pZGVudGlmaWVyfVwiIGNvbnRyb2xsZXJgKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7bmFtZX1UYXJnZXRzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy50YXJnZXRzLmZpbmRBbGwobmFtZSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYGhhcyR7Y2FwaXRhbGl6ZShuYW1lKX1UYXJnZXRgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLnRhcmdldHMuaGFzKG5hbWUpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICB9O1xufVxuXG5mdW5jdGlvbiBWYWx1ZVByb3BlcnRpZXNCbGVzc2luZyhjb25zdHJ1Y3Rvcikge1xuICAgIGNvbnN0IHZhbHVlRGVmaW5pdGlvblBhaXJzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljT2JqZWN0UGFpcnMoY29uc3RydWN0b3IsIFwidmFsdWVzXCIpO1xuICAgIGNvbnN0IHByb3BlcnR5RGVzY3JpcHRvck1hcCA9IHtcbiAgICAgICAgdmFsdWVEZXNjcmlwdG9yTWFwOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHZhbHVlRGVmaW5pdGlvblBhaXJzLnJlZHVjZSgocmVzdWx0LCB2YWx1ZURlZmluaXRpb25QYWlyKSA9PiB7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IHZhbHVlRGVzY3JpcHRvciA9IHBhcnNlVmFsdWVEZWZpbml0aW9uUGFpcih2YWx1ZURlZmluaXRpb25QYWlyLCB0aGlzLmlkZW50aWZpZXIpO1xuICAgICAgICAgICAgICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5kYXRhLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkodmFsdWVEZXNjcmlwdG9yLmtleSk7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHJlc3VsdCwgeyBbYXR0cmlidXRlTmFtZV06IHZhbHVlRGVzY3JpcHRvciB9KTtcbiAgICAgICAgICAgICAgICB9LCB7fSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG4gICAgcmV0dXJuIHZhbHVlRGVmaW5pdGlvblBhaXJzLnJlZHVjZSgocHJvcGVydGllcywgdmFsdWVEZWZpbml0aW9uUGFpcikgPT4ge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihwcm9wZXJ0aWVzLCBwcm9wZXJ0aWVzRm9yVmFsdWVEZWZpbml0aW9uUGFpcih2YWx1ZURlZmluaXRpb25QYWlyKSk7XG4gICAgfSwgcHJvcGVydHlEZXNjcmlwdG9yTWFwKTtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JWYWx1ZURlZmluaXRpb25QYWlyKHZhbHVlRGVmaW5pdGlvblBhaXIsIGNvbnRyb2xsZXIpIHtcbiAgICBjb25zdCBkZWZpbml0aW9uID0gcGFyc2VWYWx1ZURlZmluaXRpb25QYWlyKHZhbHVlRGVmaW5pdGlvblBhaXIsIGNvbnRyb2xsZXIpO1xuICAgIGNvbnN0IHsga2V5LCBuYW1lLCByZWFkZXI6IHJlYWQsIHdyaXRlcjogd3JpdGUgfSA9IGRlZmluaXRpb247XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW25hbWVdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgdmFsdWUgPSB0aGlzLmRhdGEuZ2V0KGtleSk7XG4gICAgICAgICAgICAgICAgaWYgKHZhbHVlICE9PSBudWxsKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiByZWFkKHZhbHVlKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBkZWZpbml0aW9uLmRlZmF1bHRWYWx1ZTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICAgICAgc2V0KHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgaWYgKHZhbHVlID09PSB1bmRlZmluZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgdGhpcy5kYXRhLmRlbGV0ZShrZXkpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgdGhpcy5kYXRhLnNldChrZXksIHdyaXRlKHZhbHVlKSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2BoYXMke2NhcGl0YWxpemUobmFtZSl9YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy5kYXRhLmhhcyhrZXkpIHx8IGRlZmluaXRpb24uaGFzQ3VzdG9tRGVmYXVsdFZhbHVlO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICB9O1xufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZURlZmluaXRpb25QYWlyKFt0b2tlbiwgdHlwZURlZmluaXRpb25dLCBjb250cm9sbGVyKSB7XG4gICAgcmV0dXJuIHZhbHVlRGVzY3JpcHRvckZvclRva2VuQW5kVHlwZURlZmluaXRpb24oe1xuICAgICAgICBjb250cm9sbGVyLFxuICAgICAgICB0b2tlbixcbiAgICAgICAgdHlwZURlZmluaXRpb24sXG4gICAgfSk7XG59XG5mdW5jdGlvbiBwYXJzZVZhbHVlVHlwZUNvbnN0YW50KGNvbnN0YW50KSB7XG4gICAgc3dpdGNoIChjb25zdGFudCkge1xuICAgICAgICBjYXNlIEFycmF5OlxuICAgICAgICAgICAgcmV0dXJuIFwiYXJyYXlcIjtcbiAgICAgICAgY2FzZSBCb29sZWFuOlxuICAgICAgICAgICAgcmV0dXJuIFwiYm9vbGVhblwiO1xuICAgICAgICBjYXNlIE51bWJlcjpcbiAgICAgICAgICAgIHJldHVybiBcIm51bWJlclwiO1xuICAgICAgICBjYXNlIE9iamVjdDpcbiAgICAgICAgICAgIHJldHVybiBcIm9iamVjdFwiO1xuICAgICAgICBjYXNlIFN0cmluZzpcbiAgICAgICAgICAgIHJldHVybiBcInN0cmluZ1wiO1xuICAgIH1cbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVUeXBlRGVmYXVsdChkZWZhdWx0VmFsdWUpIHtcbiAgICBzd2l0Y2ggKHR5cGVvZiBkZWZhdWx0VmFsdWUpIHtcbiAgICAgICAgY2FzZSBcImJvb2xlYW5cIjpcbiAgICAgICAgICAgIHJldHVybiBcImJvb2xlYW5cIjtcbiAgICAgICAgY2FzZSBcIm51bWJlclwiOlxuICAgICAgICAgICAgcmV0dXJuIFwibnVtYmVyXCI7XG4gICAgICAgIGNhc2UgXCJzdHJpbmdcIjpcbiAgICAgICAgICAgIHJldHVybiBcInN0cmluZ1wiO1xuICAgIH1cbiAgICBpZiAoQXJyYXkuaXNBcnJheShkZWZhdWx0VmFsdWUpKVxuICAgICAgICByZXR1cm4gXCJhcnJheVwiO1xuICAgIGlmIChPYmplY3QucHJvdG90eXBlLnRvU3RyaW5nLmNhbGwoZGVmYXVsdFZhbHVlKSA9PT0gXCJbb2JqZWN0IE9iamVjdF1cIilcbiAgICAgICAgcmV0dXJuIFwib2JqZWN0XCI7XG59XG5mdW5jdGlvbiBwYXJzZVZhbHVlVHlwZU9iamVjdChwYXlsb2FkKSB7XG4gICAgY29uc3QgeyBjb250cm9sbGVyLCB0b2tlbiwgdHlwZU9iamVjdCB9ID0gcGF5bG9hZDtcbiAgICBjb25zdCBoYXNUeXBlID0gaXNTb21ldGhpbmcodHlwZU9iamVjdC50eXBlKTtcbiAgICBjb25zdCBoYXNEZWZhdWx0ID0gaXNTb21ldGhpbmcodHlwZU9iamVjdC5kZWZhdWx0KTtcbiAgICBjb25zdCBmdWxsT2JqZWN0ID0gaGFzVHlwZSAmJiBoYXNEZWZhdWx0O1xuICAgIGNvbnN0IG9ubHlUeXBlID0gaGFzVHlwZSAmJiAhaGFzRGVmYXVsdDtcbiAgICBjb25zdCBvbmx5RGVmYXVsdCA9ICFoYXNUeXBlICYmIGhhc0RlZmF1bHQ7XG4gICAgY29uc3QgdHlwZUZyb21PYmplY3QgPSBwYXJzZVZhbHVlVHlwZUNvbnN0YW50KHR5cGVPYmplY3QudHlwZSk7XG4gICAgY29uc3QgdHlwZUZyb21EZWZhdWx0VmFsdWUgPSBwYXJzZVZhbHVlVHlwZURlZmF1bHQocGF5bG9hZC50eXBlT2JqZWN0LmRlZmF1bHQpO1xuICAgIGlmIChvbmx5VHlwZSlcbiAgICAgICAgcmV0dXJuIHR5cGVGcm9tT2JqZWN0O1xuICAgIGlmIChvbmx5RGVmYXVsdClcbiAgICAgICAgcmV0dXJuIHR5cGVGcm9tRGVmYXVsdFZhbHVlO1xuICAgIGlmICh0eXBlRnJvbU9iamVjdCAhPT0gdHlwZUZyb21EZWZhdWx0VmFsdWUpIHtcbiAgICAgICAgY29uc3QgcHJvcGVydHlQYXRoID0gY29udHJvbGxlciA/IGAke2NvbnRyb2xsZXJ9LiR7dG9rZW59YCA6IHRva2VuO1xuICAgICAgICB0aHJvdyBuZXcgRXJyb3IoYFRoZSBzcGVjaWZpZWQgZGVmYXVsdCB2YWx1ZSBmb3IgdGhlIFN0aW11bHVzIFZhbHVlIFwiJHtwcm9wZXJ0eVBhdGh9XCIgbXVzdCBtYXRjaCB0aGUgZGVmaW5lZCB0eXBlIFwiJHt0eXBlRnJvbU9iamVjdH1cIi4gVGhlIHByb3ZpZGVkIGRlZmF1bHQgdmFsdWUgb2YgXCIke3R5cGVPYmplY3QuZGVmYXVsdH1cIiBpcyBvZiB0eXBlIFwiJHt0eXBlRnJvbURlZmF1bHRWYWx1ZX1cIi5gKTtcbiAgICB9XG4gICAgaWYgKGZ1bGxPYmplY3QpXG4gICAgICAgIHJldHVybiB0eXBlRnJvbU9iamVjdDtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVUeXBlRGVmaW5pdGlvbihwYXlsb2FkKSB7XG4gICAgY29uc3QgeyBjb250cm9sbGVyLCB0b2tlbiwgdHlwZURlZmluaXRpb24gfSA9IHBheWxvYWQ7XG4gICAgY29uc3QgdHlwZU9iamVjdCA9IHsgY29udHJvbGxlciwgdG9rZW4sIHR5cGVPYmplY3Q6IHR5cGVEZWZpbml0aW9uIH07XG4gICAgY29uc3QgdHlwZUZyb21PYmplY3QgPSBwYXJzZVZhbHVlVHlwZU9iamVjdCh0eXBlT2JqZWN0KTtcbiAgICBjb25zdCB0eXBlRnJvbURlZmF1bHRWYWx1ZSA9IHBhcnNlVmFsdWVUeXBlRGVmYXVsdCh0eXBlRGVmaW5pdGlvbik7XG4gICAgY29uc3QgdHlwZUZyb21Db25zdGFudCA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZURlZmluaXRpb24pO1xuICAgIGNvbnN0IHR5cGUgPSB0eXBlRnJvbU9iamVjdCB8fCB0eXBlRnJvbURlZmF1bHRWYWx1ZSB8fCB0eXBlRnJvbUNvbnN0YW50O1xuICAgIGlmICh0eXBlKVxuICAgICAgICByZXR1cm4gdHlwZTtcbiAgICBjb25zdCBwcm9wZXJ0eVBhdGggPSBjb250cm9sbGVyID8gYCR7Y29udHJvbGxlcn0uJHt0eXBlRGVmaW5pdGlvbn1gIDogdG9rZW47XG4gICAgdGhyb3cgbmV3IEVycm9yKGBVbmtub3duIHZhbHVlIHR5cGUgXCIke3Byb3BlcnR5UGF0aH1cIiBmb3IgXCIke3Rva2VufVwiIHZhbHVlYCk7XG59XG5mdW5jdGlvbiBkZWZhdWx0VmFsdWVGb3JEZWZpbml0aW9uKHR5cGVEZWZpbml0aW9uKSB7XG4gICAgY29uc3QgY29uc3RhbnQgPSBwYXJzZVZhbHVlVHlwZUNvbnN0YW50KHR5cGVEZWZpbml0aW9uKTtcbiAgICBpZiAoY29uc3RhbnQpXG4gICAgICAgIHJldHVybiBkZWZhdWx0VmFsdWVzQnlUeXBlW2NvbnN0YW50XTtcbiAgICBjb25zdCBoYXNEZWZhdWx0ID0gaGFzUHJvcGVydHkodHlwZURlZmluaXRpb24sIFwiZGVmYXVsdFwiKTtcbiAgICBjb25zdCBoYXNUeXBlID0gaGFzUHJvcGVydHkodHlwZURlZmluaXRpb24sIFwidHlwZVwiKTtcbiAgICBjb25zdCB0eXBlT2JqZWN0ID0gdHlwZURlZmluaXRpb247XG4gICAgaWYgKGhhc0RlZmF1bHQpXG4gICAgICAgIHJldHVybiB0eXBlT2JqZWN0LmRlZmF1bHQ7XG4gICAgaWYgKGhhc1R5cGUpIHtcbiAgICAgICAgY29uc3QgeyB0eXBlIH0gPSB0eXBlT2JqZWN0O1xuICAgICAgICBjb25zdCBjb25zdGFudEZyb21UeXBlID0gcGFyc2VWYWx1ZVR5cGVDb25zdGFudCh0eXBlKTtcbiAgICAgICAgaWYgKGNvbnN0YW50RnJvbVR5cGUpXG4gICAgICAgICAgICByZXR1cm4gZGVmYXVsdFZhbHVlc0J5VHlwZVtjb25zdGFudEZyb21UeXBlXTtcbiAgICB9XG4gICAgcmV0dXJuIHR5cGVEZWZpbml0aW9uO1xufVxuZnVuY3Rpb24gdmFsdWVEZXNjcmlwdG9yRm9yVG9rZW5BbmRUeXBlRGVmaW5pdGlvbihwYXlsb2FkKSB7XG4gICAgY29uc3QgeyB0b2tlbiwgdHlwZURlZmluaXRpb24gfSA9IHBheWxvYWQ7XG4gICAgY29uc3Qga2V5ID0gYCR7ZGFzaGVyaXplKHRva2VuKX0tdmFsdWVgO1xuICAgIGNvbnN0IHR5cGUgPSBwYXJzZVZhbHVlVHlwZURlZmluaXRpb24ocGF5bG9hZCk7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgdHlwZSxcbiAgICAgICAga2V5LFxuICAgICAgICBuYW1lOiBjYW1lbGl6ZShrZXkpLFxuICAgICAgICBnZXQgZGVmYXVsdFZhbHVlKCkge1xuICAgICAgICAgICAgcmV0dXJuIGRlZmF1bHRWYWx1ZUZvckRlZmluaXRpb24odHlwZURlZmluaXRpb24pO1xuICAgICAgICB9LFxuICAgICAgICBnZXQgaGFzQ3VzdG9tRGVmYXVsdFZhbHVlKCkge1xuICAgICAgICAgICAgcmV0dXJuIHBhcnNlVmFsdWVUeXBlRGVmYXVsdCh0eXBlRGVmaW5pdGlvbikgIT09IHVuZGVmaW5lZDtcbiAgICAgICAgfSxcbiAgICAgICAgcmVhZGVyOiByZWFkZXJzW3R5cGVdLFxuICAgICAgICB3cml0ZXI6IHdyaXRlcnNbdHlwZV0gfHwgd3JpdGVycy5kZWZhdWx0LFxuICAgIH07XG59XG5jb25zdCBkZWZhdWx0VmFsdWVzQnlUeXBlID0ge1xuICAgIGdldCBhcnJheSgpIHtcbiAgICAgICAgcmV0dXJuIFtdO1xuICAgIH0sXG4gICAgYm9vbGVhbjogZmFsc2UsXG4gICAgbnVtYmVyOiAwLFxuICAgIGdldCBvYmplY3QoKSB7XG4gICAgICAgIHJldHVybiB7fTtcbiAgICB9LFxuICAgIHN0cmluZzogXCJcIixcbn07XG5jb25zdCByZWFkZXJzID0ge1xuICAgIGFycmF5KHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IGFycmF5ID0gSlNPTi5wYXJzZSh2YWx1ZSk7XG4gICAgICAgIGlmICghQXJyYXkuaXNBcnJheShhcnJheSkpIHtcbiAgICAgICAgICAgIHRocm93IG5ldyBUeXBlRXJyb3IoYGV4cGVjdGVkIHZhbHVlIG9mIHR5cGUgXCJhcnJheVwiIGJ1dCBpbnN0ZWFkIGdvdCB2YWx1ZSBcIiR7dmFsdWV9XCIgb2YgdHlwZSBcIiR7cGFyc2VWYWx1ZVR5cGVEZWZhdWx0KGFycmF5KX1cImApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBhcnJheTtcbiAgICB9LFxuICAgIGJvb2xlYW4odmFsdWUpIHtcbiAgICAgICAgcmV0dXJuICEodmFsdWUgPT0gXCIwXCIgfHwgU3RyaW5nKHZhbHVlKS50b0xvd2VyQ2FzZSgpID09IFwiZmFsc2VcIik7XG4gICAgfSxcbiAgICBudW1iZXIodmFsdWUpIHtcbiAgICAgICAgcmV0dXJuIE51bWJlcih2YWx1ZS5yZXBsYWNlKC9fL2csIFwiXCIpKTtcbiAgICB9LFxuICAgIG9iamVjdCh2YWx1ZSkge1xuICAgICAgICBjb25zdCBvYmplY3QgPSBKU09OLnBhcnNlKHZhbHVlKTtcbiAgICAgICAgaWYgKG9iamVjdCA9PT0gbnVsbCB8fCB0eXBlb2Ygb2JqZWN0ICE9IFwib2JqZWN0XCIgfHwgQXJyYXkuaXNBcnJheShvYmplY3QpKSB7XG4gICAgICAgICAgICB0aHJvdyBuZXcgVHlwZUVycm9yKGBleHBlY3RlZCB2YWx1ZSBvZiB0eXBlIFwib2JqZWN0XCIgYnV0IGluc3RlYWQgZ290IHZhbHVlIFwiJHt2YWx1ZX1cIiBvZiB0eXBlIFwiJHtwYXJzZVZhbHVlVHlwZURlZmF1bHQob2JqZWN0KX1cImApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBvYmplY3Q7XG4gICAgfSxcbiAgICBzdHJpbmcodmFsdWUpIHtcbiAgICAgICAgcmV0dXJuIHZhbHVlO1xuICAgIH0sXG59O1xuY29uc3Qgd3JpdGVycyA9IHtcbiAgICBkZWZhdWx0OiB3cml0ZVN0cmluZyxcbiAgICBhcnJheTogd3JpdGVKU09OLFxuICAgIG9iamVjdDogd3JpdGVKU09OLFxufTtcbmZ1bmN0aW9uIHdyaXRlSlNPTih2YWx1ZSkge1xuICAgIHJldHVybiBKU09OLnN0cmluZ2lmeSh2YWx1ZSk7XG59XG5mdW5jdGlvbiB3cml0ZVN0cmluZyh2YWx1ZSkge1xuICAgIHJldHVybiBgJHt2YWx1ZX1gO1xufVxuXG5jbGFzcyBDb250cm9sbGVyIHtcbiAgICBjb25zdHJ1Y3Rvcihjb250ZXh0KSB7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgfVxuICAgIHN0YXRpYyBnZXQgc2hvdWxkTG9hZCgpIHtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfVxuICAgIHN0YXRpYyBhZnRlckxvYWQoX2lkZW50aWZpZXIsIF9hcHBsaWNhdGlvbikge1xuICAgICAgICByZXR1cm47XG4gICAgfVxuICAgIGdldCBhcHBsaWNhdGlvbigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5hcHBsaWNhdGlvbjtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCB0YXJnZXRzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS50YXJnZXRzO1xuICAgIH1cbiAgICBnZXQgb3V0bGV0cygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUub3V0bGV0cztcbiAgICB9XG4gICAgZ2V0IGNsYXNzZXMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmNsYXNzZXM7XG4gICAgfVxuICAgIGdldCBkYXRhKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5kYXRhO1xuICAgIH1cbiAgICBpbml0aWFsaXplKCkge1xuICAgIH1cbiAgICBjb25uZWN0KCkge1xuICAgIH1cbiAgICBkaXNjb25uZWN0KCkge1xuICAgIH1cbiAgICBkaXNwYXRjaChldmVudE5hbWUsIHsgdGFyZ2V0ID0gdGhpcy5lbGVtZW50LCBkZXRhaWwgPSB7fSwgcHJlZml4ID0gdGhpcy5pZGVudGlmaWVyLCBidWJibGVzID0gdHJ1ZSwgY2FuY2VsYWJsZSA9IHRydWUsIH0gPSB7fSkge1xuICAgICAgICBjb25zdCB0eXBlID0gcHJlZml4ID8gYCR7cHJlZml4fToke2V2ZW50TmFtZX1gIDogZXZlbnROYW1lO1xuICAgICAgICBjb25zdCBldmVudCA9IG5ldyBDdXN0b21FdmVudCh0eXBlLCB7IGRldGFpbCwgYnViYmxlcywgY2FuY2VsYWJsZSB9KTtcbiAgICAgICAgdGFyZ2V0LmRpc3BhdGNoRXZlbnQoZXZlbnQpO1xuICAgICAgICByZXR1cm4gZXZlbnQ7XG4gICAgfVxufVxuQ29udHJvbGxlci5ibGVzc2luZ3MgPSBbXG4gICAgQ2xhc3NQcm9wZXJ0aWVzQmxlc3NpbmcsXG4gICAgVGFyZ2V0UHJvcGVydGllc0JsZXNzaW5nLFxuICAgIFZhbHVlUHJvcGVydGllc0JsZXNzaW5nLFxuICAgIE91dGxldFByb3BlcnRpZXNCbGVzc2luZyxcbl07XG5Db250cm9sbGVyLnRhcmdldHMgPSBbXTtcbkNvbnRyb2xsZXIub3V0bGV0cyA9IFtdO1xuQ29udHJvbGxlci52YWx1ZXMgPSB7fTtcblxuZXhwb3J0IHsgQXBwbGljYXRpb24sIEF0dHJpYnV0ZU9ic2VydmVyLCBDb250ZXh0LCBDb250cm9sbGVyLCBFbGVtZW50T2JzZXJ2ZXIsIEluZGV4ZWRNdWx0aW1hcCwgTXVsdGltYXAsIFNlbGVjdG9yT2JzZXJ2ZXIsIFN0cmluZ01hcE9ic2VydmVyLCBUb2tlbkxpc3RPYnNlcnZlciwgVmFsdWVMaXN0T2JzZXJ2ZXIsIGFkZCwgZGVmYXVsdFNjaGVtYSwgZGVsLCBmZXRjaCwgcHJ1bmUgfTtcbiIsICJpbXBvcnQgeyBDb250cm9sbGVyIH0gZnJvbSBcIkBob3R3aXJlZC9zdGltdWx1c1wiXG5cbi8qKlxuICogU3RpbXVsdXMgQ29udHJvbGxlciBmb3IgXCJCdXkgTm93XCIgYnV0dG9uXG4gKlxuICogSGFuZGxlcyBkaXJlY3QgcHJvZHVjdC10by1jaGVja291dCBmbG93XG4gKlxuICogVXNhZ2UgaW4gVHdpZzpcbiAqIDxkaXYgZGF0YS1jb250cm9sbGVyPVwiYnV5LW5vd1wiXG4gKiAgICAgIGRhdGEtYnV5LW5vdy1wcm9kdWN0LWlkLXZhbHVlPVwiLi4uXCJcbiAqICAgICAgZGF0YS1idXktbm93LXByb2R1Y3QtbmlkLXZhbHVlPVwiLi4uXCJcbiAqICAgICAgZGF0YS1idXktbm93LXBhcmVudC1pZC12YWx1ZT1cIi4uLlwiXG4gKiAgICAgIGRhdGEtYnV5LW5vdy1hY3Rpb24tdXJsLXZhbHVlPVwiLi4uXCJcbiAqICAgICAgZGF0YS1idXktbm93LWNzcmYtdG9rZW4tdmFsdWU9XCIuLi5cIj5cbiAqICAgPGJ1dHRvbiBkYXRhLWFjdGlvbj1cImJ1eS1ub3cjc3VibWl0XCI+QnV5IE5vdzwvYnV0dG9uPlxuICogPC9kaXY+XG4gKi9cbmV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgQ29udHJvbGxlciB7XG4gIHN0YXRpYyB2YWx1ZXMgPSB7XG4gICAgcHJvZHVjdElkOiBTdHJpbmcsXG4gICAgcHJvZHVjdE5pZDogU3RyaW5nLFxuICAgIHBhcmVudElkOiBTdHJpbmcsXG4gICAgYWN0aW9uVXJsOiBTdHJpbmcsXG4gICAgY3NyZlRva2VuOiBTdHJpbmdcbiAgfVxuXG4gIHN0YXRpYyB0YXJnZXRzID0gW1wiYnV0dG9uXCJdXG5cbiAgY29ubmVjdCgpIHtcbiAgICBjb25zb2xlLmxvZygnU3RyaXBlIEJ1eSBOb3cgY29udHJvbGxlciBjb25uZWN0ZWQnLCB7XG4gICAgICBwcm9kdWN0SWQ6IHRoaXMucHJvZHVjdElkVmFsdWUsXG4gICAgICBwcm9kdWN0TmlkOiB0aGlzLnByb2R1Y3ROaWRWYWx1ZVxuICAgIH0pXG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIEJ1eSBOb3cgYnV0dG9uIGNsaWNrXG4gICAqIEBwYXJhbSB7RXZlbnR9IGV2ZW50XG4gICAqL1xuICBzdWJtaXQoZXZlbnQpIHtcbiAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpXG5cbiAgICBjb25zb2xlLmxvZygnQnV5IE5vdyBjbGlja2VkJylcblxuICAgIGNvbnN0IGJ1dHRvbiA9IGV2ZW50LmN1cnJlbnRUYXJnZXRcblxuICAgIC8vIERpc2FibGUgYnV0dG9uIGFuZCBzaG93IGxvYWRpbmcgc3RhdGVcbiAgICB0aGlzLnNldExvYWRpbmdTdGF0ZShidXR0b24sIHRydWUpXG5cbiAgICAvLyBHZXQgcXVhbnRpdHkgZnJvbSBhbW91bnQgaW5wdXRcbiAgICBjb25zdCBhbW91bnRJbnB1dCA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdhbW91bnRUb0Jhc2tldCcpXG4gICAgY29uc3QgYW1vdW50ID0gYW1vdW50SW5wdXQgPyBhbW91bnRJbnB1dC52YWx1ZSA6IDFcblxuICAgIC8vIEdldCBwcm9kdWN0IGZvcm0gZGF0YSAoZm9yIHZhcmlhbnRzLCBzZWxlY3Rpb25zLCBldGMuKVxuICAgIGNvbnN0IHByb2R1Y3RGb3JtID0gZG9jdW1lbnQucXVlcnlTZWxlY3RvcignLmpzLW94UHJvZHVjdEZvcm0nKVxuICAgIGNvbnN0IGZvcm1EYXRhID0gcHJvZHVjdEZvcm0gPyBuZXcgRm9ybURhdGEocHJvZHVjdEZvcm0pIDogbmV3IEZvcm1EYXRhKClcblxuICAgIC8vIFByZXBhcmUgZm9ybSBmaWVsZHNcbiAgICBjb25zdCBmaWVsZHMgPSB7XG4gICAgICAnY2wnOiAnc3RyaXBlX2NoZWNrb3V0X29uZXBhZ2UnLFxuICAgICAgJ2ZuYyc6ICdhZGRQcm9kdWN0QW5kQ2hlY2tvdXQnLFxuICAgICAgJ2FpZCc6IHRoaXMucHJvZHVjdElkVmFsdWUsXG4gICAgICAnYW5pZCc6IHRoaXMucHJvZHVjdE5pZFZhbHVlLFxuICAgICAgJ3BhcmVudGlkJzogdGhpcy5wYXJlbnRJZFZhbHVlLFxuICAgICAgJ2FtJzogYW1vdW50LFxuICAgICAgJ3N0b2tlbic6IHRoaXMuY3NyZlRva2VuVmFsdWVcbiAgICB9XG5cbiAgICAvLyBBZGQgdmFyaWFudCBzZWxlY3Rpb25zIGZyb20gcHJvZHVjdCBmb3JtXG4gICAgZm9yIChsZXQgW2tleSwgdmFsdWVdIG9mIGZvcm1EYXRhLmVudHJpZXMoKSkge1xuICAgICAgaWYgKCFmaWVsZHNba2V5XSAmJiBrZXkgIT09ICdmbmMnICYmIGtleSAhPT0gJ2NsJykge1xuICAgICAgICBmaWVsZHNba2V5XSA9IHZhbHVlXG4gICAgICB9XG4gICAgfVxuXG4gICAgY29uc29sZS5sb2coJ1N1Ym1pdHRpbmcgQnV5IE5vdyBmb3JtOicsIGZpZWxkcylcblxuICAgIC8vIENyZWF0ZSBhbmQgc3VibWl0IGhpZGRlbiBmb3JtXG4gICAgdGhpcy5zdWJtaXRGb3JtKGZpZWxkcylcbiAgfVxuXG4gIC8qKlxuICAgKiBDcmVhdGUgaGlkZGVuIGZvcm0gYW5kIHN1Ym1pdFxuICAgKiBAcGFyYW0ge09iamVjdH0gZmllbGRzXG4gICAqL1xuICBzdWJtaXRGb3JtKGZpZWxkcykge1xuICAgIGNvbnN0IGZvcm0gPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdmb3JtJylcbiAgICBmb3JtLm1ldGhvZCA9ICdQT1NUJ1xuICAgIGZvcm0uYWN0aW9uID0gdGhpcy5hY3Rpb25VcmxWYWx1ZVxuICAgIGZvcm0uc3R5bGUuZGlzcGxheSA9ICdub25lJ1xuXG4gICAgLy8gQWRkIGFsbCBmaWVsZHMgYXMgaGlkZGVuIGlucHV0c1xuICAgIE9iamVjdC5lbnRyaWVzKGZpZWxkcykuZm9yRWFjaCgoW25hbWUsIHZhbHVlXSkgPT4ge1xuICAgICAgY29uc3QgaW5wdXQgPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdpbnB1dCcpXG4gICAgICBpbnB1dC50eXBlID0gJ2hpZGRlbidcbiAgICAgIGlucHV0Lm5hbWUgPSBuYW1lXG4gICAgICBpbnB1dC52YWx1ZSA9IHZhbHVlXG4gICAgICBmb3JtLmFwcGVuZENoaWxkKGlucHV0KVxuICAgIH0pXG5cbiAgICAvLyBBZGQgdG8gRE9NIGFuZCBzdWJtaXRcbiAgICBkb2N1bWVudC5ib2R5LmFwcGVuZENoaWxkKGZvcm0pXG5cbiAgICAvLyBTbWFsbCBkZWxheSB0byBlbnN1cmUgZm9ybSBpcyBpbiBET01cbiAgICBzZXRUaW1lb3V0KCgpID0+IHtcbiAgICAgIGZvcm0uc3VibWl0KClcbiAgICB9LCAxMDApXG4gIH1cblxuICAvKipcbiAgICogU2V0IGJ1dHRvbiBsb2FkaW5nIHN0YXRlXG4gICAqIEBwYXJhbSB7SFRNTEVsZW1lbnR9IGJ1dHRvblxuICAgKiBAcGFyYW0ge0Jvb2xlYW59IGlzTG9hZGluZ1xuICAgKi9cbiAgc2V0TG9hZGluZ1N0YXRlKGJ1dHRvbiwgaXNMb2FkaW5nKSB7XG4gICAgaWYgKGlzTG9hZGluZykge1xuICAgICAgLy8gU3RvcmUgb3JpZ2luYWwgSFRNTFxuICAgICAgYnV0dG9uLmRhdGFzZXQub3JpZ2luYWxIdG1sID0gYnV0dG9uLmlubmVySFRNTFxuXG4gICAgICAvLyBTZXQgbG9hZGluZyBzdGF0ZVxuICAgICAgYnV0dG9uLmRpc2FibGVkID0gdHJ1ZVxuICAgICAgYnV0dG9uLmlubmVySFRNTCA9IGBcbiAgICAgICAgPHNwYW4gY2xhc3M9XCJzcGlubmVyLWJvcmRlciBzcGlubmVyLWJvcmRlci1zbSBtZS0yXCIgcm9sZT1cInN0YXR1c1wiIGFyaWEtaGlkZGVuPVwidHJ1ZVwiPjwvc3Bhbj5cbiAgICAgICAgUHJvY2Vzc2luZy4uLlxuICAgICAgYFxuICAgIH0gZWxzZSB7XG4gICAgICAvLyBSZXN0b3JlIG9yaWdpbmFsIHN0YXRlXG4gICAgICBidXR0b24uZGlzYWJsZWQgPSBmYWxzZVxuICAgICAgaWYgKGJ1dHRvbi5kYXRhc2V0Lm9yaWdpbmFsSHRtbCkge1xuICAgICAgICBidXR0b24uaW5uZXJIVE1MID0gYnV0dG9uLmRhdGFzZXQub3JpZ2luYWxIdG1sXG4gICAgICB9XG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBlcnJvcnNcbiAgICogQHBhcmFtIHtFcnJvcn0gZXJyb3JcbiAgICovXG4gIGhhbmRsZUVycm9yKGVycm9yKSB7XG4gICAgY29uc29sZS5lcnJvcignQnV5IE5vdyBlcnJvcjonLCBlcnJvcilcblxuICAgIC8vIFNob3cgZXJyb3IgdG8gdXNlclxuICAgIGFsZXJ0KCdTb3JyeSwgdGhlcmUgd2FzIGFuIGVycm9yIHByb2Nlc3NpbmcgeW91ciByZXF1ZXN0LiBQbGVhc2UgdHJ5IGFnYWluLicpXG5cbiAgICAvLyBSZXNldCBidXR0b24gc3RhdGVcbiAgICBpZiAodGhpcy5oYXNCdXR0b25UYXJnZXQpIHtcbiAgICAgIHRoaXMuc2V0TG9hZGluZ1N0YXRlKHRoaXMuYnV0dG9uVGFyZ2V0LCBmYWxzZSlcbiAgICB9XG4gIH1cbn1cbiIsICJpbXBvcnQgeyBDb250cm9sbGVyIH0gZnJvbSBcIkBob3R3aXJlZC9zdGltdWx1c1wiXG5cbi8qKlxuICogU3RpbXVsdXMgQ29udHJvbGxlciBmb3IgU3RyaXBlIFBheW1lbnQgRWxlbWVudCBvbiBPcmRlciBQYWdlXG4gKlxuICogSGFuZGxlcyBTdHJpcGUgcGF5bWVudCBmb3JtIGluaXRpYWxpemF0aW9uIGFuZCBzdWJtaXNzaW9uIG9uIHRoZSBvcmRlciBjb25maXJtYXRpb24gcGFnZVxuICpcbiAqIFVzYWdlIGluIFR3aWc6XG4gKiA8ZGl2IGRhdGEtY29udHJvbGxlcj1cInN0cmlwZS1vcmRlclwiXG4gKiAgICAgIGRhdGEtc3RyaXBlLW9yZGVyLXB1Ymxpc2hhYmxlLWtleS12YWx1ZT1cInBrXy4uLlwiXG4gKiAgICAgIGRhdGEtc3RyaXBlLW9yZGVyLWNsaWVudC1zZWNyZXQtdmFsdWU9XCJwaV8uLi5fc2VjcmV0Xy4uLlwiPlxuICogICA8ZGl2IGlkPVwicGF5bWVudC1lbGVtZW50XCI+PC9kaXY+XG4gKiAgIDxkaXYgaWQ9XCJwYXltZW50LWVycm9yc1wiIHN0eWxlPVwiZGlzcGxheTpub25lXCI+XG4gKiAgICAgPHNwYW4gZGF0YS1zdHJpcGUtb3JkZXItdGFyZ2V0PVwiZXJyb3JNZXNzYWdlXCI+PC9zcGFuPlxuICogICA8L2Rpdj5cbiAqIDwvZGl2PlxuICovXG5leHBvcnQgZGVmYXVsdCBjbGFzcyBleHRlbmRzIENvbnRyb2xsZXIge1xuICBzdGF0aWMgdmFsdWVzID0ge1xuICAgIHB1Ymxpc2hhYmxlS2V5OiBTdHJpbmcsXG4gICAgY2xpZW50U2VjcmV0OiBTdHJpbmdcbiAgfVxuXG4gIHN0YXRpYyB0YXJnZXRzID0gW1wiZXJyb3JNZXNzYWdlXCIsIFwibG9hZGluZ1wiXVxuXG4gIGNvbm5lY3QoKSB7XG4gICAgY29uc29sZS5sb2coJ1N0cmlwZSBPcmRlciBjb250cm9sbGVyIGNvbm5lY3RlZCcsIHtcbiAgICAgIGhhc1B1Ymxpc2hhYmxlS2V5OiAhIXRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSxcbiAgICAgIHB1Ymxpc2hhYmxlS2V5OiB0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUgPyB0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUuc3Vic3RyaW5nKDAsIDEwKSArICcuLi4nIDogJ21pc3NpbmcnLFxuICAgIH0pXG5cbiAgICAvLyBHZXQgZGVidWcgaW5mbyBmcm9tIGVsZW1lbnRcbiAgICBjb25zdCBkZWJ1Z0luZm8gPSB0aGlzLmVsZW1lbnQuZ2V0QXR0cmlidXRlKCdkYXRhLWRlYnVnLWluZm8nKVxuICAgIGlmIChkZWJ1Z0luZm8pIHtcbiAgICAgIGNvbnNvbGUubG9nKCdEZWJ1ZyBpbmZvOicsIGRlYnVnSW5mbylcbiAgICB9XG5cbiAgICAvLyBWYWxpZGF0ZSByZXF1aXJlZCBjb25maWd1cmF0aW9uXG4gICAgaWYgKCF0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ1N0cmlwZSBwdWJsaXNoYWJsZSBrZXkgbm90IGNvbmZpZ3VyZWQnKVxuICAgICAgdGhpcy5zaG93RXJyb3IoJ1N0cmlwZSBjb25maWd1cmF0aW9uIGVycm9yLiBQbGVhc2UgY29udGFjdCBzdXBwb3J0LicpXG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICAvLyBXYWl0IGZvciBTdHJpcGUuanMgdG8gbG9hZFxuICAgIHRoaXMuaW5pdGlhbGl6ZVN0cmlwZSgpXG4gIH1cblxuICBkaXNjb25uZWN0KCkge1xuICAgIC8vIENsZWFudXAgaWYgbmVlZGVkXG4gICAgaWYgKHRoaXMucGF5bWVudEVsZW1lbnQpIHtcbiAgICAgIHRoaXMucGF5bWVudEVsZW1lbnQudW5tb3VudCgpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEluaXRpYWxpemUgU3RyaXBlIGFuZCBtb3VudCBQYXltZW50IEVsZW1lbnRcbiAgICovXG4gIGFzeW5jIGluaXRpYWxpemVTdHJpcGUoKSB7XG4gICAgLy8gV2FpdCBmb3IgU3RyaXBlLmpzIHRvIGJlIGF2YWlsYWJsZVxuICAgIGlmICh0eXBlb2YgU3RyaXBlID09PSAndW5kZWZpbmVkJykge1xuICAgICAgY29uc29sZS5sb2coJ1dhaXRpbmcgZm9yIFN0cmlwZS5qcyB0byBsb2FkLi4uJylcbiAgICAgIGF3YWl0IHRoaXMud2FpdEZvclN0cmlwZSgpXG4gICAgfVxuXG4gICAgdHJ5IHtcbiAgICAgIC8vIEluaXRpYWxpemUgU3RyaXBlXG4gICAgICB0aGlzLnN0cmlwZSA9IFN0cmlwZSh0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUpXG5cbiAgICAgIC8vIENyZWF0ZSBFbGVtZW50cyB3aXRoIHN0eWxpbmdcbiAgICAgIGNvbnN0IGFwcGVhcmFuY2UgPSB7XG4gICAgICAgIHRoZW1lOiAnc3RyaXBlJyxcbiAgICAgICAgdmFyaWFibGVzOiB7XG4gICAgICAgICAgY29sb3JQcmltYXJ5OiAnIzA1NzBkZScsXG4gICAgICAgICAgY29sb3JCYWNrZ3JvdW5kOiAnI2ZmZmZmZicsXG4gICAgICAgICAgY29sb3JUZXh0OiAnIzMwMzEzZCcsXG4gICAgICAgICAgZm9udEZhbWlseTogJ3N5c3RlbS11aSwgc2Fucy1zZXJpZicsXG4gICAgICAgICAgYm9yZGVyUmFkaXVzOiAnNHB4J1xuICAgICAgICB9XG4gICAgICB9XG5cbiAgICAgIHRoaXMuZWxlbWVudHMgPSB0aGlzLnN0cmlwZS5lbGVtZW50cyh7XG4gICAgICAgIGFwcGVhcmFuY2U6IGFwcGVhcmFuY2VcbiAgICAgIH0pXG5cbiAgICAgIHRoaXMuY2FyZCA9IHRoaXMuZWxlbWVudHMuY3JlYXRlKCdjYXJkJyk7XG4gICAgICB0aGlzLmNhcmQubW91bnQoJyNjYXJkLWVsZW1lbnQnKTtcblxuICAgICAgY29uc29sZS5sb2coJ1N0cmlwZSBQYXltZW50IEVsZW1lbnQgaW5pdGlhbGl6ZWQgc3VjY2Vzc2Z1bGx5JylcblxuICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdGYWlsZWQgdG8gaW5pdGlhbGl6ZSBTdHJpcGU6JywgZXJyb3IpXG4gICAgICB0aGlzLnNob3dFcnJvcignRmFpbGVkIHRvIGluaXRpYWxpemUgcGF5bWVudCBmb3JtLiBQbGVhc2UgcmVmcmVzaCB0aGUgcGFnZS4nKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBXYWl0IGZvciBTdHJpcGUuanMgbGlicmFyeSB0byBsb2FkXG4gICAqIEByZXR1cm5zIHtQcm9taXNlfVxuICAgKi9cbiAgd2FpdEZvclN0cmlwZSgpIHtcbiAgICByZXR1cm4gbmV3IFByb21pc2UoKHJlc29sdmUpID0+IHtcbiAgICAgIGNvbnN0IGNoZWNrU3RyaXBlID0gKCkgPT4ge1xuICAgICAgICBpZiAodHlwZW9mIFN0cmlwZSAhPT0gJ3VuZGVmaW5lZCcpIHtcbiAgICAgICAgICByZXNvbHZlKClcbiAgICAgICAgfSBlbHNlIHtcbiAgICAgICAgICBzZXRUaW1lb3V0KGNoZWNrU3RyaXBlLCAxMDApXG4gICAgICAgIH1cbiAgICAgIH1cbiAgICAgIGNoZWNrU3RyaXBlKClcbiAgICB9KVxuICB9XG5cbiAgLyoqXG4gICAqIFNob3cgbG9hZGluZyBpbmRpY2F0b3JcbiAgICovXG4gIHNob3dMb2FkaW5nKCkge1xuICAgIGlmICh0aGlzLmhhc0xvYWRpbmdUYXJnZXQpIHtcbiAgICAgIHRoaXMubG9hZGluZ1RhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ2Jsb2NrJ1xuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBTaG93IGVycm9yIG1lc3NhZ2VcbiAgICogQHBhcmFtIHtTdHJpbmd9IG1lc3NhZ2VcbiAgICovXG4gIHNob3dFcnJvcihtZXNzYWdlKSB7XG4gICAgY29uc3QgZXJyb3JEaXYgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgncGF5bWVudC1lcnJvcnMnKVxuICAgIGlmIChlcnJvckRpdiAmJiB0aGlzLmhhc0Vycm9yTWVzc2FnZVRhcmdldCkge1xuICAgICAgZXJyb3JEaXYuc3R5bGUuZGlzcGxheSA9ICdibG9jaydcbiAgICAgIHRoaXMuZXJyb3JNZXNzYWdlVGFyZ2V0LnRleHRDb250ZW50ID0gbWVzc2FnZVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBIaWRlIGVycm9yIG1lc3NhZ2VcbiAgICovXG4gIGhpZGVFcnJvcigpIHtcbiAgICBjb25zdCBlcnJvckRpdiA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdwYXltZW50LWVycm9ycycpXG4gICAgaWYgKGVycm9yRGl2KSB7XG4gICAgICBlcnJvckRpdi5zdHlsZS5kaXNwbGF5ID0gJ25vbmUnXG4gICAgICBpZiAodGhpcy5oYXNFcnJvck1lc3NhZ2VUYXJnZXQpIHtcbiAgICAgICAgdGhpcy5lcnJvck1lc3NhZ2VUYXJnZXQudGV4dENvbnRlbnQgPSAnJ1xuICAgICAgfVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBIaWRlIGxvYWRpbmcgaW5kaWNhdG9yXG4gICAqL1xuICBoaWRlTG9hZGluZygpIHtcbiAgICBpZiAodGhpcy5oYXNMb2FkaW5nVGFyZ2V0KSB7XG4gICAgICB0aGlzLmxvYWRpbmdUYXJnZXQuc3R5bGUuZGlzcGxheSA9ICdub25lJ1xuICAgIH1cbiAgfVxuXG59XG4iLCAiaW1wb3J0IHsgQ29udHJvbGxlciB9IGZyb20gXCJAaG90d2lyZWQvc3RpbXVsdXNcIlxuXG4vKipcbiAqIFN0aW11bHVzIENvbnRyb2xsZXIgZm9yIE9yZGVyIFN1Ym1pdCBCdXR0b25cbiAqXG4gKiBIYW5kbGVzIG9yZGVyIHN1Ym1pc3Npb24gb24gdGhlIGNoZWNrb3V0IG9yZGVyIHBhZ2UuXG4gKiBTdXBwb3J0cyB0d28gcGF5bWVudCBmbG93czpcbiAqIDEuIFN0cmlwZSBDaGVja291dCAoaG9zdGVkIHBhZ2UpIC0gZm9yIHdhbGxldCBwYXltZW50c1xuICogMi4gUGF5bWVudCBJbnRlbnQgKGNhcmQgZWxlbWVudCkgLSBmb3IgY2FyZCBwYXltZW50c1xuICpcbiAqIFVzYWdlIGluIFR3aWc6XG4gKiA8YnV0dG9uIGRhdGEtY29udHJvbGxlcj1cIm9yZGVyLXN1Ym1pdFwiXG4gKiAgICAgICAgIGRhdGEtYWN0aW9uPVwiY2xpY2stPm9yZGVyLXN1Ym1pdCNoYW5kbGVTdWJtaXRcIlxuICogICAgICAgICBkYXRhLW9yZGVyLXN1Ym1pdC11cmwtdmFsdWU9XCIuLi5cIlxuICogICAgICAgICBkYXRhLW9yZGVyLXN1Ym1pdC1wYXltZW50LXR5cGUtdmFsdWU9XCJ3YWxsZXR8Y2FyZFwiXG4gKiAgICAgICAgIHR5cGU9XCJidXR0b25cIj5cbiAqICAgU3VibWl0IE9yZGVyXG4gKiA8L2J1dHRvbj5cbiAqL1xuZXhwb3J0IGRlZmF1bHQgY2xhc3MgZXh0ZW5kcyBDb250cm9sbGVyIHtcbiAgc3RhdGljIHRhcmdldHMgPSBbXCJzdGF0dXNcIl1cbiAgc3RhdGljIHZhbHVlcyA9IHtcbiAgICB1cmw6IFN0cmluZyxcbiAgICBwYXltZW50VHlwZTogU3RyaW5nLFxuICAgIHB1Ymxpc2hhYmxlS2V5OiBTdHJpbmdcbiAgfVxuXG4gIC8qKlxuICAgKiBDYWxsZWQgd2hlbiBjb250cm9sbGVyIGlzIGNvbm5lY3RlZCB0byBET01cbiAgICovXG4gIGNvbm5lY3QoKSB7XG4gICAgY29uc29sZS5sb2coJ09yZGVyIFN1Ym1pdCBjb250cm9sbGVyIGNvbm5lY3RlZCcpXG4gICAgY29uc29sZS5sb2coJ0J1dHRvbiBlbGVtZW50OicsIHRoaXMuZWxlbWVudClcbiAgfVxuXG4gIC8qKlxuICAgKiBDYWxsZWQgd2hlbiBjb250cm9sbGVyIGlzIGRpc2Nvbm5lY3RlZCBmcm9tIERPTVxuICAgKi9cbiAgZGlzY29ubmVjdCgpIHtcbiAgICBjb25zb2xlLmxvZygnT3JkZXIgU3VibWl0IGNvbnRyb2xsZXIgZGlzY29ubmVjdGVkJylcbiAgfVxuXG4gIC8qKlxuICAgKiBHZXQgdGhlIHN0cmlwZS1vcmRlciBjb250cm9sbGVyIGluc3RhbmNlXG4gICAqIEByZXR1cm5zIHtDb250cm9sbGVyfG51bGx9XG4gICAqL1xuICBnZXRTdHJpcGVPcmRlckNvbnRyb2xsZXIoKSB7XG4gICAgY29uc3QgY2FyZEVsZW1lbnQgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgnY2FyZC1lbGVtZW50JylcbiAgICBpZiAoIWNhcmRFbGVtZW50KSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdDYXJkIGVsZW1lbnQgbm90IGZvdW5kJylcbiAgICAgIHJldHVybiBudWxsXG4gICAgfVxuXG4gICAgY29uc3QgY29udHJvbGxlciA9IHRoaXMuYXBwbGljYXRpb24uZ2V0Q29udHJvbGxlckZvckVsZW1lbnRBbmRJZGVudGlmaWVyKFxuICAgICAgY2FyZEVsZW1lbnQsXG4gICAgICAnc3RyaXBlLW9yZGVyJ1xuICAgIClcblxuICAgIGlmICghY29udHJvbGxlcikge1xuICAgICAgY29uc29sZS5lcnJvcignU3RyaXBlIG9yZGVyIGNvbnRyb2xsZXIgbm90IGZvdW5kIG9uIGNhcmQgZWxlbWVudCcpXG4gICAgICByZXR1cm4gbnVsbFxuICAgIH1cblxuICAgIGNvbnNvbGUubG9nKCdGb3VuZCBzdHJpcGUtb3JkZXIgY29udHJvbGxlcjonLCBjb250cm9sbGVyKVxuICAgIHJldHVybiBjb250cm9sbGVyXG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIG9yZGVyIHN1Ym1pdCBidXR0b24gY2xpY2tcbiAgICogUm91dGVzIHRvIGFwcHJvcHJpYXRlIHBheW1lbnQgZmxvdyBiYXNlZCBvbiBwYXltZW50IHR5cGVcbiAgICogQHBhcmFtIHtFdmVudH0gZXZlbnQgLSBUaGUgY2xpY2sgZXZlbnRcbiAgICovXG4gIGFzeW5jIGhhbmRsZVN1Ym1pdChldmVudCkge1xuICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KClcblxuICAgIGNvbnNvbGUubG9nKCdPcmRlciBzdWJtaXQgYnV0dG9uIGNsaWNrZWQnLCB7XG4gICAgICBidXR0b25JZDogdGhpcy5lbGVtZW50LmlkLFxuICAgICAgcGF5bWVudFR5cGU6IHRoaXMucGF5bWVudFR5cGVWYWx1ZSxcbiAgICAgIHRpbWVzdGFtcDogbmV3IERhdGUoKS50b0lTT1N0cmluZygpXG4gICAgfSlcblxuICAgIHRoaXMuc2hvd0xvYWRpbmcoKVxuXG4gICAgdHJ5IHtcbiAgICAgIC8vIFJvdXRlIHRvIGFwcHJvcHJpYXRlIHBheW1lbnQgZmxvd1xuICAgICAgaWYgKHRoaXMucGF5bWVudFR5cGVWYWx1ZSA9PT0gJ3dhbGxldCcpIHtcbiAgICAgICAgYXdhaXQgdGhpcy5oYW5kbGVTdHJpcGVDaGVja291dCgpXG4gICAgICB9IGVsc2Uge1xuICAgICAgICBhd2FpdCB0aGlzLmhhbmRsZVBheW1lbnRJbnRlbnQoKVxuICAgICAgfVxuICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdPcmRlciBzdWJtaXNzaW9uIGZhaWxlZCcsIGVycm9yKVxuICAgICAgdGhpcy5zaG93RXJyb3IoZXJyb3IubWVzc2FnZSB8fCAnUGF5bWVudCBwcm9jZXNzaW5nIGZhaWxlZCcpXG4gICAgfSBmaW5hbGx5IHtcbiAgICAgIHRoaXMuaGlkZUxvYWRpbmcoKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBIYW5kbGUgU3RyaXBlIENoZWNrb3V0IGZsb3cgKGhvc3RlZCBwYXltZW50IHBhZ2UpXG4gICAqIFVzZWQgZm9yIHdhbGxldCBwYXltZW50cyAoQXBwbGUgUGF5LCBHb29nbGUgUGF5KVxuICAgKi9cbiAgYXN5bmMgaGFuZGxlU3RyaXBlQ2hlY2tvdXQoKSB7XG4gICAgaWYgKCF3aW5kb3cuU3RyaXBlKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IoJ1N0cmlwZS5qcyBub3QgbG9hZGVkJylcbiAgICB9XG5cbiAgICAvLyBHZXQgU3RyaXBlIHB1Ymxpc2hhYmxlIGtleSBmcm9tIFN0aW11bHVzIHZhbHVlXG4gICAgaWYgKCF0aGlzLmhhc1B1Ymxpc2hhYmxlS2V5VmFsdWUgfHwgIXRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSkge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKCdTdHJpcGUgcHVibGlzaGFibGUga2V5IG5vdCBjb25maWd1cmVkJylcbiAgICB9XG5cbiAgICBjb25zdCBzdHJpcGUgPSBTdHJpcGUodGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlKVxuXG4gICAgdGhpcy5zZXRTdGF0dXMoJ0NyZWF0aW5nIGNoZWNrb3V0IHNlc3Npb24uLi4nKVxuXG4gICAgLy8gQ3JlYXRlIENoZWNrb3V0IFNlc3Npb25cbiAgICBjb25zdCByZXNwb25zZSA9IGF3YWl0IGZldGNoKHRoaXMudXJsVmFsdWUsIHtcbiAgICAgIG1ldGhvZDogJ1BPU1QnLFxuICAgICAgaGVhZGVyczoge1xuICAgICAgICAnQ29udGVudC1UeXBlJzogJ2FwcGxpY2F0aW9uL2pzb24nXG4gICAgICB9LFxuICAgICAgYm9keTogSlNPTi5zdHJpbmdpZnkoe1xuICAgICAgICBjYXB0dXJlOiAnYXV0b21hdGljJyAvLyBDYW4gYmUgbWFkZSBjb25maWd1cmFibGVcbiAgICAgIH0pLFxuICAgICAgY3JlZGVudGlhbHM6ICdzYW1lLW9yaWdpbidcbiAgICB9KVxuXG4gICAgaWYgKCFyZXNwb25zZS5vaykge1xuICAgICAgY29uc3QgZXJyb3JEYXRhID0gYXdhaXQgcmVzcG9uc2UuanNvbigpLmNhdGNoKCgpID0+ICh7fSkpXG4gICAgICB0aHJvdyBuZXcgRXJyb3IoZXJyb3JEYXRhLmVycm9yIHx8ICdGYWlsZWQgdG8gY3JlYXRlIGNoZWNrb3V0IHNlc3Npb24nKVxuICAgIH1cblxuICAgIGNvbnN0IGRhdGEgPSBhd2FpdCByZXNwb25zZS5qc29uKClcblxuICAgIGlmICghZGF0YS5pZCkge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKCdJbnZhbGlkIGNoZWNrb3V0IHNlc3Npb24gcmVzcG9uc2UnKVxuICAgIH1cblxuICAgIGNvbnNvbGUubG9nKCdDaGVja291dCBTZXNzaW9uIGNyZWF0ZWQ6JywgZGF0YS5pZClcblxuICAgIC8vIFJlZGlyZWN0IHRvIFN0cmlwZSBDaGVja291dFxuICAgIGNvbnN0IHsgZXJyb3IgfSA9IGF3YWl0IHN0cmlwZS5yZWRpcmVjdFRvQ2hlY2tvdXQoe1xuICAgICAgc2Vzc2lvbklkOiBkYXRhLmlkXG4gICAgfSlcblxuICAgIGlmIChlcnJvcikge1xuICAgICAgdGhyb3cgZXJyb3JcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIFBheW1lbnQgSW50ZW50IGZsb3cgKGNhcmQgZWxlbWVudClcbiAgICogVXNlZCBmb3IgY2FyZCBwYXltZW50c1xuICAgKi9cbiAgYXN5bmMgaGFuZGxlUGF5bWVudEludGVudCgpIHtcbiAgICAvLyBHZXQgc3RyaXBlLW9yZGVyIGNvbnRyb2xsZXIgaW5zdGFuY2VcbiAgICBjb25zdCBzdHJpcGVPcmRlckNvbnRyb2xsZXIgPSB0aGlzLmdldFN0cmlwZU9yZGVyQ29udHJvbGxlcigpXG5cbiAgICBpZiAoIXN0cmlwZU9yZGVyQ29udHJvbGxlcikge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKCdTdHJpcGUgcGF5bWVudCBjb250cm9sbGVyIG5vdCBmb3VuZC4gUGxlYXNlIHJlZnJlc2ggdGhlIHBhZ2UuJylcbiAgICB9XG5cbiAgICAvLyBWZXJpZnkgY2FyZCBlbGVtZW50IGFuZCBzdHJpcGUgYXJlIGF2YWlsYWJsZVxuICAgIGlmICghc3RyaXBlT3JkZXJDb250cm9sbGVyLmNhcmQgfHwgIXN0cmlwZU9yZGVyQ29udHJvbGxlci5zdHJpcGUpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ1BheW1lbnQgZm9ybSBub3QgcmVhZHk6Jywge1xuICAgICAgICBoYXNDYXJkOiAhIXN0cmlwZU9yZGVyQ29udHJvbGxlci5jYXJkLFxuICAgICAgICBoYXNTdHJpcGU6ICEhc3RyaXBlT3JkZXJDb250cm9sbGVyLnN0cmlwZVxuICAgICAgfSlcbiAgICAgIHRocm93IG5ldyBFcnJvcignUGF5bWVudCBmb3JtIG5vdCBpbml0aWFsaXplZC4gUGxlYXNlIHJlZnJlc2ggdGhlIHBhZ2UuJylcbiAgICB9XG5cbiAgICBjb25zb2xlLmxvZygnU3RyaXBlIGNvbnRyb2xsZXIgcmVhZHk6Jywge1xuICAgICAgaGFzQ2FyZDogISFzdHJpcGVPcmRlckNvbnRyb2xsZXIuY2FyZCxcbiAgICAgIGhhc1N0cmlwZTogISFzdHJpcGVPcmRlckNvbnRyb2xsZXIuc3RyaXBlXG4gICAgfSlcblxuICAgIGNvbnN0IHBheW1lbnRJbnRlbnRSZXNwb25zZSA9IGF3YWl0IHRoaXMuaGFuZGxlUGF5bWVudCgpXG4gICAgY29uc3QgY2xpZW50U2VjcmV0ID0gcGF5bWVudEludGVudFJlc3BvbnNlLmNsaWVudFNlY3JldFxuXG4gICAgY29uc3QgY29uZmlybVBheW1lbnRSZXNwb25zZSA9IGF3YWl0IHN0cmlwZU9yZGVyQ29udHJvbGxlci5zdHJpcGUuY29uZmlybUNhcmRQYXltZW50KGNsaWVudFNlY3JldCwge1xuICAgICAgcGF5bWVudF9tZXRob2Q6IHtcbiAgICAgICAgY2FyZDogc3RyaXBlT3JkZXJDb250cm9sbGVyLmNhcmRcbiAgICAgIH1cbiAgICB9KTtcblxuICAgIGlmIChjb25maXJtUGF5bWVudFJlc3BvbnNlLmVycm9yKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IoY29uZmlybVBheW1lbnRSZXNwb25zZS5lcnJvci5tZXNzYWdlKVxuICAgIH0gZWxzZSBpZiAoY29uZmlybVBheW1lbnRSZXNwb25zZS5wYXltZW50SW50ZW50ICYmIGNvbmZpcm1QYXltZW50UmVzcG9uc2UucGF5bWVudEludGVudC5zdGF0dXMgPT09ICdzdWNjZWVkZWQnKSB7XG4gICAgICBjb25zb2xlLmxvZygnUGF5bWVudCBzdWNjZWVkZWQnLCBjb25maXJtUGF5bWVudFJlc3BvbnNlLnBheW1lbnRJbnRlbnQpXG4gICAgICAvLyBUT0RPOiBTdWJtaXQgZmluYWwgb3JkZXIgdG8gYmFja2VuZFxuICAgIH0gZWxzZSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IoJ1BheW1lbnQgbm90IGNvbXBsZXRlZCcpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEZldGNoIHBheW1lbnQgaW50ZW50IGNyZWF0aW9uIFVSTCBhbmQgcmV0dXJuIHJlc3BvbnNlXG4gICAqIEByZXR1cm5zIHtQcm9taXNlPE9iamVjdD59IFBheW1lbnQgaW50ZW50IHJlc3BvbnNlIHdpdGggY2xpZW50U2VjcmV0LCBhbW91bnQsIGN1cnJlbmN5XG4gICAqIEB0aHJvd3Mge0Vycm9yfSBJZiBmZXRjaCBmYWlscyBvciByZXNwb25zZSBpcyBub3Qgb2tcbiAgICovXG4gIGFzeW5jIGhhbmRsZVBheW1lbnQoKSB7XG4gICAgaWYgKCF0aGlzLmhhc1VybFZhbHVlKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IoJ1BheW1lbnQgVVJMIGlzIG5vdCBjb25maWd1cmVkJylcbiAgICB9XG5cbiAgICBjb25zb2xlLmxvZygnQ3JlYXRpbmcgcGF5bWVudCBpbnRlbnQgdmlhIFVSTDonLCB0aGlzLnVybFZhbHVlKVxuXG4gICAgY29uc3QgcmVzcG9uc2UgPSBhd2FpdCBmZXRjaCh0aGlzLnVybFZhbHVlLCB7XG4gICAgICBtZXRob2Q6ICdQT1NUJyxcbiAgICAgIGhlYWRlcnM6IHtcbiAgICAgICAgJ0NvbnRlbnQtVHlwZSc6ICdhcHBsaWNhdGlvbi9qc29uJ1xuICAgICAgfSxcbiAgICAgIGNyZWRlbnRpYWxzOiAnc2FtZS1vcmlnaW4nXG4gICAgfSlcblxuICAgIGlmICghcmVzcG9uc2Uub2spIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcihgSFRUUCBlcnJvciEgc3RhdHVzOiAke3Jlc3BvbnNlLnN0YXR1c31gKVxuICAgIH1cblxuICAgIGNvbnN0IHJlc3BvbnNlRGF0YSA9IGF3YWl0IHJlc3BvbnNlLmpzb24oKVxuXG4gICAgaWYgKHJlc3BvbnNlRGF0YS5lcnJvcikge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHJlc3BvbnNlRGF0YS5lcnJvcilcbiAgICB9XG5cbiAgICBpZiAoIXJlc3BvbnNlRGF0YS5zdWNjZXNzIHx8ICFyZXNwb25zZURhdGEuY2xpZW50U2VjcmV0KSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IoJ0ludmFsaWQgcGF5bWVudCBpbnRlbnQgcmVzcG9uc2UnKVxuICAgIH1cblxuICAgIHJldHVybiByZXNwb25zZURhdGFcbiAgfVxuXG4gIC8qKlxuICAgKiBTaG93IGxvYWRpbmcgc3RhdGUgb24gYnV0dG9uXG4gICAqL1xuICBzaG93TG9hZGluZygpIHtcbiAgICB0aGlzLmVsZW1lbnQuZGlzYWJsZWQgPSB0cnVlXG4gICAgdGhpcy5vcmlnaW5hbFRleHQgPSB0aGlzLmVsZW1lbnQudGV4dENvbnRlbnRcbiAgICB0aGlzLmVsZW1lbnQudGV4dENvbnRlbnQgPSAnUHJvY2Vzc2luZy4uLidcbiAgfVxuXG4gIC8qKlxuICAgKiBIaWRlIGxvYWRpbmcgc3RhdGUgb24gYnV0dG9uXG4gICAqL1xuICBoaWRlTG9hZGluZygpIHtcbiAgICB0aGlzLmVsZW1lbnQuZGlzYWJsZWQgPSBmYWxzZVxuICAgIGlmICh0aGlzLm9yaWdpbmFsVGV4dCkge1xuICAgICAgdGhpcy5lbGVtZW50LnRleHRDb250ZW50ID0gdGhpcy5vcmlnaW5hbFRleHRcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogU2V0IHN0YXR1cyBtZXNzYWdlXG4gICAqIEBwYXJhbSB7c3RyaW5nfSBtZXNzYWdlIC0gU3RhdHVzIG1lc3NhZ2UgdG8gZGlzcGxheVxuICAgKi9cbiAgc2V0U3RhdHVzKG1lc3NhZ2UpIHtcbiAgICBpZiAodGhpcy5oYXNTdGF0dXNUYXJnZXQpIHtcbiAgICAgIHRoaXMuc3RhdHVzVGFyZ2V0LnRleHRDb250ZW50ID0gbWVzc2FnZVxuICAgICAgdGhpcy5zdGF0dXNUYXJnZXQuY2xhc3NOYW1lID0gJ210LTIgdGV4dC1jZW50ZXIgdGV4dC1tdXRlZCdcbiAgICB9XG4gICAgY29uc29sZS5sb2coJ1N0YXR1czonLCBtZXNzYWdlKVxuICB9XG5cbiAgLyoqXG4gICAqIFNob3cgZXJyb3IgbWVzc2FnZVxuICAgKiBAcGFyYW0ge3N0cmluZ30gbWVzc2FnZSAtIEVycm9yIG1lc3NhZ2UgdG8gZGlzcGxheVxuICAgKi9cbiAgc2hvd0Vycm9yKG1lc3NhZ2UpIHtcbiAgICBpZiAodGhpcy5oYXNTdGF0dXNUYXJnZXQpIHtcbiAgICAgIHRoaXMuc3RhdHVzVGFyZ2V0LnRleHRDb250ZW50ID0gbWVzc2FnZVxuICAgICAgdGhpcy5zdGF0dXNUYXJnZXQuY2xhc3NOYW1lID0gJ210LTIgdGV4dC1jZW50ZXIgdGV4dC1kYW5nZXInXG4gICAgfSBlbHNlIHtcbiAgICAgIGFsZXJ0KCdFcnJvcjogJyArIG1lc3NhZ2UpXG4gICAgfVxuICB9XG59XG4iLCAiaW1wb3J0IHsgQ29udHJvbGxlciB9IGZyb20gJ0Bob3R3aXJlZC9zdGltdWx1cydcblxuLyoqXG4gKiBTdGltdWx1cyBjb250cm9sbGVyIGZvciBBR0IgKFRlcm1zIGFuZCBDb25kaXRpb25zKSBjaGVja2JveCB2YWxpZGF0aW9uXG4gKlxuICogVGhpcyBjb250cm9sbGVyIGhhbmRsZXMgdGhlIHZhbGlkYXRpb24gb2YgdGhlIEFHQiBjaGVja2JveCBvbiB0aGUgb3JkZXIgcGFnZS5cbiAqIFdoZW4gYmxDb25maXJtQUdCIGlzIGVuYWJsZWQsIGl0IHByZXZlbnRzIG9yZGVyIHN1Ym1pc3Npb24gdW50aWwgdGhlIGNoZWNrYm94IGlzIGNoZWNrZWQuXG4gKlxuICogVXNhZ2UgaW4gdGVtcGxhdGU6XG4gKiA8ZGl2IGRhdGEtY29udHJvbGxlcj1cImFnYi12YWxpZGF0aW9uXCIgZGF0YS1hZ2ItdmFsaWRhdGlvbi1lbmFibGVkLXZhbHVlPVwidHJ1ZVwiPlxuICogICA8aW5wdXQgdHlwZT1cImNoZWNrYm94XCIgZGF0YS1hZ2ItdmFsaWRhdGlvbi10YXJnZXQ9XCJjaGVja2JveFwiIC8+XG4gKiAgIDxidXR0b24gZGF0YS1hZ2ItdmFsaWRhdGlvbi10YXJnZXQ9XCJzdWJtaXRCdXR0b25cIj5PcmRlcjwvYnV0dG9uPlxuICogPC9kaXY+XG4gKi9cbmV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgQ29udHJvbGxlciB7XG4gIHN0YXRpYyB0YXJnZXRzID0gWydjaGVja2JveCcsICdzdWJtaXRCdXR0b24nXVxuICBzdGF0aWMgdmFsdWVzID0ge1xuICAgIGVuYWJsZWQ6IEJvb2xlYW5cbiAgfVxuXG4gIC8qKlxuICAgKiBJbml0aWFsaXplIHRoZSBjb250cm9sbGVyIHdoZW4gY29ubmVjdGVkIHRvIHRoZSBET01cbiAgICovXG4gIGNvbm5lY3QoKSB7XG4gICAgICBkZWJ1Z2dlclxuICAgIGNvbnNvbGUubG9nKCdBR0IgVmFsaWRhdGlvbiBjb250cm9sbGVyIGNvbm5lY3RlZCcsIHtcbiAgICAgIGVuYWJsZWQ6IHRoaXMuZW5hYmxlZFZhbHVlLFxuICAgICAgaGFzQ2hlY2tib3g6IHRoaXMuaGFzQ2hlY2tib3hUYXJnZXQsXG4gICAgICBoYXNTdWJtaXRCdXR0b25zOiB0aGlzLmhhc1N1Ym1pdEJ1dHRvblRhcmdldFxuICAgIH0pXG5cbiAgICAvLyBPbmx5IGFwcGx5IHZhbGlkYXRpb24gaWYgYmxDb25maXJtQUdCIGlzIGVuYWJsZWRcbiAgICBpZiAodGhpcy5lbmFibGVkVmFsdWUpIHtcbiAgICAgIHRoaXMudXBkYXRlQnV0dG9uU3RhdGVzKClcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIGNoZWNrYm94IHN0YXRlIGNoYW5nZXNcbiAgICovXG4gIGNoZWNrYm94Q2hhbmdlZCgpIHtcbiAgICBpZiAodGhpcy5lbmFibGVkVmFsdWUpIHtcbiAgICAgIHRoaXMudXBkYXRlQnV0dG9uU3RhdGVzKClcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogVXBkYXRlIHRoZSBkaXNhYmxlZCBzdGF0ZSBvZiBhbGwgc3VibWl0IGJ1dHRvbnMgYmFzZWQgb24gY2hlY2tib3ggc3RhdGVcbiAgICovXG4gIHVwZGF0ZUJ1dHRvblN0YXRlcygpIHtcbiAgICBpZiAoIXRoaXMuaGFzQ2hlY2tib3hUYXJnZXQgfHwgIXRoaXMuaGFzU3VibWl0QnV0dG9uVGFyZ2V0KSB7XG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICBjb25zdCBpc0NoZWNrZWQgPSB0aGlzLmNoZWNrYm94VGFyZ2V0LmNoZWNrZWRcblxuICAgIC8vIFVwZGF0ZSBhbGwgc3VibWl0IGJ1dHRvbnNcbiAgICB0aGlzLnN1Ym1pdEJ1dHRvblRhcmdldHMuZm9yRWFjaChidXR0b24gPT4ge1xuICAgICAgYnV0dG9uLmRpc2FibGVkID0gIWlzQ2hlY2tlZFxuXG4gICAgICAvLyBBZGQgdmlzdWFsIGZlZWRiYWNrXG4gICAgICBpZiAoaXNDaGVja2VkKSB7XG4gICAgICAgIGJ1dHRvbi5jbGFzc0xpc3QucmVtb3ZlKCdkaXNhYmxlZCcpXG4gICAgICAgIGJ1dHRvbi5yZW1vdmVBdHRyaWJ1dGUoJ3RpdGxlJylcbiAgICAgIH0gZWxzZSB7XG4gICAgICAgIGJ1dHRvbi5jbGFzc0xpc3QuYWRkKCdkaXNhYmxlZCcpXG4gICAgICAgIGJ1dHRvbi5zZXRBdHRyaWJ1dGUoJ3RpdGxlJywgJ1BsZWFzZSBhY2NlcHQgdGhlIHRlcm1zIGFuZCBjb25kaXRpb25zJylcbiAgICAgIH1cbiAgICB9KVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBmb3JtIHN1Ym1pc3Npb24gYXR0ZW1wdHNcbiAgICogQHBhcmFtIHtFdmVudH0gZXZlbnQgLSBUaGUgc3VibWl0IGV2ZW50XG4gICAqL1xuICBoYW5kbGVTdWJtaXQoZXZlbnQpIHtcbiAgICBpZiAoIXRoaXMuZW5hYmxlZFZhbHVlKSB7XG4gICAgICByZXR1cm4gdHJ1ZVxuICAgIH1cblxuICAgIGlmICghdGhpcy5oYXNDaGVja2JveFRhcmdldCB8fCAhdGhpcy5jaGVja2JveFRhcmdldC5jaGVja2VkKSB7XG4gICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpXG4gICAgICBldmVudC5zdG9wUHJvcGFnYXRpb24oKVxuXG4gICAgICAvLyBTaG93IHZpc3VhbCBmZWVkYmFja1xuICAgICAgaWYgKHRoaXMuaGFzQ2hlY2tib3hUYXJnZXQpIHtcbiAgICAgICAgY29uc3QgY2hlY2tib3hXcmFwcGVyID0gdGhpcy5jaGVja2JveFRhcmdldC5jbG9zZXN0KCcuZm9ybS1jaGVjaycpXG4gICAgICAgIGlmIChjaGVja2JveFdyYXBwZXIpIHtcbiAgICAgICAgICBjaGVja2JveFdyYXBwZXIuY2xhc3NMaXN0LmFkZCgnYm9yZGVyJywgJ2JvcmRlci1kYW5nZXInLCAncC0yJywgJ3JvdW5kZWQnKVxuXG4gICAgICAgICAgLy8gUmVtb3ZlIHRoZSBoaWdobGlnaHQgYWZ0ZXIgMyBzZWNvbmRzXG4gICAgICAgICAgc2V0VGltZW91dCgoKSA9PiB7XG4gICAgICAgICAgICBjaGVja2JveFdyYXBwZXIuY2xhc3NMaXN0LnJlbW92ZSgnYm9yZGVyJywgJ2JvcmRlci1kYW5nZXInLCAncC0yJywgJ3JvdW5kZWQnKVxuICAgICAgICAgIH0sIDMwMDApXG4gICAgICAgIH1cbiAgICAgIH1cblxuICAgICAgcmV0dXJuIGZhbHNlXG4gICAgfVxuXG4gICAgcmV0dXJuIHRydWVcbiAgfVxufVxuIiwgIi8qKlxuICogU3RyaXBlIE1vZHVsZSAtIEphdmFTY3JpcHQgRW50cnkgUG9pbnRcbiAqXG4gKiBJbml0aWFsaXplcyBTdGltdWx1cy5qcyBhbmQgcmVnaXN0ZXJzIGFsbCBjb250cm9sbGVyc1xuICovXG5cbmltcG9ydCB7IEFwcGxpY2F0aW9uIH0gZnJvbSBcIkBob3R3aXJlZC9zdGltdWx1c1wiXG5cbi8vIEltcG9ydCBjb250cm9sbGVyc1xuaW1wb3J0IEJ1eU5vd0NvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvYnV5X25vd19jb250cm9sbGVyXCJcbmltcG9ydCBTdHJpcGVPcmRlckNvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvc3RyaXBlX29yZGVyX2NvbnRyb2xsZXJcIlxuaW1wb3J0IE9yZGVyU3VibWl0Q29udHJvbGxlciBmcm9tIFwiLi9jb250cm9sbGVycy9vcmRlcl9zdWJtaXRfY29udHJvbGxlclwiXG5pbXBvcnQgQWdiVmFsaWRhdGlvbkNvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvYWdiX3ZhbGlkYXRpb25fY29udHJvbGxlclwiXG5cbi8vIFN0YXJ0IFN0aW11bHVzIGFwcGxpY2F0aW9uXG53aW5kb3cuU3RpbXVsdXMgPSBBcHBsaWNhdGlvbi5zdGFydCgpXG5cbi8vIFJlZ2lzdGVyIGNvbnRyb2xsZXJzXG5TdGltdWx1cy5yZWdpc3RlcihcImJ1eS1ub3dcIiwgQnV5Tm93Q29udHJvbGxlcilcblN0aW11bHVzLnJlZ2lzdGVyKFwic3RyaXBlLW9yZGVyXCIsIFN0cmlwZU9yZGVyQ29udHJvbGxlcilcblN0aW11bHVzLnJlZ2lzdGVyKFwib3JkZXItc3VibWl0XCIsIE9yZGVyU3VibWl0Q29udHJvbGxlcilcblN0aW11bHVzLnJlZ2lzdGVyKFwiYWdiLXZhbGlkYXRpb25cIiwgQWdiVmFsaWRhdGlvbkNvbnRyb2xsZXIpXG5cbi8vIERlYnVnIG1vZGUgaW4gZGV2ZWxvcG1lbnRcbmlmIChwcm9jZXNzLmVudi5OT0RFX0VOViA9PT0gJ2RldmVsb3BtZW50Jykge1xuICBTdGltdWx1cy5kZWJ1ZyA9IHRydWVcbiAgY29uc29sZS5sb2coJ1N0cmlwZSBNb2R1bGU6IFN0aW11bHVzIGluaXRpYWxpemVkIHdpdGggY29udHJvbGxlcnM6JywgU3RpbXVsdXMucm91dGVyLm1vZHVsZXNCeUlkZW50aWZpZXIpXG59XG5cbmNvbnNvbGUubG9nKCdTdHJpcGUgTW9kdWxlOiBKYXZhU2NyaXB0IGxvYWRlZCBhbmQgcmVhZHknKVxuIl0sCiAgIm1hcHBpbmdzIjogIjs7Ozs7Ozs7O0FBSUEsTUFBTSxnQkFBTixNQUFvQjtBQUFBLElBQ2hCLFlBQVksYUFBYSxXQUFXLGNBQWM7QUFDOUMsV0FBSyxjQUFjO0FBQ25CLFdBQUssWUFBWTtBQUNqQixXQUFLLGVBQWU7QUFDcEIsV0FBSyxvQkFBb0Isb0JBQUksSUFBSTtBQUFBLElBQ3JDO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxZQUFZLGlCQUFpQixLQUFLLFdBQVcsTUFBTSxLQUFLLFlBQVk7QUFBQSxJQUM3RTtBQUFBLElBQ0EsYUFBYTtBQUNULFdBQUssWUFBWSxvQkFBb0IsS0FBSyxXQUFXLE1BQU0sS0FBSyxZQUFZO0FBQUEsSUFDaEY7QUFBQSxJQUNBLGlCQUFpQixTQUFTO0FBQ3RCLFdBQUssa0JBQWtCLElBQUksT0FBTztBQUFBLElBQ3RDO0FBQUEsSUFDQSxvQkFBb0IsU0FBUztBQUN6QixXQUFLLGtCQUFrQixPQUFPLE9BQU87QUFBQSxJQUN6QztBQUFBLElBQ0EsWUFBWSxPQUFPO0FBQ2YsWUFBTSxnQkFBZ0IsWUFBWSxLQUFLO0FBQ3ZDLGlCQUFXLFdBQVcsS0FBSyxVQUFVO0FBQ2pDLFlBQUksY0FBYyw2QkFBNkI7QUFDM0M7QUFBQSxRQUNKLE9BQ0s7QUFDRCxrQkFBUSxZQUFZLGFBQWE7QUFBQSxRQUNyQztBQUFBLE1BQ0o7QUFBQSxJQUNKO0FBQUEsSUFDQSxjQUFjO0FBQ1YsYUFBTyxLQUFLLGtCQUFrQixPQUFPO0FBQUEsSUFDekM7QUFBQSxJQUNBLElBQUksV0FBVztBQUNYLGFBQU8sTUFBTSxLQUFLLEtBQUssaUJBQWlCLEVBQUUsS0FBSyxDQUFDLE1BQU0sVUFBVTtBQUM1RCxjQUFNLFlBQVksS0FBSyxPQUFPLGFBQWEs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Y0FBYztBQUNyQixhQUFLLG1CQUFtQjtBQUFBLE1BQzFCO0FBQUEsSUFDRjtBQUFBO0FBQUE7QUFBQTtBQUFBLElBS0EscUJBQXFCO0FBQ25CLFVBQUksQ0FBQyxLQUFLLHFCQUFxQixDQUFDLEtBQUssdUJBQXVCO0FBQzFEO0FBQUEsTUFDRjtBQUVBLFlBQU0sWUFBWSxLQUFLLGVBQWU7QUFHdEMsV0FBSyxvQkFBb0IsUUFBUSxZQUFVO0FBQ3pDLGVBQU8sV0FBVyxDQUFDO0FBR25CLFlBQUksV0FBVztBQUNiLGlCQUFPLFVBQVUsT0FBTyxVQUFVO0FBQ2xDLGlCQUFPLGdCQUFnQixPQUFPO0FBQUEsUUFDaEMsT0FBTztBQUNMLGlCQUFPLFVBQVUsSUFBSSxVQUFVO0FBQy9CLGlCQUFPLGFBQWEsU0FBUyx3Q0FBd0M7QUFBQSxRQUN2RTtBQUFBLE1BQ0YsQ0FBQztBQUFBLElBQ0g7QUFBQTtBQUFBO0FBQUE7QUFBQTtBQUFBLElBTUEsYUFBYSxPQUFPO0FBQ2xCLFVBQUksQ0FBQyxLQUFLLGNBQWM7QUFDdEIsZUFBTztBQUFBLE1BQ1Q7QUFFQSxVQUFJLENBQUMsS0FBSyxxQkFBcUIsQ0FBQyxLQUFLLGVBQWUsU0FBUztBQUMzRCxjQUFNLGVBQWU7QUFDckIsY0FBTSxnQkFBZ0I7QUFHdEIsWUFBSSxLQUFLLG1CQUFtQjtBQUMxQixnQkFBTSxrQkFBa0IsS0FBSyxlQUFlLFFBQVEsYUFBYTtBQUNqRSxjQUFJLGlCQUFpQjtBQUNuQiw0QkFBZ0IsVUFBVSxJQUFJLFVBQVUsaUJBQWlCLE9BQU8sU0FBUztBQUd6RSx1QkFBVyxNQUFNO0FBQ2YsOEJBQWdCLFVBQVUsT0FBTyxVQUFVLGlCQUFpQixPQUFPLFNBQVM7QUFBQSxZQUM5RSxHQUFHLEdBQUk7QUFBQSxVQUNUO0FBQUEsUUFDRjtBQUVBLGVBQU87QUFBQSxNQUNUO0FBRUEsYUFBTztBQUFBLElBQ1Q7QUFBQSxFQUNGO0FBdkZFLGdCQURLLG1DQUNFLFdBQVUsQ0FBQyxZQUFZLGNBQWM7QUFDNUMsZ0JBRkssbUNBRUUsVUFBUztBQUFBLElBQ2QsU0FBUztBQUFBLEVBQ1g7OztBQ0hGLFNBQU8sV0FBVyxZQUFZLE1BQU07QUFHcEMsV0FBUyxTQUFTLFdBQVcsMEJBQWdCO0FBQzdDLFdBQVMsU0FBUyxnQkFBZ0IsK0JBQXFCO0FBQ3ZELFdBQVMsU0FBUyxnQkFBZ0IsK0JBQXFCO0FBQ3ZELFdBQVMsU0FBUyxrQkFBa0IsaUNBQXVCO0FBRzNELE1BQUksTUFBd0M7QUFDMUMsYUFBUyxRQUFRO0FBQ2pCLFlBQVEsSUFBSSx5REFBeUQsU0FBUyxPQUFPLG1CQUFtQjtBQUFBLEVBQzFHO0FBRUEsVUFBUSxJQUFJLDRDQUE0QzsiLAogICJuYW1lcyI6IFsiZXJyb3IiLCAiZmV0Y2giLCAibWF0Y2giLCAib2xkVmFsdWUiLCAiZXJyb3IiLCAiY29uc3RydWN0b3IiLCAiZWxlbWVudCIsICJlcnJvciIsICJlcnJvciIsICJlcnJvciJdCn0K
