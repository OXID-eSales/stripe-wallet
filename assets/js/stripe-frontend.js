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

  // resources/build/js/debug.js
  var consoleRef = globalThis.console;
  function createDebugLogger(isEnabled) {
    return function debug2(...args) {
      if (!isEnabled()) {
        return;
      }
      consoleRef.log(...args);
    };
  }

  // resources/build/js/controllers/stripe_order_controller.js
  var stripe_order_controller_default = class extends Controller {
    connect() {
      var _a, _b;
      this._debug = createDebugLogger(() => this.stripeDebugValue);
      this._debug("Stripe Order controller connected", {
        hasPublishableKey: !!this.publishableKeyValue,
        publishableKey: this.publishableKeyValue ? this.publishableKeyValue.substring(0, 10) + "..." : "missing"
      });
      const debugInfo = this.element.getAttribute("data-debug-info");
      if (debugInfo) {
        this._debug("Debug info:", debugInfo);
      }
      if (!this.publishableKeyValue) {
        console.error("Stripe publishable key not configured");
        this.showError(((_b = (_a = window.oStripe) == null ? void 0 : _a.i18n) == null ? void 0 : _b.CONFIG_ERROR) || "Stripe configuration error. Please contact support.");
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
      var _a, _b;
      if (typeof Stripe === "undefined") {
        this._debug("Waiting for Stripe.js to load...");
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
        this._debug("Stripe Payment Element initialized successfully");
      } catch (error2) {
        console.error("Failed to initialize Stripe:", error2);
        this.showError(((_b = (_a = window.oStripe) == null ? void 0 : _a.i18n) == null ? void 0 : _b.INIT_FAILED) || "Failed to initialize payment form. Please refresh the page.");
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
    clientSecret: String,
    stripeDebug: { type: Boolean, default: false }
  });
  __publicField(stripe_order_controller_default, "targets", ["errorMessage", "loading"]);

  // resources/build/js/controllers/order_submit_controller.js
  var order_submit_controller_default = class extends Controller {
    /**
     * Called when controller is connected to DOM.
     *
     * Sprint 122: Register a pageshow listener so that when the browser restores
     * this page from the back-forward cache (bfcache) after a Stripe redirect,
     * hideLoading() clears the frozen mid-submit state and dispatches
     * 'oe:stripe:submit-end' — allowing agb-validation to recompute the resting
     * button state as the authoritative last step (see sprint plan §4.2).
     */
    connect() {
      this._debug = createDebugLogger(() => this.stripeDebugValue);
      this._debug("Order Submit controller connected");
      this._debug("Button element:", this.element);
      this._onPageShow = (e) => {
        if (e.persisted)
          this.hideLoading();
      };
      window.addEventListener("pageshow", this._onPageShow);
    }
    /**
     * Called when controller is disconnected from DOM.
     *
     * Sprint 122: Remove the pageshow listener using the exact same bound
     * reference stored in connect() — symmetric, leak-free.
     */
    disconnect() {
      this._debug("Order Submit controller disconnected");
      window.removeEventListener("pageshow", this._onPageShow);
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
      this._debug("Found stripe-order controller:", controller);
      return controller;
    }
    /**
     * Handle order submit button click
     * Routes to appropriate payment flow based on payment type
     * @param {Event} event - The click event
     */
    async handleSubmit(event) {
      var _a, _b;
      event.preventDefault();
      this._debug("Order submit button clicked", {
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
        this.showError(error2.message || ((_b = (_a = window.oStripe) == null ? void 0 : _a.i18n) == null ? void 0 : _b.PAYMENT_FAILED) || "Payment processing failed");
      } finally {
        this.hideLoading();
      }
    }
    /**
     * Handle Stripe Checkout flow (hosted payment page)
     * Used for wallet payments (Apple Pay, Google Pay)
     */
    async handleStripeCheckout() {
      var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j;
      if (!window.Stripe) {
        throw new Error(((_b = (_a = window.oStripe) == null ? void 0 : _a.i18n) == null ? void 0 : _b.JS_NOT_LOADED) || "Stripe.js not loaded");
      }
      if (!this.hasPublishableKeyValue || !this.publishableKeyValue) {
        throw new Error(((_d = (_c = window.oStripe) == null ? void 0 : _c.i18n) == null ? void 0 : _d.KEY_NOT_CONFIGURED) || "Stripe publishable key not configured");
      }
      const stripe = Stripe(this.publishableKeyValue);
      this.setStatus(((_f = (_e = window.oStripe) == null ? void 0 : _e.i18n) == null ? void 0 : _f.CREATING_SESSION) || "Creating checkout session...");
      const response = await fetch(this.appendAgbState(this.buildUrlWithCsrfToken(this.urlValue)), {
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
        const messages = this.collectValidationMessages(errorData);
        if (messages.length) {
          this.renderFieldValidationErrors(errorData.errors);
          this.showValidationBox(messages);
          return;
        }
        throw new Error(errorData.error || ((_h = (_g = window.oStripe) == null ? void 0 : _g.i18n) == null ? void 0 : _h.SESSION_FAILED) || "Failed to create checkout session");
      }
      const data = await response.json();
      if (!data.id) {
        throw new Error(((_j = (_i = window.oStripe) == null ? void 0 : _i.i18n) == null ? void 0 : _j.SESSION_INVALID) || "Invalid checkout session response");
      }
      this._debug("Checkout Session created:", data.id, "URL:", data.url);
      this._debug("Debug info:", data._debug);
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
      var _a, _b, _c, _d, _e, _f;
      const stripeOrderController = this.getStripeOrderController();
      if (!stripeOrderController) {
        throw new Error(((_b = (_a = window.oStripe) == null ? void 0 : _a.i18n) == null ? void 0 : _b.CONTROLLER_NOT_FOUND) || "Stripe payment controller not found. Please refresh the page.");
      }
      if (!stripeOrderController.card || !stripeOrderController.stripe) {
        console.error("Payment form not ready:", {
          hasCard: !!stripeOrderController.card,
          hasStripe: !!stripeOrderController.stripe
        });
        throw new Error(((_d = (_c = window.oStripe) == null ? void 0 : _c.i18n) == null ? void 0 : _d.FORM_NOT_READY) || "Payment form not initialized. Please refresh the page.");
      }
      this._debug("Stripe controller ready:", {
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
        this._debug("Payment succeeded", confirmPaymentResponse.paymentIntent);
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
      var _a, _b, _c, _d;
      if (!this.hasUrlValue) {
        throw new Error(((_b = (_a = window.oStripe) == null ? void 0 : _a.i18n) == null ? void 0 : _b.URL_NOT_CONFIGURED) || "Payment URL is not configured");
      }
      this._debug("Creating payment intent via URL:", this.urlValue);
      const response = await fetch(this.appendAgbState(this.buildUrlWithCsrfToken(this.urlValue)), {
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
     * Append stoken (CSRF token) to URL for session challenge validation.
     * OXID includes stoken in forms via oViewConf.getSessionChallengeToken().
     * @param {string} url - The base URL
     * @returns {string} URL with stoken parameter appended
     */
    buildUrlWithCsrfToken(url) {
      var _a;
      const stoken = ((_a = document.querySelector('input[name="stoken"]')) == null ? void 0 : _a.value) || "";
      if (!stoken) {
        this._debug("CSRF token (stoken) not found in form");
        return url;
      }
      const separator = url.includes("?") ? "&" : "?";
      return url + separator + "stoken=" + encodeURIComponent(stoken);
    }
    /**
     * Append the AGB acceptance flag (ord_agb=1) when the customer has ticked
     * the apex Terms-and-Conditions checkbox (#checkAgbTop). The Stripe order
     * fetch posts a JSON body, which OXID's Registry::getRequest() does not
     * parse — placing ord_agb in the query string is the simplest way to make
     * StripeOrderController::createCheckoutSession() see it.
     *
     * @param {string} url
     * @returns {string}
     */
    appendAgbState(url) {
      const checkbox = document.getElementById("checkAgbTop");
      if (!checkbox || !checkbox.checked) {
        return url;
      }
      const separator = url.includes("?") ? "&" : "?";
      return url + separator + "ord_agb=1";
    }
    /**
     * Show loading state on button.
     *
     * Sprint 123: Dispatch 'oe:stripe:submit-start' so that agb-validation
     * can lock the AGB checkbox for the duration of the submit, preventing the
     * customer from unticking it while the request is in flight. The lock is
     * automatically lifted when hideLoading() fires (error path, bfcache restore)
     * via the mirror 'oe:stripe:submit-end' event established in Sprint 122.
     */
    showLoading() {
      var _a, _b;
      this.element.disabled = true;
      this.originalText = this.element.textContent;
      this.element.textContent = ((_b = (_a = window.oStripe) == null ? void 0 : _a.i18n) == null ? void 0 : _b.PROCESSING) || "Processing...";
      document.dispatchEvent(new CustomEvent("oe:stripe:submit-start"));
    }
    /**
     * Hide loading state on button.
     *
     * Sprint 122: After restoring the button's resting DOM state, dispatch
     * 'oe:stripe:submit-end' so that agb-validation (the authority on the
     * resting disabled value) recomputes from the live checkbox. The dispatch
     * is synchronous — agb-validation's recompute runs before hideLoading()
     * returns, ensuring deterministic ordering with no listener-ordering race
     * (see sprint plan §4.2).
     *
     * This fires on three paths: normal error (finally block), bfcache restore
     * (pageshow persisted handler), and any future explicit call — all are safe
     * because hideLoading() and updateButtonStates() are idempotent.
     */
    hideLoading() {
      this.element.disabled = false;
      if (this.originalText) {
        this.element.textContent = this.originalText;
      }
      document.dispatchEvent(new CustomEvent("oe:stripe:submit-end"));
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
      this._debug("Status:", message);
    }
    /**
     * Extract the per-field validation messages from a 422 payload.
     * @param {{errors?: Array<{message?: string}>}} errorData
     * @returns {string[]} per-field messages (empty if none)
     */
    collectValidationMessages(errorData) {
      if (!errorData || !Array.isArray(errorData.errors)) {
        return [];
      }
      return errorData.errors.map((e) => e && e.message).filter(Boolean);
    }
    /**
     * Render the validation messages in the standard OXID red error box
     * (#stripe-validation-errors, placed above the checkout form). The box is
     * dismissed by the "Understand" button OR by pressing any key.
     * Falls back to the status target if the container is absent.
     * @param {string[]} messages
     */
    showValidationBox(messages) {
      const container = document.getElementById("stripe-validation-errors");
      if (!container) {
        this.showError(messages.join(" "));
        return;
      }
      const understandText = container.getAttribute("data-stripe-validation-understand") || "Understand";
      container.innerHTML = "";
      const dismissAll = () => {
        container.innerHTML = "";
        document.removeEventListener("keydown", dismissAll);
      };
      let firstBox = null;
      for (const message of messages) {
        const box = this.buildErrorBox(message, understandText);
        container.appendChild(box);
        firstBox = firstBox || box;
      }
      document.addEventListener("keydown", dismissAll, { once: true });
      if (firstBox) {
        firstBox.scrollIntoView({ behavior: "smooth", block: "center" });
      }
    }
    /**
     * Build a single OXID-style red error box for one message.
     * The "Understand" button removes just this box.
     * @param {string} message
     * @param {string} understandText
     * @returns {HTMLElement}
     */
    buildErrorBox(message, understandText) {
      const box = document.createElement("div");
      box.className = "alert alert-danger d-flex justify-content-between align-items-center px-4";
      const textWrap = document.createElement("div");
      textWrap.className = "ps-2 pe-3 text-start flex-grow-1";
      textWrap.style.textAlign = "left";
      const p = document.createElement("p");
      p.className = "mb-0";
      p.style.textAlign = "left";
      p.textContent = message;
      textWrap.appendChild(p);
      const button = document.createElement("button");
      button.type = "button";
      button.className = "btn btn-outline-light btn-sm text-white border border-white flex-shrink-0";
      button.textContent = understandText;
      button.addEventListener("click", () => box.remove());
      box.appendChild(textWrap);
      box.appendChild(button);
      return box;
    }
    /**
     * Mark the corresponding OXID address inputs invalid + render inline feedback,
     * when such inputs exist in the DOM (editable checkout themes / cl=user step).
     * On the read-only order page this is a no-op; the error box carries the message.
     * @param {Array<{field?: string, message?: string}>} errors
     */
    renderFieldValidationErrors(errors) {
      if (!Array.isArray(errors)) {
        return;
      }
      const NAME_MAP = {
        firstName: "oxuser__oxfname",
        lastName: "oxuser__oxlname",
        additionalInfo: "oxuser__oxaddinfo",
        street: "oxuser__oxstreet",
        houseNumber: "oxuser__oxstreetnr",
        postalCode: "oxuser__oxzip",
        city: "oxuser__oxcity",
        company: "oxuser__oxcompany",
        vatId: "oxuser__oxustid",
        phone: "oxuser__oxfon",
        cellPhone: "oxuser__oxprivfon",
        personalPhone: "oxuser__oxmobfon",
        fax: "oxuser__oxfax"
      };
      for (const err of errors) {
        const name = NAME_MAP[err && err.field];
        const el = name ? document.querySelector('[name="' + name + '"]') : null;
        if (!el) {
          continue;
        }
        el.classList.add("is-invalid");
        const existing = el.parentElement && el.parentElement.querySelector(".invalid-feedback[data-stripe-validation]");
        if (existing)
          existing.remove();
        const feedback = document.createElement("div");
        feedback.className = "invalid-feedback";
        feedback.setAttribute("data-stripe-validation", "true");
        feedback.textContent = err.message || "Invalid value for " + err.field;
        el.insertAdjacentElement("afterend", feedback);
      }
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
    publishableKey: String,
    stripeDebug: { type: Boolean, default: false }
  });

  // resources/build/js/controllers/agb_validation_controller.js
  var agb_validation_controller_default = class extends Controller {
    /**
     * Initialize the controller when connected to the DOM.
     *
     * Sprint 101: The AGB checkbox (#checkAgbTop) lives in the apex theme
     * partial which the Stripe module may not modify (edit boundary §0 of
     * sprint plan). We resolve it by its stable apex DOM ID — the same ID
     * OXID's own agb.js consumes — and attach a change listener.
     *
     * If the checkbox is absent from the DOM (blConfirmAGB is off and apex
     * did not render it), the null guard leaves all buttons enabled, which
     * is the correct outcome for that configuration path.
     */
    connect() {
      this._coreCheckbox = document.getElementById("checkAgbTop");
      if (this._coreCheckbox) {
        this._coreCheckbox.addEventListener("change", () => this.checkboxChanged());
      }
      this._onSubmitEnd = () => {
        this.unlockCheckbox();
        if (this.enabledValue)
          this.updateButtonStates();
      };
      document.addEventListener("oe:stripe:submit-end", this._onSubmitEnd);
      this._onSubmitStart = () => this.lockCheckbox();
      document.addEventListener("oe:stripe:submit-start", this._onSubmitStart);
      if (this.enabledValue && this.priorConsentValue && this._coreCheckbox && !this._coreCheckbox.checked) {
        this._coreCheckbox.checked = true;
      }
      if (this.enabledValue) {
        this.updateButtonStates();
      }
    }
    /**
     * Called when controller is disconnected from DOM.
     *
     * Sprint 122/123: Remove both submit-lifecycle listeners using the exact
     * bound references stored in connect() — symmetric, leak-free across
     * Stimulus reconnects.
     */
    disconnect() {
      document.removeEventListener("oe:stripe:submit-end", this._onSubmitEnd);
      document.removeEventListener("oe:stripe:submit-start", this._onSubmitStart);
    }
    /**
     * Lock the AGB checkbox so it cannot be toggled while a submit is in flight.
     *
     * Sprint 123: UI-integrity fix only — the consent is already captured in
     * ord_agb=1 by appendAgbState() before this lock matters (§0/§4.3 of
     * sprint plan). Null-guarded: if blConfirmAGB is off and the checkbox is
     * absent, this is a safe no-op.
     */
    lockCheckbox() {
      if (this._coreCheckbox) {
        this._coreCheckbox.disabled = true;
      }
    }
    /**
     * Unlock the AGB checkbox after a submit lifecycle ends (error, bfcache
     * restore, or any future path that calls hideLoading()).
     *
     * Sprint 123: Idempotent — safe to call multiple times. Null-guarded for
     * the blConfirmAGB=off case where no checkbox is rendered.
     */
    unlockCheckbox() {
      if (this._coreCheckbox) {
        this._coreCheckbox.disabled = false;
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
     * Update the disabled state of all submit buttons based on checkbox state.
     *
     * Reads from this._coreCheckbox (the apex #checkAgbTop element resolved
     * in connect()). If the checkbox is not present, leaves buttons enabled.
     */
    updateButtonStates() {
      if (!this.hasSubmitButtonTarget) {
        return;
      }
      if (!this._coreCheckbox) {
        return;
      }
      const isChecked = this._coreCheckbox.checked;
      this.submitButtonTargets.forEach((button) => {
        var _a, _b;
        button.disabled = !isChecked;
        if (isChecked) {
          button.classList.remove("disabled");
          button.removeAttribute("title");
        } else {
          button.classList.add("disabled");
          button.setAttribute("title", ((_b = (_a = window.oStripe) == null ? void 0 : _a.i18n) == null ? void 0 : _b.AGB_REQUIRED) || "Please accept the terms and conditions");
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
      if (!this._coreCheckbox || !this._coreCheckbox.checked) {
        event.preventDefault();
        event.stopPropagation();
        if (this._coreCheckbox) {
          const checkboxWrapper = this._coreCheckbox.closest(".form-check");
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
  __publicField(agb_validation_controller_default, "targets", ["submitButton"]);
  __publicField(agb_validation_controller_default, "values", {
    enabled: Boolean,
    priorConsent: Boolean
  });

  // resources/build/js/app.js
  window.Stimulus = Application.start();
  Stimulus.register("stripe-order", stripe_order_controller_default);
  Stimulus.register("order-submit", order_submit_controller_default);
  Stimulus.register("agb-validation", agb_validation_controller_default);
  var stripeDebugEnabled = () => {
    var _a;
    return ((_a = window.oStripe) == null ? void 0 : _a.debug) === true;
  };
  var debug = createDebugLogger(stripeDebugEnabled);
  Stimulus.debug = stripeDebugEnabled();
  debug("Stripe Module: Stimulus initialized with controllers:", Stimulus.router.modulesByIdentifier);
  debug("Stripe Module: JavaScript loaded and ready");
})();
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsiLi4vLi4vbm9kZV9tb2R1bGVzL0Bob3R3aXJlZC9zdGltdWx1cy9kaXN0L3N0aW11bHVzLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9kZWJ1Zy5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvY29udHJvbGxlcnMvc3RyaXBlX29yZGVyX2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL2NvbnRyb2xsZXJzL29yZGVyX3N1Ym1pdF9jb250cm9sbGVyLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9jb250cm9sbGVycy9hZ2JfdmFsaWRhdGlvbl9jb250cm9sbGVyLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9hcHAuanMiXSwKICAic291cmNlc0NvbnRlbnQiOiBbIi8qXG5TdGltdWx1cyAzLjIuMVxuQ29weXJpZ2h0IFx1MDBBOSAyMDIzIEJhc2VjYW1wLCBMTENcbiAqL1xuY2xhc3MgRXZlbnRMaXN0ZW5lciB7XG4gICAgY29uc3RydWN0b3IoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKSB7XG4gICAgICAgIHRoaXMuZXZlbnRUYXJnZXQgPSBldmVudFRhcmdldDtcbiAgICAgICAgdGhpcy5ldmVudE5hbWUgPSBldmVudE5hbWU7XG4gICAgICAgIHRoaXMuZXZlbnRPcHRpb25zID0gZXZlbnRPcHRpb25zO1xuICAgICAgICB0aGlzLnVub3JkZXJlZEJpbmRpbmdzID0gbmV3IFNldCgpO1xuICAgIH1cbiAgICBjb25uZWN0KCkge1xuICAgICAgICB0aGlzLmV2ZW50VGFyZ2V0LmFkZEV2ZW50TGlzdGVuZXIodGhpcy5ldmVudE5hbWUsIHRoaXMsIHRoaXMuZXZlbnRPcHRpb25zKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldC5yZW1vdmVFdmVudExpc3RlbmVyKHRoaXMuZXZlbnROYW1lLCB0aGlzLCB0aGlzLmV2ZW50T3B0aW9ucyk7XG4gICAgfVxuICAgIGJpbmRpbmdDb25uZWN0ZWQoYmluZGluZykge1xuICAgICAgICB0aGlzLnVub3JkZXJlZEJpbmRpbmdzLmFkZChiaW5kaW5nKTtcbiAgICB9XG4gICAgYmluZGluZ0Rpc2Nvbm5lY3RlZChiaW5kaW5nKSB7XG4gICAgICAgIHRoaXMudW5vcmRlcmVkQmluZGluZ3MuZGVsZXRlKGJpbmRpbmcpO1xuICAgIH1cbiAgICBoYW5kbGVFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBleHRlbmRlZEV2ZW50ID0gZXh0ZW5kRXZlbnQoZXZlbnQpO1xuICAgICAgICBmb3IgKGNvbnN0IGJpbmRpbmcgb2YgdGhpcy5iaW5kaW5ncykge1xuICAgICAgICAgICAgaWYgKGV4dGVuZGVkRXZlbnQuaW1tZWRpYXRlUHJvcGFnYXRpb25TdG9wcGVkKSB7XG4gICAgICAgICAgICAgICAgYnJlYWs7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICBiaW5kaW5nLmhhbmRsZUV2ZW50KGV4dGVuZGVkRXZlbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGhhc0JpbmRpbmdzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy51bm9yZGVyZWRCaW5kaW5ncy5zaXplID4gMDtcbiAgICB9XG4gICAgZ2V0IGJpbmRpbmdzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnVub3JkZXJlZEJpbmRpbmdzKS5zb3J0KChsZWZ0LCByaWdodCkgPT4ge1xuICAgICAgICAgICAgY29uc3QgbGVmdEluZGV4ID0gbGVmdC5pbmRleCwgcmlnaHRJbmRleCA9IHJpZ2h0LmluZGV4O1xuICAgICAgICAgICAgcmV0dXJuIGxlZnRJbmRleCA8IHJpZ2h0SW5kZXggPyAtMSA6IGxlZnRJbmRleCA+IHJpZ2h0SW5kZXggPyAxIDogMDtcbiAgICAgICAgfSk7XG4gICAgfVxufVxuZnVuY3Rpb24gZXh0ZW5kRXZlbnQoZXZlbnQpIHtcbiAgICBpZiAoXCJpbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWRcIiBpbiBldmVudCkge1xuICAgICAgICByZXR1cm4gZXZlbnQ7XG4gICAgfVxuICAgIGVsc2Uge1xuICAgICAgICBjb25zdCB7IHN0b3BJbW1lZGlhdGVQcm9wYWdhdGlvbiB9ID0gZXZlbnQ7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKGV2ZW50LCB7XG4gICAgICAgICAgICBpbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWQ6IGZhbHNlLFxuICAgICAgICAgICAgc3RvcEltbWVkaWF0ZVByb3BhZ2F0aW9uKCkge1xuICAgICAgICAgICAgICAgIHRoaXMuaW1tZWRpYXRlUHJvcGFnYXRpb25TdG9wcGVkID0gdHJ1ZTtcbiAgICAgICAgICAgICAgICBzdG9wSW1tZWRpYXRlUHJvcGFnYXRpb24uY2FsbCh0aGlzKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0pO1xuICAgIH1cbn1cblxuY2xhc3MgRGlzcGF0Y2hlciB7XG4gICAgY29uc3RydWN0b3IoYXBwbGljYXRpb24pIHtcbiAgICAgICAgdGhpcy5hcHBsaWNhdGlvbiA9IGFwcGxpY2F0aW9uO1xuICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJNYXBzID0gbmV3IE1hcCgpO1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVycy5mb3JFYWNoKChldmVudExpc3RlbmVyKSA9PiBldmVudExpc3RlbmVyLmNvbm5lY3QoKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJzLmZvckVhY2goKGV2ZW50TGlzdGVuZXIpID0+IGV2ZW50TGlzdGVuZXIuZGlzY29ubmVjdCgpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgZXZlbnRMaXN0ZW5lcnMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMudmFsdWVzKCkpLnJlZHVjZSgobGlzdGVuZXJzLCBtYXApID0+IGxpc3RlbmVycy5jb25jYXQoQXJyYXkuZnJvbShtYXAudmFsdWVzKCkpKSwgW10pO1xuICAgIH1cbiAgICBiaW5kaW5nQ29ubmVjdGVkKGJpbmRpbmcpIHtcbiAgICAgICAgdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJGb3JCaW5kaW5nKGJpbmRpbmcpLmJpbmRpbmdDb25uZWN0ZWQoYmluZGluZyk7XG4gICAgfVxuICAgIGJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZywgY2xlYXJFdmVudExpc3RlbmVycyA9IGZhbHNlKSB7XG4gICAgICAgIHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKS5iaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcpO1xuICAgICAgICBpZiAoY2xlYXJFdmVudExpc3RlbmVycylcbiAgICAgICAgICAgIHRoaXMuY2xlYXJFdmVudExpc3RlbmVyc0ZvckJpbmRpbmcoYmluZGluZyk7XG4gICAgfVxuICAgIGhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwgPSB7fSkge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBgRXJyb3IgJHttZXNzYWdlfWAsIGRldGFpbCk7XG4gICAgfVxuICAgIGNsZWFyRXZlbnRMaXN0ZW5lcnNGb3JCaW5kaW5nKGJpbmRpbmcpIHtcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lciA9IHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKTtcbiAgICAgICAgaWYgKCFldmVudExpc3RlbmVyLmhhc0JpbmRpbmdzKCkpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5yZW1vdmVNYXBwZWRFdmVudExpc3RlbmVyRm9yKGJpbmRpbmcpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlbW92ZU1hcHBlZEV2ZW50TGlzdGVuZXJGb3IoYmluZGluZykge1xuICAgICAgICBjb25zdCB7IGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyB9ID0gYmluZGluZztcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lck1hcCA9IHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyTWFwRm9yRXZlbnRUYXJnZXQoZXZlbnRUYXJnZXQpO1xuICAgICAgICBjb25zdCBjYWNoZUtleSA9IHRoaXMuY2FjaGVLZXkoZXZlbnROYW1lLCBldmVudE9wdGlvbnMpO1xuICAgICAgICBldmVudExpc3RlbmVyTWFwLmRlbGV0ZShjYWNoZUtleSk7XG4gICAgICAgIGlmIChldmVudExpc3RlbmVyTWFwLnNpemUgPT0gMClcbiAgICAgICAgICAgIHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMuZGVsZXRlKGV2ZW50VGFyZ2V0KTtcbiAgICB9XG4gICAgZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKSB7XG4gICAgICAgIGNvbnN0IHsgZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zIH0gPSBiaW5kaW5nO1xuICAgICAgICByZXR1cm4gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICB9XG4gICAgZmV0Y2hFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICBjb25zdCBldmVudExpc3RlbmVyTWFwID0gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJNYXBGb3JFdmVudFRhcmdldChldmVudFRhcmdldCk7XG4gICAgICAgIGNvbnN0IGNhY2hlS2V5ID0gdGhpcy5jYWNoZUtleShldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgIGxldCBldmVudExpc3RlbmVyID0gZXZlbnRMaXN0ZW5lck1hcC5nZXQoY2FjaGVLZXkpO1xuICAgICAgICBpZiAoIWV2ZW50TGlzdGVuZXIpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIgPSB0aGlzLmNyZWF0ZUV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXJNYXAuc2V0KGNhY2hlS2V5LCBldmVudExpc3RlbmVyKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZXZlbnRMaXN0ZW5lcjtcbiAgICB9XG4gICAgY3JlYXRlRXZlbnRMaXN0ZW5lcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpIHtcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lciA9IG5ldyBFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIuY29ubmVjdCgpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudExpc3RlbmVyO1xuICAgIH1cbiAgICBmZXRjaEV2ZW50TGlzdGVuZXJNYXBGb3JFdmVudFRhcmdldChldmVudFRhcmdldCkge1xuICAgICAgICBsZXQgZXZlbnRMaXN0ZW5lck1hcCA9IHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMuZ2V0KGV2ZW50VGFyZ2V0KTtcbiAgICAgICAgaWYgKCFldmVudExpc3RlbmVyTWFwKSB7XG4gICAgICAgICAgICBldmVudExpc3RlbmVyTWFwID0gbmV3IE1hcCgpO1xuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVyTWFwcy5zZXQoZXZlbnRUYXJnZXQsIGV2ZW50TGlzdGVuZXJNYXApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudExpc3RlbmVyTWFwO1xuICAgIH1cbiAgICBjYWNoZUtleShldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICBjb25zdCBwYXJ0cyA9IFtldmVudE5hbWVdO1xuICAgICAgICBPYmplY3Qua2V5cyhldmVudE9wdGlvbnMpXG4gICAgICAgICAgICAuc29ydCgpXG4gICAgICAgICAgICAuZm9yRWFjaCgoa2V5KSA9PiB7XG4gICAgICAgICAgICBwYXJ0cy5wdXNoKGAke2V2ZW50T3B0aW9uc1trZXldID8gXCJcIiA6IFwiIVwifSR7a2V5fWApO1xuICAgICAgICB9KTtcbiAgICAgICAgcmV0dXJuIHBhcnRzLmpvaW4oXCI6XCIpO1xuICAgIH1cbn1cblxuY29uc3QgZGVmYXVsdEFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzID0ge1xuICAgIHN0b3AoeyBldmVudCwgdmFsdWUgfSkge1xuICAgICAgICBpZiAodmFsdWUpXG4gICAgICAgICAgICBldmVudC5zdG9wUHJvcGFnYXRpb24oKTtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfSxcbiAgICBwcmV2ZW50KHsgZXZlbnQsIHZhbHVlIH0pIHtcbiAgICAgICAgaWYgKHZhbHVlKVxuICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfSxcbiAgICBzZWxmKHsgZXZlbnQsIHZhbHVlLCBlbGVtZW50IH0pIHtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICByZXR1cm4gZWxlbWVudCA9PT0gZXZlbnQudGFyZ2V0O1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICB9LFxufTtcbmNvbnN0IGRlc2NyaXB0b3JQYXR0ZXJuID0gL14oPzooPzooW14uXSs/KVxcKyk/KC4rPykoPzpcXC4oLis/KSk/KD86QCh3aW5kb3d8ZG9jdW1lbnQpKT8tPik/KC4rPykoPzojKFteOl0rPykpKD86OiguKykpPyQvO1xuZnVuY3Rpb24gcGFyc2VBY3Rpb25EZXNjcmlwdG9yU3RyaW5nKGRlc2NyaXB0b3JTdHJpbmcpIHtcbiAgICBjb25zdCBzb3VyY2UgPSBkZXNjcmlwdG9yU3RyaW5nLnRyaW0oKTtcbiAgICBjb25zdCBtYXRjaGVzID0gc291cmNlLm1hdGNoKGRlc2NyaXB0b3JQYXR0ZXJuKSB8fCBbXTtcbiAgICBsZXQgZXZlbnROYW1lID0gbWF0Y2hlc1syXTtcbiAgICBsZXQga2V5RmlsdGVyID0gbWF0Y2hlc1szXTtcbiAgICBpZiAoa2V5RmlsdGVyICYmICFbXCJrZXlkb3duXCIsIFwia2V5dXBcIiwgXCJrZXlwcmVzc1wiXS5pbmNsdWRlcyhldmVudE5hbWUpKSB7XG4gICAgICAgIGV2ZW50TmFtZSArPSBgLiR7a2V5RmlsdGVyfWA7XG4gICAgICAgIGtleUZpbHRlciA9IFwiXCI7XG4gICAgfVxuICAgIHJldHVybiB7XG4gICAgICAgIGV2ZW50VGFyZ2V0OiBwYXJzZUV2ZW50VGFyZ2V0KG1hdGNoZXNbNF0pLFxuICAgICAgICBldmVudE5hbWUsXG4gICAgICAgIGV2ZW50T3B0aW9uczogbWF0Y2hlc1s3XSA/IHBhcnNlRXZlbnRPcHRpb25zKG1hdGNoZXNbN10pIDoge30sXG4gICAgICAgIGlkZW50aWZpZXI6IG1hdGNoZXNbNV0sXG4gICAgICAgIG1ldGhvZE5hbWU6IG1hdGNoZXNbNl0sXG4gICAgICAgIGtleUZpbHRlcjogbWF0Y2hlc1sxXSB8fCBrZXlGaWx0ZXIsXG4gICAgfTtcbn1cbmZ1bmN0aW9uIHBhcnNlRXZlbnRUYXJnZXQoZXZlbnRUYXJnZXROYW1lKSB7XG4gICAgaWYgKGV2ZW50VGFyZ2V0TmFtZSA9PSBcIndpbmRvd1wiKSB7XG4gICAgICAgIHJldHVybiB3aW5kb3c7XG4gICAgfVxuICAgIGVsc2UgaWYgKGV2ZW50VGFyZ2V0TmFtZSA9PSBcImRvY3VtZW50XCIpIHtcbiAgICAgICAgcmV0dXJuIGRvY3VtZW50O1xuICAgIH1cbn1cbmZ1bmN0aW9uIHBhcnNlRXZlbnRPcHRpb25zKGV2ZW50T3B0aW9ucykge1xuICAgIHJldHVybiBldmVudE9wdGlvbnNcbiAgICAgICAgLnNwbGl0KFwiOlwiKVxuICAgICAgICAucmVkdWNlKChvcHRpb25zLCB0b2tlbikgPT4gT2JqZWN0LmFzc2lnbihvcHRpb25zLCB7IFt0b2tlbi5yZXBsYWNlKC9eIS8sIFwiXCIpXTogIS9eIS8udGVzdCh0b2tlbikgfSksIHt9KTtcbn1cbmZ1bmN0aW9uIHN0cmluZ2lmeUV2ZW50VGFyZ2V0KGV2ZW50VGFyZ2V0KSB7XG4gICAgaWYgKGV2ZW50VGFyZ2V0ID09IHdpbmRvdykge1xuICAgICAgICByZXR1cm4gXCJ3aW5kb3dcIjtcbiAgICB9XG4gICAgZWxzZSBpZiAoZXZlbnRUYXJnZXQgPT0gZG9jdW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIFwiZG9jdW1lbnRcIjtcbiAgICB9XG59XG5cbmZ1bmN0aW9uIGNhbWVsaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLnJlcGxhY2UoLyg/OltfLV0pKFthLXowLTldKS9nLCAoXywgY2hhcikgPT4gY2hhci50b1VwcGVyQ2FzZSgpKTtcbn1cbmZ1bmN0aW9uIG5hbWVzcGFjZUNhbWVsaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIGNhbWVsaXplKHZhbHVlLnJlcGxhY2UoLy0tL2csIFwiLVwiKS5yZXBsYWNlKC9fXy9nLCBcIl9cIikpO1xufVxuZnVuY3Rpb24gY2FwaXRhbGl6ZSh2YWx1ZSkge1xuICAgIHJldHVybiB2YWx1ZS5jaGFyQXQoMCkudG9VcHBlckNhc2UoKSArIHZhbHVlLnNsaWNlKDEpO1xufVxuZnVuY3Rpb24gZGFzaGVyaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLnJlcGxhY2UoLyhbQS1aXSkvZywgKF8sIGNoYXIpID0+IGAtJHtjaGFyLnRvTG93ZXJDYXNlKCl9YCk7XG59XG5mdW5jdGlvbiB0b2tlbml6ZSh2YWx1ZSkge1xuICAgIHJldHVybiB2YWx1ZS5tYXRjaCgvW15cXHNdKy9nKSB8fCBbXTtcbn1cblxuZnVuY3Rpb24gaXNTb21ldGhpbmcob2JqZWN0KSB7XG4gICAgcmV0dXJuIG9iamVjdCAhPT0gbnVsbCAmJiBvYmplY3QgIT09IHVuZGVmaW5lZDtcbn1cbmZ1bmN0aW9uIGhhc1Byb3BlcnR5KG9iamVjdCwgcHJvcGVydHkpIHtcbiAgICByZXR1cm4gT2JqZWN0LnByb3RvdHlwZS5oYXNPd25Qcm9wZXJ0eS5jYWxsKG9iamVjdCwgcHJvcGVydHkpO1xufVxuXG5jb25zdCBhbGxNb2RpZmllcnMgPSBbXCJtZXRhXCIsIFwiY3RybFwiLCBcImFsdFwiLCBcInNoaWZ0XCJdO1xuY2xhc3MgQWN0aW9uIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBpbmRleCwgZGVzY3JpcHRvciwgc2NoZW1hKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuaW5kZXggPSBpbmRleDtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldCA9IGRlc2NyaXB0b3IuZXZlbnRUYXJnZXQgfHwgZWxlbWVudDtcbiAgICAgICAgdGhpcy5ldmVudE5hbWUgPSBkZXNjcmlwdG9yLmV2ZW50TmFtZSB8fCBnZXREZWZhdWx0RXZlbnROYW1lRm9yRWxlbWVudChlbGVtZW50KSB8fCBlcnJvcihcIm1pc3NpbmcgZXZlbnQgbmFtZVwiKTtcbiAgICAgICAgdGhpcy5ldmVudE9wdGlvbnMgPSBkZXNjcmlwdG9yLmV2ZW50T3B0aW9ucyB8fCB7fTtcbiAgICAgICAgdGhpcy5pZGVudGlmaWVyID0gZGVzY3JpcHRvci5pZGVudGlmaWVyIHx8IGVycm9yKFwibWlzc2luZyBpZGVudGlmaWVyXCIpO1xuICAgICAgICB0aGlzLm1ldGhvZE5hbWUgPSBkZXNjcmlwdG9yLm1ldGhvZE5hbWUgfHwgZXJyb3IoXCJtaXNzaW5nIG1ldGhvZCBuYW1lXCIpO1xuICAgICAgICB0aGlzLmtleUZpbHRlciA9IGRlc2NyaXB0b3Iua2V5RmlsdGVyIHx8IFwiXCI7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgIH1cbiAgICBzdGF0aWMgZm9yVG9rZW4odG9rZW4sIHNjaGVtYSkge1xuICAgICAgICByZXR1cm4gbmV3IHRoaXModG9rZW4uZWxlbWVudCwgdG9rZW4uaW5kZXgsIHBhcnNlQWN0aW9uRGVzY3JpcHRvclN0cmluZyh0b2tlbi5jb250ZW50KSwgc2NoZW1hKTtcbiAgICB9XG4gICAgdG9TdHJpbmcoKSB7XG4gICAgICAgIGNvbnN0IGV2ZW50RmlsdGVyID0gdGhpcy5rZXlGaWx0ZXIgPyBgLiR7dGhpcy5rZXlGaWx0ZXJ9YCA6IFwiXCI7XG4gICAgICAgIGNvbnN0IGV2ZW50VGFyZ2V0ID0gdGhpcy5ldmVudFRhcmdldE5hbWUgPyBgQCR7dGhpcy5ldmVudFRhcmdldE5hbWV9YCA6IFwiXCI7XG4gICAgICAgIHJldHVybiBgJHt0aGlzLmV2ZW50TmFtZX0ke2V2ZW50RmlsdGVyfSR7ZXZlbnRUYXJnZXR9LT4ke3RoaXMuaWRlbnRpZmllcn0jJHt0aGlzLm1ldGhvZE5hbWV9YDtcbiAgICB9XG4gICAgc2hvdWxkSWdub3JlS2V5Ym9hcmRFdmVudChldmVudCkge1xuICAgICAgICBpZiAoIXRoaXMua2V5RmlsdGVyKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgY29uc3QgZmlsdGVycyA9IHRoaXMua2V5RmlsdGVyLnNwbGl0KFwiK1wiKTtcbiAgICAgICAgaWYgKHRoaXMua2V5RmlsdGVyRGlzc2F0aXNmaWVkKGV2ZW50LCBmaWx0ZXJzKSkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgY29uc3Qgc3RhbmRhcmRGaWx0ZXIgPSBmaWx0ZXJzLmZpbHRlcigoa2V5KSA9PiAhYWxsTW9kaWZpZXJzLmluY2x1ZGVzKGtleSkpWzBdO1xuICAgICAgICBpZiAoIXN0YW5kYXJkRmlsdGVyKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKCFoYXNQcm9wZXJ0eSh0aGlzLmtleU1hcHBpbmdzLCBzdGFuZGFyZEZpbHRlcikpIHtcbiAgICAgICAgICAgIGVycm9yKGBjb250YWlucyB1bmtub3duIGtleSBmaWx0ZXI6ICR7dGhpcy5rZXlGaWx0ZXJ9YCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHRoaXMua2V5TWFwcGluZ3Nbc3RhbmRhcmRGaWx0ZXJdLnRvTG93ZXJDYXNlKCkgIT09IGV2ZW50LmtleS50b0xvd2VyQ2FzZSgpO1xuICAgIH1cbiAgICBzaG91bGRJZ25vcmVNb3VzZUV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGlmICghdGhpcy5rZXlGaWx0ZXIpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBjb25zdCBmaWx0ZXJzID0gW3RoaXMua2V5RmlsdGVyXTtcbiAgICAgICAgaWYgKHRoaXMua2V5RmlsdGVyRGlzc2F0aXNmaWVkKGV2ZW50LCBmaWx0ZXJzKSkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgIH1cbiAgICBnZXQgcGFyYW1zKCkge1xuICAgICAgICBjb25zdCBwYXJhbXMgPSB7fTtcbiAgICAgICAgY29uc3QgcGF0dGVybiA9IG5ldyBSZWdFeHAoYF5kYXRhLSR7dGhpcy5pZGVudGlmaWVyfS0oLispLXBhcmFtJGAsIFwiaVwiKTtcbiAgICAgICAgZm9yIChjb25zdCB7IG5hbWUsIHZhbHVlIH0gb2YgQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnQuYXR0cmlidXRlcykpIHtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoID0gbmFtZS5tYXRjaChwYXR0ZXJuKTtcbiAgICAgICAgICAgIGNvbnN0IGtleSA9IG1hdGNoICYmIG1hdGNoWzFdO1xuICAgICAgICAgICAgaWYgKGtleSkge1xuICAgICAgICAgICAgICAgIHBhcmFtc1tjYW1lbGl6ZShrZXkpXSA9IHR5cGVjYXN0KHZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFyYW1zO1xuICAgIH1cbiAgICBnZXQgZXZlbnRUYXJnZXROYW1lKCkge1xuICAgICAgICByZXR1cm4gc3RyaW5naWZ5RXZlbnRUYXJnZXQodGhpcy5ldmVudFRhcmdldCk7XG4gICAgfVxuICAgIGdldCBrZXlNYXBwaW5ncygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmtleU1hcHBpbmdzO1xuICAgIH1cbiAgICBrZXlGaWx0ZXJEaXNzYXRpc2ZpZWQoZXZlbnQsIGZpbHRlcnMpIHtcbiAgICAgICAgY29uc3QgW21ldGEsIGN0cmwsIGFsdCwgc2hpZnRdID0gYWxsTW9kaWZpZXJzLm1hcCgobW9kaWZpZXIpID0+IGZpbHRlcnMuaW5jbHVkZXMobW9kaWZpZXIpKTtcbiAgICAgICAgcmV0dXJuIGV2ZW50Lm1ldGFLZXkgIT09IG1ldGEgfHwgZXZlbnQuY3RybEtleSAhPT0gY3RybCB8fCBldmVudC5hbHRLZXkgIT09IGFsdCB8fCBldmVudC5zaGlmdEtleSAhPT0gc2hpZnQ7XG4gICAgfVxufVxuY29uc3QgZGVmYXVsdEV2ZW50TmFtZXMgPSB7XG4gICAgYTogKCkgPT4gXCJjbGlja1wiLFxuICAgIGJ1dHRvbjogKCkgPT4gXCJjbGlja1wiLFxuICAgIGZvcm06ICgpID0+IFwic3VibWl0XCIsXG4gICAgZGV0YWlsczogKCkgPT4gXCJ0b2dnbGVcIixcbiAgICBpbnB1dDogKGUpID0+IChlLmdldEF0dHJpYnV0ZShcInR5cGVcIikgPT0gXCJzdWJtaXRcIiA/IFwiY2xpY2tcIiA6IFwiaW5wdXRcIiksXG4gICAgc2VsZWN0OiAoKSA9PiBcImNoYW5nZVwiLFxuICAgIHRleHRhcmVhOiAoKSA9PiBcImlucHV0XCIsXG59O1xuZnVuY3Rpb24gZ2V0RGVmYXVsdEV2ZW50TmFtZUZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgIGNvbnN0IHRhZ05hbWUgPSBlbGVtZW50LnRhZ05hbWUudG9Mb3dlckNhc2UoKTtcbiAgICBpZiAodGFnTmFtZSBpbiBkZWZhdWx0RXZlbnROYW1lcykge1xuICAgICAgICByZXR1cm4gZGVmYXVsdEV2ZW50TmFtZXNbdGFnTmFtZV0oZWxlbWVudCk7XG4gICAgfVxufVxuZnVuY3Rpb24gZXJyb3IobWVzc2FnZSkge1xuICAgIHRocm93IG5ldyBFcnJvcihtZXNzYWdlKTtcbn1cbmZ1bmN0aW9uIHR5cGVjYXN0KHZhbHVlKSB7XG4gICAgdHJ5IHtcbiAgICAgICAgcmV0dXJuIEpTT04ucGFyc2UodmFsdWUpO1xuICAgIH1cbiAgICBjYXRjaCAob19PKSB7XG4gICAgICAgIHJldHVybiB2YWx1ZTtcbiAgICB9XG59XG5cbmNsYXNzIEJpbmRpbmcge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmFjdGlvbiA9IGFjdGlvbjtcbiAgICB9XG4gICAgZ2V0IGluZGV4KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uaW5kZXg7XG4gICAgfVxuICAgIGdldCBldmVudFRhcmdldCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYWN0aW9uLmV2ZW50VGFyZ2V0O1xuICAgIH1cbiAgICBnZXQgZXZlbnRPcHRpb25zKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uZXZlbnRPcHRpb25zO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBoYW5kbGVFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBhY3Rpb25FdmVudCA9IHRoaXMucHJlcGFyZUFjdGlvbkV2ZW50KGV2ZW50KTtcbiAgICAgICAgaWYgKHRoaXMud2lsbEJlSW52b2tlZEJ5RXZlbnQoZXZlbnQpICYmIHRoaXMuYXBwbHlFdmVudE1vZGlmaWVycyhhY3Rpb25FdmVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuaW52b2tlV2l0aEV2ZW50KGFjdGlvbkV2ZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgZXZlbnROYW1lKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uZXZlbnROYW1lO1xuICAgIH1cbiAgICBnZXQgbWV0aG9kKCkge1xuICAgICAgICBjb25zdCBtZXRob2QgPSB0aGlzLmNvbnRyb2xsZXJbdGhpcy5tZXRob2ROYW1lXTtcbiAgICAgICAgaWYgKHR5cGVvZiBtZXRob2QgPT0gXCJmdW5jdGlvblwiKSB7XG4gICAgICAgICAgICByZXR1cm4gbWV0aG9kO1xuICAgICAgICB9XG4gICAgICAgIHRocm93IG5ldyBFcnJvcihgQWN0aW9uIFwiJHt0aGlzLmFjdGlvbn1cIiByZWZlcmVuY2VzIHVuZGVmaW5lZCBtZXRob2QgXCIke3RoaXMubWV0aG9kTmFtZX1cImApO1xuICAgIH1cbiAgICBhcHBseUV2ZW50TW9kaWZpZXJzKGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdGhpcy5hY3Rpb247XG4gICAgICAgIGNvbnN0IHsgYWN0aW9uRGVzY3JpcHRvckZpbHRlcnMgfSA9IHRoaXMuY29udGV4dC5hcHBsaWNhdGlvbjtcbiAgICAgICAgY29uc3QgeyBjb250cm9sbGVyIH0gPSB0aGlzLmNvbnRleHQ7XG4gICAgICAgIGxldCBwYXNzZXMgPSB0cnVlO1xuICAgICAgICBmb3IgKGNvbnN0IFtuYW1lLCB2YWx1ZV0gb2YgT2JqZWN0LmVudHJpZXModGhpcy5ldmVudE9wdGlvbnMpKSB7XG4gICAgICAgICAgICBpZiAobmFtZSBpbiBhY3Rpb25EZXNjcmlwdG9yRmlsdGVycykge1xuICAgICAgICAgICAgICAgIGNvbnN0IGZpbHRlciA9IGFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzW25hbWVdO1xuICAgICAgICAgICAgICAgIHBhc3NlcyA9IHBhc3NlcyAmJiBmaWx0ZXIoeyBuYW1lLCB2YWx1ZSwgZXZlbnQsIGVsZW1lbnQsIGNvbnRyb2xsZXIgfSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICBjb250aW51ZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFzc2VzO1xuICAgIH1cbiAgICBwcmVwYXJlQWN0aW9uRXZlbnQoZXZlbnQpIHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24oZXZlbnQsIHsgcGFyYW1zOiB0aGlzLmFjdGlvbi5wYXJhbXMgfSk7XG4gICAgfVxuICAgIGludm9rZVdpdGhFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCB7IHRhcmdldCwgY3VycmVudFRhcmdldCB9ID0gZXZlbnQ7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLm1ldGhvZC5jYWxsKHRoaXMuY29udHJvbGxlciwgZXZlbnQpO1xuICAgICAgICAgICAgdGhpcy5jb250ZXh0LmxvZ0RlYnVnQWN0aXZpdHkodGhpcy5tZXRob2ROYW1lLCB7IGV2ZW50LCB0YXJnZXQsIGN1cnJlbnRUYXJnZXQsIGFjdGlvbjogdGhpcy5tZXRob2ROYW1lIH0pO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50LCBpbmRleCB9ID0gdGhpcztcbiAgICAgICAgICAgIGNvbnN0IGRldGFpbCA9IHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCwgaW5kZXgsIGV2ZW50IH07XG4gICAgICAgICAgICB0aGlzLmNvbnRleHQuaGFuZGxlRXJyb3IoZXJyb3IsIGBpbnZva2luZyBhY3Rpb24gXCIke3RoaXMuYWN0aW9ufVwiYCwgZGV0YWlsKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICB3aWxsQmVJbnZva2VkQnlFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBldmVudFRhcmdldCA9IGV2ZW50LnRhcmdldDtcbiAgICAgICAgaWYgKGV2ZW50IGluc3RhbmNlb2YgS2V5Ym9hcmRFdmVudCAmJiB0aGlzLmFjdGlvbi5zaG91bGRJZ25vcmVLZXlib2FyZEV2ZW50KGV2ZW50KSkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmIChldmVudCBpbnN0YW5jZW9mIE1vdXNlRXZlbnQgJiYgdGhpcy5hY3Rpb24uc2hvdWxkSWdub3JlTW91c2VFdmVudChldmVudCkpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBpZiAodGhpcy5lbGVtZW50ID09PSBldmVudFRhcmdldCkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAoZXZlbnRUYXJnZXQgaW5zdGFuY2VvZiBFbGVtZW50ICYmIHRoaXMuZWxlbWVudC5jb250YWlucyhldmVudFRhcmdldCkpIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmNvbnRhaW5zRWxlbWVudChldmVudFRhcmdldCk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5jb250YWluc0VsZW1lbnQodGhpcy5hY3Rpb24uZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuY29udHJvbGxlcjtcbiAgICB9XG4gICAgZ2V0IG1ldGhvZE5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFjdGlvbi5tZXRob2ROYW1lO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbn1cblxuY2xhc3MgRWxlbWVudE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXJJbml0ID0geyBhdHRyaWJ1dGVzOiB0cnVlLCBjaGlsZExpc3Q6IHRydWUsIHN1YnRyZWU6IHRydWUgfTtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5lbGVtZW50cyA9IG5ldyBTZXQoKTtcbiAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyID0gbmV3IE11dGF0aW9uT2JzZXJ2ZXIoKG11dGF0aW9ucykgPT4gdGhpcy5wcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IHRydWU7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIub2JzZXJ2ZSh0aGlzLmVsZW1lbnQsIHRoaXMubXV0YXRpb25PYnNlcnZlckluaXQpO1xuICAgICAgICAgICAgdGhpcy5yZWZyZXNoKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcGF1c2UoY2FsbGJhY2spIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGNhbGxiYWNrKCk7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIub2JzZXJ2ZSh0aGlzLmVsZW1lbnQsIHRoaXMubXV0YXRpb25PYnNlcnZlckluaXQpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIudGFrZVJlY29yZHMoKTtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gbmV3IFNldCh0aGlzLm1hdGNoRWxlbWVudHNJblRyZWUoKSk7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnRzKSkge1xuICAgICAgICAgICAgICAgIGlmICghbWF0Y2hlcy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICAgICAgdGhpcy5yZW1vdmVFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiBBcnJheS5mcm9tKG1hdGNoZXMpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5hZGRFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbnMobXV0YXRpb25zKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGZvciAoY29uc3QgbXV0YXRpb24gb2YgbXV0YXRpb25zKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzTXV0YXRpb24obXV0YXRpb24pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbihtdXRhdGlvbikge1xuICAgICAgICBpZiAobXV0YXRpb24udHlwZSA9PSBcImF0dHJpYnV0ZXNcIikge1xuICAgICAgICAgICAgdGhpcy5wcm9jZXNzQXR0cmlidXRlQ2hhbmdlKG11dGF0aW9uLnRhcmdldCwgbXV0YXRpb24uYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAobXV0YXRpb24udHlwZSA9PSBcImNoaWxkTGlzdFwiKSB7XG4gICAgICAgICAgICB0aGlzLnByb2Nlc3NSZW1vdmVkTm9kZXMobXV0YXRpb24ucmVtb3ZlZE5vZGVzKTtcbiAgICAgICAgICAgIHRoaXMucHJvY2Vzc0FkZGVkTm9kZXMobXV0YXRpb24uYWRkZWROb2Rlcyk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc0F0dHJpYnV0ZUNoYW5nZShlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmICh0aGlzLmVsZW1lbnRzLmhhcyhlbGVtZW50KSkge1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZUNoYW5nZWQgJiYgdGhpcy5tYXRjaEVsZW1lbnQoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgdGhpcy5yZW1vdmVFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgICAgIGVsc2UgaWYgKHRoaXMubWF0Y2hFbGVtZW50KGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLmFkZEVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc1JlbW92ZWROb2Rlcyhub2Rlcykge1xuICAgICAgICBmb3IgKGNvbnN0IG5vZGUgb2YgQXJyYXkuZnJvbShub2RlcykpIHtcbiAgICAgICAgICAgIGNvbnN0IGVsZW1lbnQgPSB0aGlzLmVsZW1lbnRGcm9tTm9kZShub2RlKTtcbiAgICAgICAgICAgIGlmIChlbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzVHJlZShlbGVtZW50LCB0aGlzLnJlbW92ZUVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NBZGRlZE5vZGVzKG5vZGVzKSB7XG4gICAgICAgIGZvciAoY29uc3Qgbm9kZSBvZiBBcnJheS5mcm9tKG5vZGVzKSkge1xuICAgICAgICAgICAgY29uc3QgZWxlbWVudCA9IHRoaXMuZWxlbWVudEZyb21Ob2RlKG5vZGUpO1xuICAgICAgICAgICAgaWYgKGVsZW1lbnQgJiYgdGhpcy5lbGVtZW50SXNBY3RpdmUoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnByb2Nlc3NUcmVlKGVsZW1lbnQsIHRoaXMuYWRkRWxlbWVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVsZWdhdGUubWF0Y2hFbGVtZW50KGVsZW1lbnQpO1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUgPSB0aGlzLmVsZW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVsZWdhdGUubWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKTtcbiAgICB9XG4gICAgcHJvY2Vzc1RyZWUodHJlZSwgcHJvY2Vzc29yKSB7XG4gICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiB0aGlzLm1hdGNoRWxlbWVudHNJblRyZWUodHJlZSkpIHtcbiAgICAgICAgICAgIHByb2Nlc3Nvci5jYWxsKHRoaXMsIGVsZW1lbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRGcm9tTm9kZShub2RlKSB7XG4gICAgICAgIGlmIChub2RlLm5vZGVUeXBlID09IE5vZGUuRUxFTUVOVF9OT0RFKSB7XG4gICAgICAgICAgICByZXR1cm4gbm9kZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50SXNBY3RpdmUoZWxlbWVudCkge1xuICAgICAgICBpZiAoZWxlbWVudC5pc0Nvbm5lY3RlZCAhPSB0aGlzLmVsZW1lbnQuaXNDb25uZWN0ZWQpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnQuY29udGFpbnMoZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgYWRkRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGlmICghdGhpcy5lbGVtZW50cy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIGlmICh0aGlzLmVsZW1lbnRJc0FjdGl2ZShlbGVtZW50KSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZWxlbWVudHMuYWRkKGVsZW1lbnQpO1xuICAgICAgICAgICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkKSB7XG4gICAgICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWQoZWxlbWVudCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHJlbW92ZUVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBpZiAodGhpcy5lbGVtZW50cy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuZWxlbWVudHMuZGVsZXRlKGVsZW1lbnQpO1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZCkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZChlbGVtZW50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbn1cblxuY2xhc3MgQXR0cmlidXRlT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlTmFtZSA9IGF0dHJpYnV0ZU5hbWU7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIgPSBuZXcgRWxlbWVudE9ic2VydmVyKGVsZW1lbnQsIHRoaXMpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudE9ic2VydmVyLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzZWxlY3RvcigpIHtcbiAgICAgICAgcmV0dXJuIGBbJHt0aGlzLmF0dHJpYnV0ZU5hbWV9XWA7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5wYXVzZShjYWxsYmFjayk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBnZXQgc3RhcnRlZCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0ZWQ7XG4gICAgfVxuICAgIG1hdGNoRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIHJldHVybiBlbGVtZW50Lmhhc0F0dHJpYnV0ZSh0aGlzLmF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUpIHtcbiAgICAgICAgY29uc3QgbWF0Y2ggPSB0aGlzLm1hdGNoRWxlbWVudCh0cmVlKSA/IFt0cmVlXSA6IFtdO1xuICAgICAgICBjb25zdCBtYXRjaGVzID0gQXJyYXkuZnJvbSh0cmVlLnF1ZXJ5U2VsZWN0b3JBbGwodGhpcy5zZWxlY3RvcikpO1xuICAgICAgICByZXR1cm4gbWF0Y2guY29uY2F0KG1hdGNoZXMpO1xuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQsIHRoaXMuYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZEF0dHJpYnV0ZShlbGVtZW50LCB0aGlzLmF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZVZhbHVlQ2hhbmdlZCAmJiB0aGlzLmF0dHJpYnV0ZU5hbWUgPT0gYXR0cmlidXRlTmFtZSkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxufVxuXG5mdW5jdGlvbiBhZGQobWFwLCBrZXksIHZhbHVlKSB7XG4gICAgZmV0Y2gobWFwLCBrZXkpLmFkZCh2YWx1ZSk7XG59XG5mdW5jdGlvbiBkZWwobWFwLCBrZXksIHZhbHVlKSB7XG4gICAgZmV0Y2gobWFwLCBrZXkpLmRlbGV0ZSh2YWx1ZSk7XG4gICAgcHJ1bmUobWFwLCBrZXkpO1xufVxuZnVuY3Rpb24gZmV0Y2gobWFwLCBrZXkpIHtcbiAgICBsZXQgdmFsdWVzID0gbWFwLmdldChrZXkpO1xuICAgIGlmICghdmFsdWVzKSB7XG4gICAgICAgIHZhbHVlcyA9IG5ldyBTZXQoKTtcbiAgICAgICAgbWFwLnNldChrZXksIHZhbHVlcyk7XG4gICAgfVxuICAgIHJldHVybiB2YWx1ZXM7XG59XG5mdW5jdGlvbiBwcnVuZShtYXAsIGtleSkge1xuICAgIGNvbnN0IHZhbHVlcyA9IG1hcC5nZXQoa2V5KTtcbiAgICBpZiAodmFsdWVzICE9IG51bGwgJiYgdmFsdWVzLnNpemUgPT0gMCkge1xuICAgICAgICBtYXAuZGVsZXRlKGtleSk7XG4gICAgfVxufVxuXG5jbGFzcyBNdWx0aW1hcCB7XG4gICAgY29uc3RydWN0b3IoKSB7XG4gICAgICAgIHRoaXMudmFsdWVzQnlLZXkgPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIGdldCBrZXlzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnZhbHVlc0J5S2V5LmtleXMoKSk7XG4gICAgfVxuICAgIGdldCB2YWx1ZXMoKSB7XG4gICAgICAgIGNvbnN0IHNldHMgPSBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkudmFsdWVzKCkpO1xuICAgICAgICByZXR1cm4gc2V0cy5yZWR1Y2UoKHZhbHVlcywgc2V0KSA9PiB2YWx1ZXMuY29uY2F0KEFycmF5LmZyb20oc2V0KSksIFtdKTtcbiAgICB9XG4gICAgZ2V0IHNpemUoKSB7XG4gICAgICAgIGNvbnN0IHNldHMgPSBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkudmFsdWVzKCkpO1xuICAgICAgICByZXR1cm4gc2V0cy5yZWR1Y2UoKHNpemUsIHNldCkgPT4gc2l6ZSArIHNldC5zaXplLCAwKTtcbiAgICB9XG4gICAgYWRkKGtleSwgdmFsdWUpIHtcbiAgICAgICAgYWRkKHRoaXMudmFsdWVzQnlLZXksIGtleSwgdmFsdWUpO1xuICAgIH1cbiAgICBkZWxldGUoa2V5LCB2YWx1ZSkge1xuICAgICAgICBkZWwodGhpcy52YWx1ZXNCeUtleSwga2V5LCB2YWx1ZSk7XG4gICAgfVxuICAgIGhhcyhrZXksIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHZhbHVlcyA9IHRoaXMudmFsdWVzQnlLZXkuZ2V0KGtleSk7XG4gICAgICAgIHJldHVybiB2YWx1ZXMgIT0gbnVsbCAmJiB2YWx1ZXMuaGFzKHZhbHVlKTtcbiAgICB9XG4gICAgaGFzS2V5KGtleSkge1xuICAgICAgICByZXR1cm4gdGhpcy52YWx1ZXNCeUtleS5oYXMoa2V5KTtcbiAgICB9XG4gICAgaGFzVmFsdWUodmFsdWUpIHtcbiAgICAgICAgY29uc3Qgc2V0cyA9IEFycmF5LmZyb20odGhpcy52YWx1ZXNCeUtleS52YWx1ZXMoKSk7XG4gICAgICAgIHJldHVybiBzZXRzLnNvbWUoKHNldCkgPT4gc2V0Lmhhcyh2YWx1ZSkpO1xuICAgIH1cbiAgICBnZXRWYWx1ZXNGb3JLZXkoa2V5KSB7XG4gICAgICAgIGNvbnN0IHZhbHVlcyA9IHRoaXMudmFsdWVzQnlLZXkuZ2V0KGtleSk7XG4gICAgICAgIHJldHVybiB2YWx1ZXMgPyBBcnJheS5mcm9tKHZhbHVlcykgOiBbXTtcbiAgICB9XG4gICAgZ2V0S2V5c0ZvclZhbHVlKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkpXG4gICAgICAgICAgICAuZmlsdGVyKChbX2tleSwgdmFsdWVzXSkgPT4gdmFsdWVzLmhhcyh2YWx1ZSkpXG4gICAgICAgICAgICAubWFwKChba2V5LCBfdmFsdWVzXSkgPT4ga2V5KTtcbiAgICB9XG59XG5cbmNsYXNzIEluZGV4ZWRNdWx0aW1hcCBleHRlbmRzIE11bHRpbWFwIHtcbiAgICBjb25zdHJ1Y3RvcigpIHtcbiAgICAgICAgc3VwZXIoKTtcbiAgICAgICAgdGhpcy5rZXlzQnlWYWx1ZSA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgZ2V0IHZhbHVlcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5rZXlzQnlWYWx1ZS5rZXlzKCkpO1xuICAgIH1cbiAgICBhZGQoa2V5LCB2YWx1ZSkge1xuICAgICAgICBzdXBlci5hZGQoa2V5LCB2YWx1ZSk7XG4gICAgICAgIGFkZCh0aGlzLmtleXNCeVZhbHVlLCB2YWx1ZSwga2V5KTtcbiAgICB9XG4gICAgZGVsZXRlKGtleSwgdmFsdWUpIHtcbiAgICAgICAgc3VwZXIuZGVsZXRlKGtleSwgdmFsdWUpO1xuICAgICAgICBkZWwodGhpcy5rZXlzQnlWYWx1ZSwgdmFsdWUsIGtleSk7XG4gICAgfVxuICAgIGhhc1ZhbHVlKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmtleXNCeVZhbHVlLmhhcyh2YWx1ZSk7XG4gICAgfVxuICAgIGdldEtleXNGb3JWYWx1ZSh2YWx1ZSkge1xuICAgICAgICBjb25zdCBzZXQgPSB0aGlzLmtleXNCeVZhbHVlLmdldCh2YWx1ZSk7XG4gICAgICAgIHJldHVybiBzZXQgPyBBcnJheS5mcm9tKHNldCkgOiBbXTtcbiAgICB9XG59XG5cbmNsYXNzIFNlbGVjdG9yT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIHNlbGVjdG9yLCBkZWxlZ2F0ZSwgZGV0YWlscykge1xuICAgICAgICB0aGlzLl9zZWxlY3RvciA9IHNlbGVjdG9yO1xuICAgICAgICB0aGlzLmRldGFpbHMgPSBkZXRhaWxzO1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlciA9IG5ldyBFbGVtZW50T2JzZXJ2ZXIoZWxlbWVudCwgdGhpcyk7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5tYXRjaGVzQnlFbGVtZW50ID0gbmV3IE11bHRpbWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RhcnRlZDtcbiAgICB9XG4gICAgZ2V0IHNlbGVjdG9yKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5fc2VsZWN0b3I7XG4gICAgfVxuICAgIHNldCBzZWxlY3RvcihzZWxlY3Rvcikge1xuICAgICAgICB0aGlzLl9zZWxlY3RvciA9IHNlbGVjdG9yO1xuICAgICAgICB0aGlzLnJlZnJlc2goKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHBhdXNlKGNhbGxiYWNrKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnBhdXNlKGNhbGxiYWNrKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgeyBzZWxlY3RvciB9ID0gdGhpcztcbiAgICAgICAgaWYgKHNlbGVjdG9yKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gZWxlbWVudC5tYXRjaGVzKHNlbGVjdG9yKTtcbiAgICAgICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLnNlbGVjdG9yTWF0Y2hFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIG1hdGNoZXMgJiYgdGhpcy5kZWxlZ2F0ZS5zZWxlY3Rvck1hdGNoRWxlbWVudChlbGVtZW50LCB0aGlzLmRldGFpbHMpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmV0dXJuIG1hdGNoZXM7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2ggPSB0aGlzLm1hdGNoRWxlbWVudCh0cmVlKSA/IFt0cmVlXSA6IFtdO1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlcyA9IEFycmF5LmZyb20odHJlZS5xdWVyeVNlbGVjdG9yQWxsKHNlbGVjdG9yKSkuZmlsdGVyKChtYXRjaCkgPT4gdGhpcy5tYXRjaEVsZW1lbnQobWF0Y2gpKTtcbiAgICAgICAgICAgIHJldHVybiBtYXRjaC5jb25jYXQobWF0Y2hlcyk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gW107XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCB7IHNlbGVjdG9yIH0gPSB0aGlzO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkKGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3JzID0gdGhpcy5tYXRjaGVzQnlFbGVtZW50LmdldEtleXNGb3JWYWx1ZShlbGVtZW50KTtcbiAgICAgICAgZm9yIChjb25zdCBzZWxlY3RvciBvZiBzZWxlY3RvcnMpIHtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIF9hdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlcyA9IHRoaXMubWF0Y2hFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlZEJlZm9yZSA9IHRoaXMubWF0Y2hlc0J5RWxlbWVudC5oYXMoc2VsZWN0b3IsIGVsZW1lbnQpO1xuICAgICAgICAgICAgaWYgKG1hdGNoZXMgJiYgIW1hdGNoZWRCZWZvcmUpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnNlbGVjdG9yTWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIGlmICghbWF0Y2hlcyAmJiBtYXRjaGVkQmVmb3JlKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUuc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yLCB0aGlzLmRldGFpbHMpO1xuICAgICAgICB0aGlzLm1hdGNoZXNCeUVsZW1lbnQuYWRkKHNlbGVjdG9yLCBlbGVtZW50KTtcbiAgICB9XG4gICAgc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpIHtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3RvciwgdGhpcy5kZXRhaWxzKTtcbiAgICAgICAgdGhpcy5tYXRjaGVzQnlFbGVtZW50LmRlbGV0ZShzZWxlY3RvciwgZWxlbWVudCk7XG4gICAgfVxufVxuXG5jbGFzcyBTdHJpbmdNYXBPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXAgPSBuZXcgTWFwKCk7XG4gICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlciA9IG5ldyBNdXRhdGlvbk9ic2VydmVyKChtdXRhdGlvbnMpID0+IHRoaXMucHJvY2Vzc011dGF0aW9ucyhtdXRhdGlvbnMpKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLm9ic2VydmUodGhpcy5lbGVtZW50LCB7IGF0dHJpYnV0ZXM6IHRydWUsIGF0dHJpYnV0ZU9sZFZhbHVlOiB0cnVlIH0pO1xuICAgICAgICAgICAgdGhpcy5yZWZyZXNoKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLnRha2VSZWNvcmRzKCk7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgZm9yIChjb25zdCBhdHRyaWJ1dGVOYW1lIG9mIHRoaXMua25vd25BdHRyaWJ1dGVOYW1lcykge1xuICAgICAgICAgICAgICAgIHRoaXMucmVmcmVzaEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lLCBudWxsKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IG11dGF0aW9uIG9mIG11dGF0aW9ucykge1xuICAgICAgICAgICAgICAgIHRoaXMucHJvY2Vzc011dGF0aW9uKG11dGF0aW9uKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzTXV0YXRpb24obXV0YXRpb24pIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IG11dGF0aW9uLmF0dHJpYnV0ZU5hbWU7XG4gICAgICAgIGlmIChhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgICAgICB0aGlzLnJlZnJlc2hBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSwgbXV0YXRpb24ub2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2hBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3Qga2V5ID0gdGhpcy5kZWxlZ2F0ZS5nZXRTdHJpbmdNYXBLZXlGb3JBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIGlmIChrZXkgIT0gbnVsbCkge1xuICAgICAgICAgICAgaWYgKCF0aGlzLnN0cmluZ01hcC5oYXMoYXR0cmlidXRlTmFtZSkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjb25zdCB2YWx1ZSA9IHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICBpZiAodGhpcy5zdHJpbmdNYXAuZ2V0KGF0dHJpYnV0ZU5hbWUpICE9IHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zdHJpbmdNYXBWYWx1ZUNoYW5nZWQodmFsdWUsIGtleSwgb2xkVmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKHZhbHVlID09IG51bGwpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvbGRWYWx1ZSA9IHRoaXMuc3RyaW5nTWFwLmdldChhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcC5kZWxldGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG9sZFZhbHVlKVxuICAgICAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcC5zZXQoYXR0cmlidXRlTmFtZSwgdmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlBZGRlZCkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlBZGRlZChrZXksIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwga2V5LCBvbGRWYWx1ZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBWYWx1ZUNoYW5nZWQpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwVmFsdWVDaGFuZ2VkKHZhbHVlLCBrZXksIG9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBLZXlSZW1vdmVkKGtleSwgYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwS2V5UmVtb3ZlZCkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlSZW1vdmVkKGtleSwgYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBrbm93bkF0dHJpYnV0ZU5hbWVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbShuZXcgU2V0KHRoaXMuY3VycmVudEF0dHJpYnV0ZU5hbWVzLmNvbmNhdCh0aGlzLnJlY29yZGVkQXR0cmlidXRlTmFtZXMpKSk7XG4gICAgfVxuICAgIGdldCBjdXJyZW50QXR0cmlidXRlTmFtZXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuZWxlbWVudC5hdHRyaWJ1dGVzKS5tYXAoKGF0dHJpYnV0ZSkgPT4gYXR0cmlidXRlLm5hbWUpO1xuICAgIH1cbiAgICBnZXQgcmVjb3JkZWRBdHRyaWJ1dGVOYW1lcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5zdHJpbmdNYXAua2V5cygpKTtcbiAgICB9XG59XG5cbmNsYXNzIFRva2VuTGlzdE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyID0gbmV3IEF0dHJpYnV0ZU9ic2VydmVyKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudG9rZW5zQnlFbGVtZW50ID0gbmV3IE11bHRpbWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLnBhdXNlKGNhbGxiYWNrKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGF0dHJpYnV0ZU5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLmF0dHJpYnV0ZU5hbWU7XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQpIHtcbiAgICAgICAgdGhpcy50b2tlbnNNYXRjaGVkKHRoaXMucmVhZFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCkpO1xuICAgIH1cbiAgICBlbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgW3VubWF0Y2hlZFRva2VucywgbWF0Y2hlZFRva2Vuc10gPSB0aGlzLnJlZnJlc2hUb2tlbnNGb3JFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICB0aGlzLnRva2Vuc1VubWF0Y2hlZCh1bm1hdGNoZWRUb2tlbnMpO1xuICAgICAgICB0aGlzLnRva2Vuc01hdGNoZWQobWF0Y2hlZFRva2Vucyk7XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUoZWxlbWVudCkge1xuICAgICAgICB0aGlzLnRva2Vuc1VubWF0Y2hlZCh0aGlzLnRva2Vuc0J5RWxlbWVudC5nZXRWYWx1ZXNGb3JLZXkoZWxlbWVudCkpO1xuICAgIH1cbiAgICB0b2tlbnNNYXRjaGVkKHRva2Vucykge1xuICAgICAgICB0b2tlbnMuZm9yRWFjaCgodG9rZW4pID0+IHRoaXMudG9rZW5NYXRjaGVkKHRva2VuKSk7XG4gICAgfVxuICAgIHRva2Vuc1VubWF0Y2hlZCh0b2tlbnMpIHtcbiAgICAgICAgdG9rZW5zLmZvckVhY2goKHRva2VuKSA9PiB0aGlzLnRva2VuVW5tYXRjaGVkKHRva2VuKSk7XG4gICAgfVxuICAgIHRva2VuTWF0Y2hlZCh0b2tlbikge1xuICAgICAgICB0aGlzLmRlbGVnYXRlLnRva2VuTWF0Y2hlZCh0b2tlbik7XG4gICAgICAgIHRoaXMudG9rZW5zQnlFbGVtZW50LmFkZCh0b2tlbi5lbGVtZW50LCB0b2tlbik7XG4gICAgfVxuICAgIHRva2VuVW5tYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUudG9rZW5Vbm1hdGNoZWQodG9rZW4pO1xuICAgICAgICB0aGlzLnRva2Vuc0J5RWxlbWVudC5kZWxldGUodG9rZW4uZWxlbWVudCwgdG9rZW4pO1xuICAgIH1cbiAgICByZWZyZXNoVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGNvbnN0IHByZXZpb3VzVG9rZW5zID0gdGhpcy50b2tlbnNCeUVsZW1lbnQuZ2V0VmFsdWVzRm9yS2V5KGVsZW1lbnQpO1xuICAgICAgICBjb25zdCBjdXJyZW50VG9rZW5zID0gdGhpcy5yZWFkVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgY29uc3QgZmlyc3REaWZmZXJpbmdJbmRleCA9IHppcChwcmV2aW91c1Rva2VucywgY3VycmVudFRva2VucykuZmluZEluZGV4KChbcHJldmlvdXNUb2tlbiwgY3VycmVudFRva2VuXSkgPT4gIXRva2Vuc0FyZUVxdWFsKHByZXZpb3VzVG9rZW4sIGN1cnJlbnRUb2tlbikpO1xuICAgICAgICBpZiAoZmlyc3REaWZmZXJpbmdJbmRleCA9PSAtMSkge1xuICAgICAgICAgICAgcmV0dXJuIFtbXSwgW11dO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIFtwcmV2aW91c1Rva2Vucy5zbGljZShmaXJzdERpZmZlcmluZ0luZGV4KSwgY3VycmVudFRva2Vucy5zbGljZShmaXJzdERpZmZlcmluZ0luZGV4KV07XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVhZFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5hdHRyaWJ1dGVOYW1lO1xuICAgICAgICBjb25zdCB0b2tlblN0cmluZyA9IGVsZW1lbnQuZ2V0QXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpIHx8IFwiXCI7XG4gICAgICAgIHJldHVybiBwYXJzZVRva2VuU3RyaW5nKHRva2VuU3RyaW5nLCBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKTtcbiAgICB9XG59XG5mdW5jdGlvbiBwYXJzZVRva2VuU3RyaW5nKHRva2VuU3RyaW5nLCBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgcmV0dXJuIHRva2VuU3RyaW5nXG4gICAgICAgIC50cmltKClcbiAgICAgICAgLnNwbGl0KC9cXHMrLylcbiAgICAgICAgLmZpbHRlcigoY29udGVudCkgPT4gY29udGVudC5sZW5ndGgpXG4gICAgICAgIC5tYXAoKGNvbnRlbnQsIGluZGV4KSA9PiAoeyBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBjb250ZW50LCBpbmRleCB9KSk7XG59XG5mdW5jdGlvbiB6aXAobGVmdCwgcmlnaHQpIHtcbiAgICBjb25zdCBsZW5ndGggPSBNYXRoLm1heChsZWZ0Lmxlbmd0aCwgcmlnaHQubGVuZ3RoKTtcbiAgICByZXR1cm4gQXJyYXkuZnJvbSh7IGxlbmd0aCB9LCAoXywgaW5kZXgpID0+IFtsZWZ0W2luZGV4XSwgcmlnaHRbaW5kZXhdXSk7XG59XG5mdW5jdGlvbiB0b2tlbnNBcmVFcXVhbChsZWZ0LCByaWdodCkge1xuICAgIHJldHVybiBsZWZ0ICYmIHJpZ2h0ICYmIGxlZnQuaW5kZXggPT0gcmlnaHQuaW5kZXggJiYgbGVmdC5jb250ZW50ID09IHJpZ2h0LmNvbnRlbnQ7XG59XG5cbmNsYXNzIFZhbHVlTGlzdE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyID0gbmV3IFRva2VuTGlzdE9ic2VydmVyKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMucGFyc2VSZXN1bHRzQnlUb2tlbiA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudCA9IG5ldyBXZWFrTWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy50b2tlbkxpc3RPYnNlcnZlci5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgYXR0cmlidXRlTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuYXR0cmlidXRlTmFtZTtcbiAgICB9XG4gICAgdG9rZW5NYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdG9rZW47XG4gICAgICAgIGNvbnN0IHsgdmFsdWUgfSA9IHRoaXMuZmV0Y2hQYXJzZVJlc3VsdEZvclRva2VuKHRva2VuKTtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICB0aGlzLmZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkuc2V0KHRva2VuLCB2YWx1ZSk7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHRva2VuVW5tYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdG9rZW47XG4gICAgICAgIGNvbnN0IHsgdmFsdWUgfSA9IHRoaXMuZmV0Y2hQYXJzZVJlc3VsdEZvclRva2VuKHRva2VuKTtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICB0aGlzLmZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkuZGVsZXRlKHRva2VuKTtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZFZhbHVlKGVsZW1lbnQsIHZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBmZXRjaFBhcnNlUmVzdWx0Rm9yVG9rZW4odG9rZW4pIHtcbiAgICAgICAgbGV0IHBhcnNlUmVzdWx0ID0gdGhpcy5wYXJzZVJlc3VsdHNCeVRva2VuLmdldCh0b2tlbik7XG4gICAgICAgIGlmICghcGFyc2VSZXN1bHQpIHtcbiAgICAgICAgICAgIHBhcnNlUmVzdWx0ID0gdGhpcy5wYXJzZVRva2VuKHRva2VuKTtcbiAgICAgICAgICAgIHRoaXMucGFyc2VSZXN1bHRzQnlUb2tlbi5zZXQodG9rZW4sIHBhcnNlUmVzdWx0KTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFyc2VSZXN1bHQ7XG4gICAgfVxuICAgIGZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBsZXQgdmFsdWVzQnlUb2tlbiA9IHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudC5nZXQoZWxlbWVudCk7XG4gICAgICAgIGlmICghdmFsdWVzQnlUb2tlbikge1xuICAgICAgICAgICAgdmFsdWVzQnlUb2tlbiA9IG5ldyBNYXAoKTtcbiAgICAgICAgICAgIHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudC5zZXQoZWxlbWVudCwgdmFsdWVzQnlUb2tlbik7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHZhbHVlc0J5VG9rZW47XG4gICAgfVxuICAgIHBhcnNlVG9rZW4odG9rZW4pIHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gdGhpcy5kZWxlZ2F0ZS5wYXJzZVZhbHVlRm9yVG9rZW4odG9rZW4pO1xuICAgICAgICAgICAgcmV0dXJuIHsgdmFsdWUgfTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHJldHVybiB7IGVycm9yIH07XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmNsYXNzIEJpbmRpbmdPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24gPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIgPSBuZXcgVmFsdWVMaXN0T2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLmFjdGlvbkF0dHJpYnV0ZSwgdGhpcyk7XG4gICAgICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICAgICAgZGVsZXRlIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXI7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBbGxBY3Rpb25zKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IGFjdGlvbkF0dHJpYnV0ZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmFjdGlvbkF0dHJpYnV0ZTtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY2hlbWE7XG4gICAgfVxuICAgIGdldCBiaW5kaW5ncygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5iaW5kaW5nc0J5QWN0aW9uLnZhbHVlcygpKTtcbiAgICB9XG4gICAgY29ubmVjdEFjdGlvbihhY3Rpb24pIHtcbiAgICAgICAgY29uc3QgYmluZGluZyA9IG5ldyBCaW5kaW5nKHRoaXMuY29udGV4dCwgYWN0aW9uKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nc0J5QWN0aW9uLnNldChhY3Rpb24sIGJpbmRpbmcpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlLmJpbmRpbmdDb25uZWN0ZWQoYmluZGluZyk7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RBY3Rpb24oYWN0aW9uKSB7XG4gICAgICAgIGNvbnN0IGJpbmRpbmcgPSB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uZ2V0KGFjdGlvbik7XG4gICAgICAgIGlmIChiaW5kaW5nKSB7XG4gICAgICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uZGVsZXRlKGFjdGlvbik7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZyk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZGlzY29ubmVjdEFsbEFjdGlvbnMoKSB7XG4gICAgICAgIHRoaXMuYmluZGluZ3MuZm9yRWFjaCgoYmluZGluZykgPT4gdGhpcy5kZWxlZ2F0ZS5iaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcsIHRydWUpKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nc0J5QWN0aW9uLmNsZWFyKCk7XG4gICAgfVxuICAgIHBhcnNlVmFsdWVGb3JUb2tlbih0b2tlbikge1xuICAgICAgICBjb25zdCBhY3Rpb24gPSBBY3Rpb24uZm9yVG9rZW4odG9rZW4sIHRoaXMuc2NoZW1hKTtcbiAgICAgICAgaWYgKGFjdGlvbi5pZGVudGlmaWVyID09IHRoaXMuaWRlbnRpZmllcikge1xuICAgICAgICAgICAgcmV0dXJuIGFjdGlvbjtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZFZhbHVlKGVsZW1lbnQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmNvbm5lY3RBY3Rpb24oYWN0aW9uKTtcbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZFZhbHVlKGVsZW1lbnQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBY3Rpb24oYWN0aW9uKTtcbiAgICB9XG59XG5cbmNsYXNzIFZhbHVlT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIHJlY2VpdmVyKSB7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMucmVjZWl2ZXIgPSByZWNlaXZlcjtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXBPYnNlcnZlciA9IG5ldyBTdHJpbmdNYXBPYnNlcnZlcih0aGlzLmVsZW1lbnQsIHRoaXMpO1xuICAgICAgICB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcCA9IHRoaXMuY29udHJvbGxlci52YWx1ZURlc2NyaXB0b3JNYXA7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLnN0cmluZ01hcE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrc0ZvckRlZmF1bHRWYWx1ZXMoKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXBPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmNvbnRyb2xsZXI7XG4gICAgfVxuICAgIGdldFN0cmluZ01hcEtleUZvckF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmIChhdHRyaWJ1dGVOYW1lIGluIHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwKSB7XG4gICAgICAgICAgICByZXR1cm4gdGhpcy52YWx1ZURlc2NyaXB0b3JNYXBbYXR0cmlidXRlTmFtZV0ubmFtZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBLZXlBZGRlZChrZXksIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwW2F0dHJpYnV0ZU5hbWVdO1xuICAgICAgICBpZiAoIXRoaXMuaGFzVmFsdWUoa2V5KSkge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2soa2V5LCBkZXNjcmlwdG9yLndyaXRlcih0aGlzLnJlY2VpdmVyW2tleV0pLCBkZXNjcmlwdG9yLndyaXRlcihkZXNjcmlwdG9yLmRlZmF1bHRWYWx1ZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwgbmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFtuYW1lXTtcbiAgICAgICAgaWYgKHZhbHVlID09PSBudWxsKVxuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICBpZiAob2xkVmFsdWUgPT09IG51bGwpIHtcbiAgICAgICAgICAgIG9sZFZhbHVlID0gZGVzY3JpcHRvci53cml0ZXIoZGVzY3JpcHRvci5kZWZhdWx0VmFsdWUpO1xuICAgICAgICB9XG4gICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKG5hbWUsIHZhbHVlLCBvbGRWYWx1ZSk7XG4gICAgfVxuICAgIHN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JOYW1lTWFwW2tleV07XG4gICAgICAgIGlmICh0aGlzLmhhc1ZhbHVlKGtleSkpIHtcbiAgICAgICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKGtleSwgZGVzY3JpcHRvci53cml0ZXIodGhpcy5yZWNlaXZlcltrZXldKSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2soa2V5LCBkZXNjcmlwdG9yLndyaXRlcihkZXNjcmlwdG9yLmRlZmF1bHRWYWx1ZSksIG9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBpbnZva2VDaGFuZ2VkQ2FsbGJhY2tzRm9yRGVmYXVsdFZhbHVlcygpIHtcbiAgICAgICAgZm9yIChjb25zdCB7IGtleSwgbmFtZSwgZGVmYXVsdFZhbHVlLCB3cml0ZXIgfSBvZiB0aGlzLnZhbHVlRGVzY3JpcHRvcnMpIHtcbiAgICAgICAgICAgIGlmIChkZWZhdWx0VmFsdWUgIT0gdW5kZWZpbmVkICYmICF0aGlzLmNvbnRyb2xsZXIuZGF0YS5oYXMoa2V5KSkge1xuICAgICAgICAgICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKG5hbWUsIHdyaXRlcihkZWZhdWx0VmFsdWUpLCB1bmRlZmluZWQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGludm9rZUNoYW5nZWRDYWxsYmFjayhuYW1lLCByYXdWYWx1ZSwgcmF3T2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3QgY2hhbmdlZE1ldGhvZE5hbWUgPSBgJHtuYW1lfUNoYW5nZWRgO1xuICAgICAgICBjb25zdCBjaGFuZ2VkTWV0aG9kID0gdGhpcy5yZWNlaXZlcltjaGFuZ2VkTWV0aG9kTmFtZV07XG4gICAgICAgIGlmICh0eXBlb2YgY2hhbmdlZE1ldGhvZCA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck5hbWVNYXBbbmFtZV07XG4gICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gZGVzY3JpcHRvci5yZWFkZXIocmF3VmFsdWUpO1xuICAgICAgICAgICAgICAgIGxldCBvbGRWYWx1ZSA9IHJhd09sZFZhbHVlO1xuICAgICAgICAgICAgICAgIGlmIChyYXdPbGRWYWx1ZSkge1xuICAgICAgICAgICAgICAgICAgICBvbGRWYWx1ZSA9IGRlc2NyaXB0b3IucmVhZGVyKHJhd09sZFZhbHVlKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgY2hhbmdlZE1ldGhvZC5jYWxsKHRoaXMucmVjZWl2ZXIsIHZhbHVlLCBvbGRWYWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgICAgICBpZiAoZXJyb3IgaW5zdGFuY2VvZiBUeXBlRXJyb3IpIHtcbiAgICAgICAgICAgICAgICAgICAgZXJyb3IubWVzc2FnZSA9IGBTdGltdWx1cyBWYWx1ZSBcIiR7dGhpcy5jb250ZXh0LmlkZW50aWZpZXJ9LiR7ZGVzY3JpcHRvci5uYW1lfVwiIC0gJHtlcnJvci5tZXNzYWdlfWA7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHRocm93IGVycm9yO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGdldCB2YWx1ZURlc2NyaXB0b3JzKCkge1xuICAgICAgICBjb25zdCB7IHZhbHVlRGVzY3JpcHRvck1hcCB9ID0gdGhpcztcbiAgICAgICAgcmV0dXJuIE9iamVjdC5rZXlzKHZhbHVlRGVzY3JpcHRvck1hcCkubWFwKChrZXkpID0+IHZhbHVlRGVzY3JpcHRvck1hcFtrZXldKTtcbiAgICB9XG4gICAgZ2V0IHZhbHVlRGVzY3JpcHRvck5hbWVNYXAoKSB7XG4gICAgICAgIGNvbnN0IGRlc2NyaXB0b3JzID0ge307XG4gICAgICAgIE9iamVjdC5rZXlzKHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwKS5mb3JFYWNoKChrZXkpID0+IHtcbiAgICAgICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcFtrZXldO1xuICAgICAgICAgICAgZGVzY3JpcHRvcnNbZGVzY3JpcHRvci5uYW1lXSA9IGRlc2NyaXB0b3I7XG4gICAgICAgIH0pO1xuICAgICAgICByZXR1cm4gZGVzY3JpcHRvcnM7XG4gICAgfVxuICAgIGhhc1ZhbHVlKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFthdHRyaWJ1dGVOYW1lXTtcbiAgICAgICAgY29uc3QgaGFzTWV0aG9kTmFtZSA9IGBoYXMke2NhcGl0YWxpemUoZGVzY3JpcHRvci5uYW1lKX1gO1xuICAgICAgICByZXR1cm4gdGhpcy5yZWNlaXZlcltoYXNNZXRob2ROYW1lXTtcbiAgICB9XG59XG5cbmNsYXNzIFRhcmdldE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3Rvcihjb250ZXh0LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudGFyZ2V0c0J5TmFtZSA9IG5ldyBNdWx0aW1hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnRva2VuTGlzdE9ic2VydmVyKSB7XG4gICAgICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyID0gbmV3IFRva2VuTGlzdE9ic2VydmVyKHRoaXMuZWxlbWVudCwgdGhpcy5hdHRyaWJ1dGVOYW1lLCB0aGlzKTtcbiAgICAgICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy50b2tlbkxpc3RPYnNlcnZlcikge1xuICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0QWxsVGFyZ2V0cygpO1xuICAgICAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgICAgICBkZWxldGUgdGhpcy50b2tlbkxpc3RPYnNlcnZlcjtcbiAgICAgICAgfVxuICAgIH1cbiAgICB0b2tlbk1hdGNoZWQoeyBlbGVtZW50LCBjb250ZW50OiBuYW1lIH0pIHtcbiAgICAgICAgaWYgKHRoaXMuc2NvcGUuY29udGFpbnNFbGVtZW50KGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLmNvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgdG9rZW5Vbm1hdGNoZWQoeyBlbGVtZW50LCBjb250ZW50OiBuYW1lIH0pIHtcbiAgICAgICAgdGhpcy5kaXNjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpO1xuICAgIH1cbiAgICBjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpIHtcbiAgICAgICAgdmFyIF9hO1xuICAgICAgICBpZiAoIXRoaXMudGFyZ2V0c0J5TmFtZS5oYXMobmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMudGFyZ2V0c0J5TmFtZS5hZGQobmFtZSwgZWxlbWVudCk7XG4gICAgICAgICAgICAoX2EgPSB0aGlzLnRva2VuTGlzdE9ic2VydmVyKSA9PT0gbnVsbCB8fCBfYSA9PT0gdm9pZCAwID8gdm9pZCAwIDogX2EucGF1c2UoKCkgPT4gdGhpcy5kZWxlZ2F0ZS50YXJnZXRDb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICh0aGlzLnRhcmdldHNCeU5hbWUuaGFzKG5hbWUsIGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLnRhcmdldHNCeU5hbWUuZGVsZXRlKG5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy50b2tlbkxpc3RPYnNlcnZlcikgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUudGFyZ2V0RGlzY29ubmVjdGVkKGVsZW1lbnQsIG5hbWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0QWxsVGFyZ2V0cygpIHtcbiAgICAgICAgZm9yIChjb25zdCBuYW1lIG9mIHRoaXMudGFyZ2V0c0J5TmFtZS5rZXlzKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgdGhpcy50YXJnZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShuYW1lKSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdFRhcmdldChlbGVtZW50LCBuYW1lKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgYXR0cmlidXRlTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIGBkYXRhLSR7dGhpcy5jb250ZXh0LmlkZW50aWZpZXJ9LXRhcmdldGA7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzY29wZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY29wZTtcbiAgICB9XG59XG5cbmZ1bmN0aW9uIHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBhbmNlc3RvcnMgPSBnZXRBbmNlc3RvcnNGb3JDb25zdHJ1Y3Rvcihjb25zdHJ1Y3Rvcik7XG4gICAgcmV0dXJuIEFycmF5LmZyb20oYW5jZXN0b3JzLnJlZHVjZSgodmFsdWVzLCBjb25zdHJ1Y3RvcikgPT4ge1xuICAgICAgICBnZXRPd25TdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgcHJvcGVydHlOYW1lKS5mb3JFYWNoKChuYW1lKSA9PiB2YWx1ZXMuYWRkKG5hbWUpKTtcbiAgICAgICAgcmV0dXJuIHZhbHVlcztcbiAgICB9LCBuZXcgU2V0KCkpKTtcbn1cbmZ1bmN0aW9uIHJlYWRJbmhlcml0YWJsZVN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBhbmNlc3RvcnMgPSBnZXRBbmNlc3RvcnNGb3JDb25zdHJ1Y3Rvcihjb25zdHJ1Y3Rvcik7XG4gICAgcmV0dXJuIGFuY2VzdG9ycy5yZWR1Y2UoKHBhaXJzLCBjb25zdHJ1Y3RvcikgPT4ge1xuICAgICAgICBwYWlycy5wdXNoKC4uLmdldE93blN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpKTtcbiAgICAgICAgcmV0dXJuIHBhaXJzO1xuICAgIH0sIFtdKTtcbn1cbmZ1bmN0aW9uIGdldEFuY2VzdG9yc0ZvckNvbnN0cnVjdG9yKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgYW5jZXN0b3JzID0gW107XG4gICAgd2hpbGUgKGNvbnN0cnVjdG9yKSB7XG4gICAgICAgIGFuY2VzdG9ycy5wdXNoKGNvbnN0cnVjdG9yKTtcbiAgICAgICAgY29uc3RydWN0b3IgPSBPYmplY3QuZ2V0UHJvdG90eXBlT2YoY29uc3RydWN0b3IpO1xuICAgIH1cbiAgICByZXR1cm4gYW5jZXN0b3JzLnJldmVyc2UoKTtcbn1cbmZ1bmN0aW9uIGdldE93blN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBkZWZpbml0aW9uID0gY29uc3RydWN0b3JbcHJvcGVydHlOYW1lXTtcbiAgICByZXR1cm4gQXJyYXkuaXNBcnJheShkZWZpbml0aW9uKSA/IGRlZmluaXRpb24gOiBbXTtcbn1cbmZ1bmN0aW9uIGdldE93blN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBkZWZpbml0aW9uID0gY29uc3RydWN0b3JbcHJvcGVydHlOYW1lXTtcbiAgICByZXR1cm4gZGVmaW5pdGlvbiA/IE9iamVjdC5rZXlzKGRlZmluaXRpb24pLm1hcCgoa2V5KSA9PiBba2V5LCBkZWZpbml0aW9uW2tleV1dKSA6IFtdO1xufVxuXG5jbGFzcyBPdXRsZXRPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5vdXRsZXRzQnlOYW1lID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUgPSBuZXcgTXVsdGltYXAoKTtcbiAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwID0gbmV3IE1hcCgpO1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwID0gbmV3IE1hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0RGVmaW5pdGlvbnMuZm9yRWFjaCgob3V0bGV0TmFtZSkgPT4ge1xuICAgICAgICAgICAgICAgIHRoaXMuc2V0dXBTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICAgICAgICAgIHRoaXMuc2V0dXBBdHRyaWJ1dGVPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgICAgIHRoaXMuZGVwZW5kZW50Q29udGV4dHMuZm9yRWFjaCgoY29udGV4dCkgPT4gY29udGV4dC5yZWZyZXNoKCkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIucmVmcmVzaCgpKTtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIucmVmcmVzaCgpKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBbGxPdXRsZXRzKCk7XG4gICAgICAgICAgICB0aGlzLnN0b3BTZWxlY3Rvck9ic2VydmVycygpO1xuICAgICAgICAgICAgdGhpcy5zdG9wQXR0cmlidXRlT2JzZXJ2ZXJzKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcFNlbGVjdG9yT2JzZXJ2ZXJzKCkge1xuICAgICAgICBpZiAodGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLnNpemUgPiAwKSB7XG4gICAgICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuZm9yRWFjaCgob2JzZXJ2ZXIpID0+IG9ic2VydmVyLnN0b3AoKSk7XG4gICAgICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuY2xlYXIoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wQXR0cmlidXRlT2JzZXJ2ZXJzKCkge1xuICAgICAgICBpZiAodGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5zaXplID4gMCkge1xuICAgICAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIuc3RvcCgpKTtcbiAgICAgICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuY2xlYXIoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzZWxlY3Rvck1hdGNoZWQoZWxlbWVudCwgX3NlbGVjdG9yLCB7IG91dGxldE5hbWUgfSkge1xuICAgICAgICBjb25zdCBvdXRsZXQgPSB0aGlzLmdldE91dGxldChlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKG91dGxldCkge1xuICAgICAgICAgICAgdGhpcy5jb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgX3NlbGVjdG9yLCB7IG91dGxldE5hbWUgfSkge1xuICAgICAgICBjb25zdCBvdXRsZXQgPSB0aGlzLmdldE91dGxldEZyb21NYXAoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIGlmIChvdXRsZXQpIHtcbiAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdE91dGxldChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHNlbGVjdG9yTWF0Y2hFbGVtZW50KGVsZW1lbnQsIHsgb3V0bGV0TmFtZSB9KSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5zZWxlY3RvcihvdXRsZXROYW1lKTtcbiAgICAgICAgY29uc3QgaGFzT3V0bGV0ID0gdGhpcy5oYXNPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IGhhc091dGxldENvbnRyb2xsZXIgPSBlbGVtZW50Lm1hdGNoZXMoYFske3RoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGV9fj0ke291dGxldE5hbWV9XWApO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIHJldHVybiBoYXNPdXRsZXQgJiYgaGFzT3V0bGV0Q29udHJvbGxlciAmJiBlbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkQXR0cmlidXRlKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBjb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICghdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5oYXMob3V0bGV0TmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0c0J5TmFtZS5hZGQob3V0bGV0TmFtZSwgb3V0bGV0KTtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuYWRkKG91dGxldE5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmdldChvdXRsZXROYW1lKSkgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUub3V0bGV0Q29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgaWYgKHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuaGFzKG91dGxldE5hbWUsIGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLm91dGxldHNCeU5hbWUuZGVsZXRlKG91dGxldE5hbWUsIG91dGxldCk7XG4gICAgICAgICAgICB0aGlzLm91dGxldEVsZW1lbnRzQnlOYW1lLmRlbGV0ZShvdXRsZXROYW1lLCBlbGVtZW50KTtcbiAgICAgICAgICAgIChfYSA9IHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcFxuICAgICAgICAgICAgICAgIC5nZXQob3V0bGV0TmFtZSkpID09PSBudWxsIHx8IF9hID09PSB2b2lkIDAgPyB2b2lkIDAgOiBfYS5wYXVzZSgoKSA9PiB0aGlzLmRlbGVnYXRlLm91dGxldERpc2Nvbm5lY3RlZChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0QWxsT3V0bGV0cygpIHtcbiAgICAgICAgZm9yIChjb25zdCBvdXRsZXROYW1lIG9mIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUua2V5cykge1xuICAgICAgICAgICAgZm9yIChjb25zdCBlbGVtZW50IG9mIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuZ2V0VmFsdWVzRm9yS2V5KG91dGxldE5hbWUpKSB7XG4gICAgICAgICAgICAgICAgZm9yIChjb25zdCBvdXRsZXQgb2YgdGhpcy5vdXRsZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShvdXRsZXROYW1lKSkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgdXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IG9ic2VydmVyID0gdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmdldChvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKG9ic2VydmVyKSB7XG4gICAgICAgICAgICBvYnNlcnZlci5zZWxlY3RvciA9IHRoaXMuc2VsZWN0b3Iob3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2V0dXBTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLnNlbGVjdG9yKG91dGxldE5hbWUpO1xuICAgICAgICBjb25zdCBzZWxlY3Rvck9ic2VydmVyID0gbmV3IFNlbGVjdG9yT2JzZXJ2ZXIoZG9jdW1lbnQuYm9keSwgc2VsZWN0b3IsIHRoaXMsIHsgb3V0bGV0TmFtZSB9KTtcbiAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLnNldChvdXRsZXROYW1lLCBzZWxlY3Rvck9ic2VydmVyKTtcbiAgICAgICAgc2VsZWN0b3JPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzZXR1cEF0dHJpYnV0ZU9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuYXR0cmlidXRlTmFtZUZvck91dGxldE5hbWUob3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU9ic2VydmVyID0gbmV3IEF0dHJpYnV0ZU9ic2VydmVyKHRoaXMuc2NvcGUuZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgdGhpcyk7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuc2V0KG91dGxldE5hbWUsIGF0dHJpYnV0ZU9ic2VydmVyKTtcbiAgICAgICAgYXR0cmlidXRlT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgc2VsZWN0b3Iob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5vdXRsZXRzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgYXR0cmlidXRlTmFtZUZvck91dGxldE5hbWUob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5zY2hlbWEub3V0bGV0QXR0cmlidXRlRm9yU2NvcGUodGhpcy5pZGVudGlmaWVyLCBvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgZ2V0T3V0bGV0TmFtZUZyb21PdXRsZXRBdHRyaWJ1dGVOYW1lKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0RGVmaW5pdGlvbnMuZmluZCgob3V0bGV0TmFtZSkgPT4gdGhpcy5hdHRyaWJ1dGVOYW1lRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKSA9PT0gYXR0cmlidXRlTmFtZSk7XG4gICAgfVxuICAgIGdldCBvdXRsZXREZXBlbmRlbmNpZXMoKSB7XG4gICAgICAgIGNvbnN0IGRlcGVuZGVuY2llcyA9IG5ldyBNdWx0aW1hcCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5tb2R1bGVzLmZvckVhY2goKG1vZHVsZSkgPT4ge1xuICAgICAgICAgICAgY29uc3QgY29uc3RydWN0b3IgPSBtb2R1bGUuZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3I7XG4gICAgICAgICAgICBjb25zdCBvdXRsZXRzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwib3V0bGV0c1wiKTtcbiAgICAgICAgICAgIG91dGxldHMuZm9yRWFjaCgob3V0bGV0KSA9PiBkZXBlbmRlbmNpZXMuYWRkKG91dGxldCwgbW9kdWxlLmlkZW50aWZpZXIpKTtcbiAgICAgICAgfSk7XG4gICAgICAgIHJldHVybiBkZXBlbmRlbmNpZXM7XG4gICAgfVxuICAgIGdldCBvdXRsZXREZWZpbml0aW9ucygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0RGVwZW5kZW5jaWVzLmdldEtleXNGb3JWYWx1ZSh0aGlzLmlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBnZXQgZGVwZW5kZW50Q29udHJvbGxlcklkZW50aWZpZXJzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXREZXBlbmRlbmNpZXMuZ2V0VmFsdWVzRm9yS2V5KHRoaXMuaWRlbnRpZmllcik7XG4gICAgfVxuICAgIGdldCBkZXBlbmRlbnRDb250ZXh0cygpIHtcbiAgICAgICAgY29uc3QgaWRlbnRpZmllcnMgPSB0aGlzLmRlcGVuZGVudENvbnRyb2xsZXJJZGVudGlmaWVycztcbiAgICAgICAgcmV0dXJuIHRoaXMucm91dGVyLmNvbnRleHRzLmZpbHRlcigoY29udGV4dCkgPT4gaWRlbnRpZmllcnMuaW5jbHVkZXMoY29udGV4dC5pZGVudGlmaWVyKSk7XG4gICAgfVxuICAgIGhhc091dGxldChlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiAhIXRoaXMuZ2V0T3V0bGV0KGVsZW1lbnQsIG91dGxldE5hbWUpIHx8ICEhdGhpcy5nZXRPdXRsZXRGcm9tTWFwKGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBnZXRPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5nZXRDb250cm9sbGVyRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgfVxuICAgIGdldE91dGxldEZyb21NYXAoZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShvdXRsZXROYW1lKS5maW5kKChvdXRsZXQpID0+IG91dGxldC5lbGVtZW50ID09PSBlbGVtZW50KTtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IGFwcGxpY2F0aW9uKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgcm91dGVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5yb3V0ZXI7XG4gICAgfVxufVxuXG5jbGFzcyBDb250ZXh0IHtcbiAgICBjb25zdHJ1Y3Rvcihtb2R1bGUsIHNjb3BlKSB7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eSA9IChmdW5jdGlvbk5hbWUsIGRldGFpbCA9IHt9KSA9PiB7XG4gICAgICAgICAgICBjb25zdCB7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXIsIGVsZW1lbnQgfSA9IHRoaXM7XG4gICAgICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9LCBkZXRhaWwpO1xuICAgICAgICAgICAgdGhpcy5hcHBsaWNhdGlvbi5sb2dEZWJ1Z0FjdGl2aXR5KHRoaXMuaWRlbnRpZmllciwgZnVuY3Rpb25OYW1lLCBkZXRhaWwpO1xuICAgICAgICB9O1xuICAgICAgICB0aGlzLm1vZHVsZSA9IG1vZHVsZTtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgICAgICB0aGlzLmNvbnRyb2xsZXIgPSBuZXcgbW9kdWxlLmNvbnRyb2xsZXJDb25zdHJ1Y3Rvcih0aGlzKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nT2JzZXJ2ZXIgPSBuZXcgQmluZGluZ09ic2VydmVyKHRoaXMsIHRoaXMuZGlzcGF0Y2hlcik7XG4gICAgICAgIHRoaXMudmFsdWVPYnNlcnZlciA9IG5ldyBWYWx1ZU9ic2VydmVyKHRoaXMsIHRoaXMuY29udHJvbGxlcik7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIgPSBuZXcgVGFyZ2V0T2JzZXJ2ZXIodGhpcywgdGhpcyk7XG4gICAgICAgIHRoaXMub3V0bGV0T2JzZXJ2ZXIgPSBuZXcgT3V0bGV0T2JzZXJ2ZXIodGhpcywgdGhpcyk7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLmNvbnRyb2xsZXIuaW5pdGlhbGl6ZSgpO1xuICAgICAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiaW5pdGlhbGl6ZVwiKTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHRoaXMuaGFuZGxlRXJyb3IoZXJyb3IsIFwiaW5pdGlhbGl6aW5nIGNvbnRyb2xsZXJcIik7XG4gICAgICAgIH1cbiAgICB9XG4gICAgY29ubmVjdCgpIHtcbiAgICAgICAgdGhpcy5iaW5kaW5nT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy52YWx1ZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB0cnkge1xuICAgICAgICAgICAgdGhpcy5jb250cm9sbGVyLmNvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImNvbm5lY3RcIik7XG4gICAgICAgIH1cbiAgICAgICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICB0aGlzLmhhbmRsZUVycm9yKGVycm9yLCBcImNvbm5lY3RpbmcgY29udHJvbGxlclwiKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLm91dGxldE9ic2VydmVyLnJlZnJlc2goKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIHRoaXMuY29udHJvbGxlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJkaXNjb25uZWN0XCIpO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgdGhpcy5oYW5kbGVFcnJvcihlcnJvciwgXCJkaXNjb25uZWN0aW5nIGNvbnRyb2xsZXJcIik7XG4gICAgICAgIH1cbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLnZhbHVlT2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLmJpbmRpbmdPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGdldCBhcHBsaWNhdGlvbigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMubW9kdWxlLmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMubW9kdWxlLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGRpc3BhdGNoZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmRpc3BhdGNoZXI7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgcGFyZW50RWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5wYXJlbnRFbGVtZW50O1xuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsID0ge30pIHtcbiAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50IH0gPSB0aGlzO1xuICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9LCBkZXRhaWwpO1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBgRXJyb3IgJHttZXNzYWdlfWAsIGRldGFpbCk7XG4gICAgfVxuICAgIHRhcmdldENvbm5lY3RlZChlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHRoaXMuaW52b2tlQ29udHJvbGxlck1ldGhvZChgJHtuYW1lfVRhcmdldENvbm5lY3RlZGAsIGVsZW1lbnQpO1xuICAgIH1cbiAgICB0YXJnZXREaXNjb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZX1UYXJnZXREaXNjb25uZWN0ZWRgLCBlbGVtZW50KTtcbiAgICB9XG4gICAgb3V0bGV0Q29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZXNwYWNlQ2FtZWxpemUobmFtZSl9T3V0bGV0Q29ubmVjdGVkYCwgb3V0bGV0LCBlbGVtZW50KTtcbiAgICB9XG4gICAgb3V0bGV0RGlzY29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZXNwYWNlQ2FtZWxpemUobmFtZSl9T3V0bGV0RGlzY29ubmVjdGVkYCwgb3V0bGV0LCBlbGVtZW50KTtcbiAgICB9XG4gICAgaW52b2tlQ29udHJvbGxlck1ldGhvZChtZXRob2ROYW1lLCAuLi5hcmdzKSB7XG4gICAgICAgIGNvbnN0IGNvbnRyb2xsZXIgPSB0aGlzLmNvbnRyb2xsZXI7XG4gICAgICAgIGlmICh0eXBlb2YgY29udHJvbGxlclttZXRob2ROYW1lXSA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgICAgIGNvbnRyb2xsZXJbbWV0aG9kTmFtZV0oLi4uYXJncyk7XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmZ1bmN0aW9uIGJsZXNzKGNvbnN0cnVjdG9yKSB7XG4gICAgcmV0dXJuIHNoYWRvdyhjb25zdHJ1Y3RvciwgZ2V0Qmxlc3NlZFByb3BlcnRpZXMoY29uc3RydWN0b3IpKTtcbn1cbmZ1bmN0aW9uIHNoYWRvdyhjb25zdHJ1Y3RvciwgcHJvcGVydGllcykge1xuICAgIGNvbnN0IHNoYWRvd0NvbnN0cnVjdG9yID0gZXh0ZW5kKGNvbnN0cnVjdG9yKTtcbiAgICBjb25zdCBzaGFkb3dQcm9wZXJ0aWVzID0gZ2V0U2hhZG93UHJvcGVydGllcyhjb25zdHJ1Y3Rvci5wcm90b3R5cGUsIHByb3BlcnRpZXMpO1xuICAgIE9iamVjdC5kZWZpbmVQcm9wZXJ0aWVzKHNoYWRvd0NvbnN0cnVjdG9yLnByb3RvdHlwZSwgc2hhZG93UHJvcGVydGllcyk7XG4gICAgcmV0dXJuIHNoYWRvd0NvbnN0cnVjdG9yO1xufVxuZnVuY3Rpb24gZ2V0Qmxlc3NlZFByb3BlcnRpZXMoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBibGVzc2luZ3MgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJibGVzc2luZ3NcIik7XG4gICAgcmV0dXJuIGJsZXNzaW5ncy5yZWR1Y2UoKGJsZXNzZWRQcm9wZXJ0aWVzLCBibGVzc2luZykgPT4ge1xuICAgICAgICBjb25zdCBwcm9wZXJ0aWVzID0gYmxlc3NpbmcoY29uc3RydWN0b3IpO1xuICAgICAgICBmb3IgKGNvbnN0IGtleSBpbiBwcm9wZXJ0aWVzKSB7XG4gICAgICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gYmxlc3NlZFByb3BlcnRpZXNba2V5XSB8fCB7fTtcbiAgICAgICAgICAgIGJsZXNzZWRQcm9wZXJ0aWVzW2tleV0gPSBPYmplY3QuYXNzaWduKGRlc2NyaXB0b3IsIHByb3BlcnRpZXNba2V5XSk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGJsZXNzZWRQcm9wZXJ0aWVzO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIGdldFNoYWRvd1Byb3BlcnRpZXMocHJvdG90eXBlLCBwcm9wZXJ0aWVzKSB7XG4gICAgcmV0dXJuIGdldE93bktleXMocHJvcGVydGllcykucmVkdWNlKChzaGFkb3dQcm9wZXJ0aWVzLCBrZXkpID0+IHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IGdldFNoYWRvd2VkRGVzY3JpcHRvcihwcm90b3R5cGUsIHByb3BlcnRpZXMsIGtleSk7XG4gICAgICAgIGlmIChkZXNjcmlwdG9yKSB7XG4gICAgICAgICAgICBPYmplY3QuYXNzaWduKHNoYWRvd1Byb3BlcnRpZXMsIHsgW2tleV06IGRlc2NyaXB0b3IgfSk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHNoYWRvd1Byb3BlcnRpZXM7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gZ2V0U2hhZG93ZWREZXNjcmlwdG9yKHByb3RvdHlwZSwgcHJvcGVydGllcywga2V5KSB7XG4gICAgY29uc3Qgc2hhZG93aW5nRGVzY3JpcHRvciA9IE9iamVjdC5nZXRPd25Qcm9wZXJ0eURlc2NyaXB0b3IocHJvdG90eXBlLCBrZXkpO1xuICAgIGNvbnN0IHNoYWRvd2VkQnlWYWx1ZSA9IHNoYWRvd2luZ0Rlc2NyaXB0b3IgJiYgXCJ2YWx1ZVwiIGluIHNoYWRvd2luZ0Rlc2NyaXB0b3I7XG4gICAgaWYgKCFzaGFkb3dlZEJ5VmFsdWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IE9iamVjdC5nZXRPd25Qcm9wZXJ0eURlc2NyaXB0b3IocHJvcGVydGllcywga2V5KS52YWx1ZTtcbiAgICAgICAgaWYgKHNoYWRvd2luZ0Rlc2NyaXB0b3IpIHtcbiAgICAgICAgICAgIGRlc2NyaXB0b3IuZ2V0ID0gc2hhZG93aW5nRGVzY3JpcHRvci5nZXQgfHwgZGVzY3JpcHRvci5nZXQ7XG4gICAgICAgICAgICBkZXNjcmlwdG9yLnNldCA9IHNoYWRvd2luZ0Rlc2NyaXB0b3Iuc2V0IHx8IGRlc2NyaXB0b3Iuc2V0O1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBkZXNjcmlwdG9yO1xuICAgIH1cbn1cbmNvbnN0IGdldE93bktleXMgPSAoKCkgPT4ge1xuICAgIGlmICh0eXBlb2YgT2JqZWN0LmdldE93blByb3BlcnR5U3ltYm9scyA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgcmV0dXJuIChvYmplY3QpID0+IFsuLi5PYmplY3QuZ2V0T3duUHJvcGVydHlOYW1lcyhvYmplY3QpLCAuLi5PYmplY3QuZ2V0T3duUHJvcGVydHlTeW1ib2xzKG9iamVjdCldO1xuICAgIH1cbiAgICBlbHNlIHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5nZXRPd25Qcm9wZXJ0eU5hbWVzO1xuICAgIH1cbn0pKCk7XG5jb25zdCBleHRlbmQgPSAoKCkgPT4ge1xuICAgIGZ1bmN0aW9uIGV4dGVuZFdpdGhSZWZsZWN0KGNvbnN0cnVjdG9yKSB7XG4gICAgICAgIGZ1bmN0aW9uIGV4dGVuZGVkKCkge1xuICAgICAgICAgICAgcmV0dXJuIFJlZmxlY3QuY29uc3RydWN0KGNvbnN0cnVjdG9yLCBhcmd1bWVudHMsIG5ldy50YXJnZXQpO1xuICAgICAgICB9XG4gICAgICAgIGV4dGVuZGVkLnByb3RvdHlwZSA9IE9iamVjdC5jcmVhdGUoY29uc3RydWN0b3IucHJvdG90eXBlLCB7XG4gICAgICAgICAgICBjb25zdHJ1Y3RvcjogeyB2YWx1ZTogZXh0ZW5kZWQgfSxcbiAgICAgICAgfSk7XG4gICAgICAgIFJlZmxlY3Quc2V0UHJvdG90eXBlT2YoZXh0ZW5kZWQsIGNvbnN0cnVjdG9yKTtcbiAgICAgICAgcmV0dXJuIGV4dGVuZGVkO1xuICAgIH1cbiAgICBmdW5jdGlvbiB0ZXN0UmVmbGVjdEV4dGVuc2lvbigpIHtcbiAgICAgICAgY29uc3QgYSA9IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHRoaXMuYS5jYWxsKHRoaXMpO1xuICAgICAgICB9O1xuICAgICAgICBjb25zdCBiID0gZXh0ZW5kV2l0aFJlZmxlY3QoYSk7XG4gICAgICAgIGIucHJvdG90eXBlLmEgPSBmdW5jdGlvbiAoKSB7IH07XG4gICAgICAgIHJldHVybiBuZXcgYigpO1xuICAgIH1cbiAgICB0cnkge1xuICAgICAgICB0ZXN0UmVmbGVjdEV4dGVuc2lvbigpO1xuICAgICAgICByZXR1cm4gZXh0ZW5kV2l0aFJlZmxlY3Q7XG4gICAgfVxuICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICByZXR1cm4gKGNvbnN0cnVjdG9yKSA9PiBjbGFzcyBleHRlbmRlZCBleHRlbmRzIGNvbnN0cnVjdG9yIHtcbiAgICAgICAgfTtcbiAgICB9XG59KSgpO1xuXG5mdW5jdGlvbiBibGVzc0RlZmluaXRpb24oZGVmaW5pdGlvbikge1xuICAgIHJldHVybiB7XG4gICAgICAgIGlkZW50aWZpZXI6IGRlZmluaXRpb24uaWRlbnRpZmllcixcbiAgICAgICAgY29udHJvbGxlckNvbnN0cnVjdG9yOiBibGVzcyhkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvciksXG4gICAgfTtcbn1cblxuY2xhc3MgTW9kdWxlIHtcbiAgICBjb25zdHJ1Y3RvcihhcHBsaWNhdGlvbiwgZGVmaW5pdGlvbikge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uID0gYXBwbGljYXRpb247XG4gICAgICAgIHRoaXMuZGVmaW5pdGlvbiA9IGJsZXNzRGVmaW5pdGlvbihkZWZpbml0aW9uKTtcbiAgICAgICAgdGhpcy5jb250ZXh0c0J5U2NvcGUgPSBuZXcgV2Vha01hcCgpO1xuICAgICAgICB0aGlzLmNvbm5lY3RlZENvbnRleHRzID0gbmV3IFNldCgpO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVmaW5pdGlvbi5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlckNvbnN0cnVjdG9yKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5kZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvcjtcbiAgICB9XG4gICAgZ2V0IGNvbnRleHRzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmNvbm5lY3RlZENvbnRleHRzKTtcbiAgICB9XG4gICAgY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkge1xuICAgICAgICBjb25zdCBjb250ZXh0ID0gdGhpcy5mZXRjaENvbnRleHRGb3JTY29wZShzY29wZSk7XG4gICAgICAgIHRoaXMuY29ubmVjdGVkQ29udGV4dHMuYWRkKGNvbnRleHQpO1xuICAgICAgICBjb250ZXh0LmNvbm5lY3QoKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkge1xuICAgICAgICBjb25zdCBjb250ZXh0ID0gdGhpcy5jb250ZXh0c0J5U2NvcGUuZ2V0KHNjb3BlKTtcbiAgICAgICAgaWYgKGNvbnRleHQpIHtcbiAgICAgICAgICAgIHRoaXMuY29ubmVjdGVkQ29udGV4dHMuZGVsZXRlKGNvbnRleHQpO1xuICAgICAgICAgICAgY29udGV4dC5kaXNjb25uZWN0KCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZmV0Y2hDb250ZXh0Rm9yU2NvcGUoc2NvcGUpIHtcbiAgICAgICAgbGV0IGNvbnRleHQgPSB0aGlzLmNvbnRleHRzQnlTY29wZS5nZXQoc2NvcGUpO1xuICAgICAgICBpZiAoIWNvbnRleHQpIHtcbiAgICAgICAgICAgIGNvbnRleHQgPSBuZXcgQ29udGV4dCh0aGlzLCBzY29wZSk7XG4gICAgICAgICAgICB0aGlzLmNvbnRleHRzQnlTY29wZS5zZXQoc2NvcGUsIGNvbnRleHQpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBjb250ZXh0O1xuICAgIH1cbn1cblxuY2xhc3MgQ2xhc3NNYXAge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgaGFzKG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGF0YS5oYXModGhpcy5nZXREYXRhS2V5KG5hbWUpKTtcbiAgICB9XG4gICAgZ2V0KG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZ2V0QWxsKG5hbWUpWzBdO1xuICAgIH1cbiAgICBnZXRBbGwobmFtZSkge1xuICAgICAgICBjb25zdCB0b2tlblN0cmluZyA9IHRoaXMuZGF0YS5nZXQodGhpcy5nZXREYXRhS2V5KG5hbWUpKSB8fCBcIlwiO1xuICAgICAgICByZXR1cm4gdG9rZW5pemUodG9rZW5TdHJpbmcpO1xuICAgIH1cbiAgICBnZXRBdHRyaWJ1dGVOYW1lKG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGF0YS5nZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KHRoaXMuZ2V0RGF0YUtleShuYW1lKSk7XG4gICAgfVxuICAgIGdldERhdGFLZXkobmFtZSkge1xuICAgICAgICByZXR1cm4gYCR7bmFtZX0tY2xhc3NgO1xuICAgIH1cbiAgICBnZXQgZGF0YSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZGF0YTtcbiAgICB9XG59XG5cbmNsYXNzIERhdGFNYXAge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQoa2V5KSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUobmFtZSk7XG4gICAgfVxuICAgIHNldChrZXksIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgdGhpcy5lbGVtZW50LnNldEF0dHJpYnV0ZShuYW1lLCB2YWx1ZSk7XG4gICAgICAgIHJldHVybiB0aGlzLmdldChrZXkpO1xuICAgIH1cbiAgICBoYXMoa2V5KSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5oYXNBdHRyaWJ1dGUobmFtZSk7XG4gICAgfVxuICAgIGRlbGV0ZShrZXkpIHtcbiAgICAgICAgaWYgKHRoaXMuaGFzKGtleSkpIHtcbiAgICAgICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgICAgIHRoaXMuZWxlbWVudC5yZW1vdmVBdHRyaWJ1dGUobmFtZSk7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KGtleSkge1xuICAgICAgICByZXR1cm4gYGRhdGEtJHt0aGlzLmlkZW50aWZpZXJ9LSR7ZGFzaGVyaXplKGtleSl9YDtcbiAgICB9XG59XG5cbmNsYXNzIEd1aWRlIHtcbiAgICBjb25zdHJ1Y3Rvcihsb2dnZXIpIHtcbiAgICAgICAgdGhpcy53YXJuZWRLZXlzQnlPYmplY3QgPSBuZXcgV2Vha01hcCgpO1xuICAgICAgICB0aGlzLmxvZ2dlciA9IGxvZ2dlcjtcbiAgICB9XG4gICAgd2FybihvYmplY3QsIGtleSwgbWVzc2FnZSkge1xuICAgICAgICBsZXQgd2FybmVkS2V5cyA9IHRoaXMud2FybmVkS2V5c0J5T2JqZWN0LmdldChvYmplY3QpO1xuICAgICAgICBpZiAoIXdhcm5lZEtleXMpIHtcbiAgICAgICAgICAgIHdhcm5lZEtleXMgPSBuZXcgU2V0KCk7XG4gICAgICAgICAgICB0aGlzLndhcm5lZEtleXNCeU9iamVjdC5zZXQob2JqZWN0LCB3YXJuZWRLZXlzKTtcbiAgICAgICAgfVxuICAgICAgICBpZiAoIXdhcm5lZEtleXMuaGFzKGtleSkpIHtcbiAgICAgICAgICAgIHdhcm5lZEtleXMuYWRkKGtleSk7XG4gICAgICAgICAgICB0aGlzLmxvZ2dlci53YXJuKG1lc3NhZ2UsIG9iamVjdCk7XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmZ1bmN0aW9uIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbihhdHRyaWJ1dGVOYW1lLCB0b2tlbikge1xuICAgIHJldHVybiBgWyR7YXR0cmlidXRlTmFtZX1+PVwiJHt0b2tlbn1cIl1gO1xufVxuXG5jbGFzcyBUYXJnZXRTZXQge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5zY2hlbWE7XG4gICAgfVxuICAgIGhhcyh0YXJnZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmZpbmQodGFyZ2V0TmFtZSkgIT0gbnVsbDtcbiAgICB9XG4gICAgZmluZCguLi50YXJnZXROYW1lcykge1xuICAgICAgICByZXR1cm4gdGFyZ2V0TmFtZXMucmVkdWNlKCh0YXJnZXQsIHRhcmdldE5hbWUpID0+IHRhcmdldCB8fCB0aGlzLmZpbmRUYXJnZXQodGFyZ2V0TmFtZSkgfHwgdGhpcy5maW5kTGVnYWN5VGFyZ2V0KHRhcmdldE5hbWUpLCB1bmRlZmluZWQpO1xuICAgIH1cbiAgICBmaW5kQWxsKC4uLnRhcmdldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiB0YXJnZXROYW1lcy5yZWR1Y2UoKHRhcmdldHMsIHRhcmdldE5hbWUpID0+IFtcbiAgICAgICAgICAgIC4uLnRhcmdldHMsXG4gICAgICAgICAgICAuLi50aGlzLmZpbmRBbGxUYXJnZXRzKHRhcmdldE5hbWUpLFxuICAgICAgICAgICAgLi4udGhpcy5maW5kQWxsTGVnYWN5VGFyZ2V0cyh0YXJnZXROYW1lKSxcbiAgICAgICAgXSwgW10pO1xuICAgIH1cbiAgICBmaW5kVGFyZ2V0KHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZmluZEVsZW1lbnQoc2VsZWN0b3IpO1xuICAgIH1cbiAgICBmaW5kQWxsVGFyZ2V0cyh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSk7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmZpbmRBbGxFbGVtZW50cyhzZWxlY3Rvcik7XG4gICAgfVxuICAgIGdldFNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGVGb3JTY29wZSh0aGlzLmlkZW50aWZpZXIpO1xuICAgICAgICByZXR1cm4gYXR0cmlidXRlVmFsdWVDb250YWluc1Rva2VuKGF0dHJpYnV0ZU5hbWUsIHRhcmdldE5hbWUpO1xuICAgIH1cbiAgICBmaW5kTGVnYWN5VGFyZ2V0KHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldExlZ2FjeVNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVwcmVjYXRlKHRoaXMuc2NvcGUuZmluZEVsZW1lbnQoc2VsZWN0b3IpLCB0YXJnZXROYW1lKTtcbiAgICB9XG4gICAgZmluZEFsbExlZ2FjeVRhcmdldHModGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0TGVnYWN5U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5maW5kQWxsRWxlbWVudHMoc2VsZWN0b3IpLm1hcCgoZWxlbWVudCkgPT4gdGhpcy5kZXByZWNhdGUoZWxlbWVudCwgdGFyZ2V0TmFtZSkpO1xuICAgIH1cbiAgICBnZXRMZWdhY3lTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCB0YXJnZXREZXNjcmlwdG9yID0gYCR7dGhpcy5pZGVudGlmaWVyfS4ke3RhcmdldE5hbWV9YDtcbiAgICAgICAgcmV0dXJuIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbih0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGUsIHRhcmdldERlc2NyaXB0b3IpO1xuICAgIH1cbiAgICBkZXByZWNhdGUoZWxlbWVudCwgdGFyZ2V0TmFtZSkge1xuICAgICAgICBpZiAoZWxlbWVudCkge1xuICAgICAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyIH0gPSB0aGlzO1xuICAgICAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuc2NoZW1hLnRhcmdldEF0dHJpYnV0ZTtcbiAgICAgICAgICAgIGNvbnN0IHJldmlzZWRBdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEudGFyZ2V0QXR0cmlidXRlRm9yU2NvcGUoaWRlbnRpZmllcik7XG4gICAgICAgICAgICB0aGlzLmd1aWRlLndhcm4oZWxlbWVudCwgYHRhcmdldDoke3RhcmdldE5hbWV9YCwgYFBsZWFzZSByZXBsYWNlICR7YXR0cmlidXRlTmFtZX09XCIke2lkZW50aWZpZXJ9LiR7dGFyZ2V0TmFtZX1cIiB3aXRoICR7cmV2aXNlZEF0dHJpYnV0ZU5hbWV9PVwiJHt0YXJnZXROYW1lfVwiLiBgICtcbiAgICAgICAgICAgICAgICBgVGhlICR7YXR0cmlidXRlTmFtZX0gYXR0cmlidXRlIGlzIGRlcHJlY2F0ZWQgYW5kIHdpbGwgYmUgcmVtb3ZlZCBpbiBhIGZ1dHVyZSB2ZXJzaW9uIG9mIFN0aW11bHVzLmApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBlbGVtZW50O1xuICAgIH1cbiAgICBnZXQgZ3VpZGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmd1aWRlO1xuICAgIH1cbn1cblxuY2xhc3MgT3V0bGV0U2V0IHtcbiAgICBjb25zdHJ1Y3RvcihzY29wZSwgY29udHJvbGxlckVsZW1lbnQpIHtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgICAgICB0aGlzLmNvbnRyb2xsZXJFbGVtZW50ID0gY29udHJvbGxlckVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuc2NoZW1hO1xuICAgIH1cbiAgICBoYXMob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5maW5kKG91dGxldE5hbWUpICE9IG51bGw7XG4gICAgfVxuICAgIGZpbmQoLi4ub3V0bGV0TmFtZXMpIHtcbiAgICAgICAgcmV0dXJuIG91dGxldE5hbWVzLnJlZHVjZSgob3V0bGV0LCBvdXRsZXROYW1lKSA9PiBvdXRsZXQgfHwgdGhpcy5maW5kT3V0bGV0KG91dGxldE5hbWUpLCB1bmRlZmluZWQpO1xuICAgIH1cbiAgICBmaW5kQWxsKC4uLm91dGxldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiBvdXRsZXROYW1lcy5yZWR1Y2UoKG91dGxldHMsIG91dGxldE5hbWUpID0+IFsuLi5vdXRsZXRzLCAuLi50aGlzLmZpbmRBbGxPdXRsZXRzKG91dGxldE5hbWUpXSwgW10pO1xuICAgIH1cbiAgICBnZXRTZWxlY3RvckZvck91dGxldE5hbWUob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEub3V0bGV0QXR0cmlidXRlRm9yU2NvcGUodGhpcy5pZGVudGlmaWVyLCBvdXRsZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udHJvbGxlckVsZW1lbnQuZ2V0QXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbiAgICBmaW5kT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKHNlbGVjdG9yKVxuICAgICAgICAgICAgcmV0dXJuIHRoaXMuZmluZEVsZW1lbnQoc2VsZWN0b3IsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBmaW5kQWxsT3V0bGV0cyhvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRTZWxlY3RvckZvck91dGxldE5hbWUob3V0bGV0TmFtZSk7XG4gICAgICAgIHJldHVybiBzZWxlY3RvciA/IHRoaXMuZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yLCBvdXRsZXROYW1lKSA6IFtdO1xuICAgIH1cbiAgICBmaW5kRWxlbWVudChzZWxlY3Rvciwgb3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBlbGVtZW50cyA9IHRoaXMuc2NvcGUucXVlcnlFbGVtZW50cyhzZWxlY3Rvcik7XG4gICAgICAgIHJldHVybiBlbGVtZW50cy5maWx0ZXIoKGVsZW1lbnQpID0+IHRoaXMubWF0Y2hlc0VsZW1lbnQoZWxlbWVudCwgc2VsZWN0b3IsIG91dGxldE5hbWUpKVswXTtcbiAgICB9XG4gICAgZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yLCBvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGVsZW1lbnRzID0gdGhpcy5zY29wZS5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKTtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnRzLmZpbHRlcigoZWxlbWVudCkgPT4gdGhpcy5tYXRjaGVzRWxlbWVudChlbGVtZW50LCBzZWxlY3Rvciwgb3V0bGV0TmFtZSkpO1xuICAgIH1cbiAgICBtYXRjaGVzRWxlbWVudChlbGVtZW50LCBzZWxlY3Rvciwgb3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBjb250cm9sbGVyQXR0cmlidXRlID0gZWxlbWVudC5nZXRBdHRyaWJ1dGUodGhpcy5zY29wZS5zY2hlbWEuY29udHJvbGxlckF0dHJpYnV0ZSkgfHwgXCJcIjtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnQubWF0Y2hlcyhzZWxlY3RvcikgJiYgY29udHJvbGxlckF0dHJpYnV0ZS5zcGxpdChcIiBcIikuaW5jbHVkZXMob3V0bGV0TmFtZSk7XG4gICAgfVxufVxuXG5jbGFzcyBTY29wZSB7XG4gICAgY29uc3RydWN0b3Ioc2NoZW1hLCBlbGVtZW50LCBpZGVudGlmaWVyLCBsb2dnZXIpIHtcbiAgICAgICAgdGhpcy50YXJnZXRzID0gbmV3IFRhcmdldFNldCh0aGlzKTtcbiAgICAgICAgdGhpcy5jbGFzc2VzID0gbmV3IENsYXNzTWFwKHRoaXMpO1xuICAgICAgICB0aGlzLmRhdGEgPSBuZXcgRGF0YU1hcCh0aGlzKTtcbiAgICAgICAgdGhpcy5jb250YWluc0VsZW1lbnQgPSAoZWxlbWVudCkgPT4ge1xuICAgICAgICAgICAgcmV0dXJuIGVsZW1lbnQuY2xvc2VzdCh0aGlzLmNvbnRyb2xsZXJTZWxlY3RvcikgPT09IHRoaXMuZWxlbWVudDtcbiAgICAgICAgfTtcbiAgICAgICAgdGhpcy5zY2hlbWEgPSBzY2hlbWE7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuaWRlbnRpZmllciA9IGlkZW50aWZpZXI7XG4gICAgICAgIHRoaXMuZ3VpZGUgPSBuZXcgR3VpZGUobG9nZ2VyKTtcbiAgICAgICAgdGhpcy5vdXRsZXRzID0gbmV3IE91dGxldFNldCh0aGlzLmRvY3VtZW50U2NvcGUsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBmaW5kRWxlbWVudChzZWxlY3Rvcikge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpID8gdGhpcy5lbGVtZW50IDogdGhpcy5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKS5maW5kKHRoaXMuY29udGFpbnNFbGVtZW50KTtcbiAgICB9XG4gICAgZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yKSB7XG4gICAgICAgIHJldHVybiBbXG4gICAgICAgICAgICAuLi4odGhpcy5lbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpID8gW3RoaXMuZWxlbWVudF0gOiBbXSksXG4gICAgICAgICAgICAuLi50aGlzLnF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpLmZpbHRlcih0aGlzLmNvbnRhaW5zRWxlbWVudCksXG4gICAgICAgIF07XG4gICAgfVxuICAgIHF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5lbGVtZW50LnF1ZXJ5U2VsZWN0b3JBbGwoc2VsZWN0b3IpKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJTZWxlY3RvcigpIHtcbiAgICAgICAgcmV0dXJuIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbih0aGlzLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlLCB0aGlzLmlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBnZXQgaXNEb2N1bWVudFNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50ID09PSBkb2N1bWVudC5kb2N1bWVudEVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBkb2N1bWVudFNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5pc0RvY3VtZW50U2NvcGVcbiAgICAgICAgICAgID8gdGhpc1xuICAgICAgICAgICAgOiBuZXcgU2NvcGUodGhpcy5zY2hlbWEsIGRvY3VtZW50LmRvY3VtZW50RWxlbWVudCwgdGhpcy5pZGVudGlmaWVyLCB0aGlzLmd1aWRlLmxvZ2dlcik7XG4gICAgfVxufVxuXG5jbGFzcyBTY29wZU9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBzY2hlbWEsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIgPSBuZXcgVmFsdWVMaXN0T2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLmNvbnRyb2xsZXJBdHRyaWJ1dGUsIHRoaXMpO1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllckJ5RWxlbWVudCA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMgPSBuZXcgV2Vha01hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJBdHRyaWJ1dGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlO1xuICAgIH1cbiAgICBwYXJzZVZhbHVlRm9yVG9rZW4odG9rZW4pIHtcbiAgICAgICAgY29uc3QgeyBlbGVtZW50LCBjb250ZW50OiBpZGVudGlmaWVyIH0gPSB0b2tlbjtcbiAgICAgICAgcmV0dXJuIHRoaXMucGFyc2VWYWx1ZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBwYXJzZVZhbHVlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBzY29wZXNCeUlkZW50aWZpZXIgPSB0aGlzLmZldGNoU2NvcGVzQnlJZGVudGlmaWVyRm9yRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgbGV0IHNjb3BlID0gc2NvcGVzQnlJZGVudGlmaWVyLmdldChpZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKCFzY29wZSkge1xuICAgICAgICAgICAgc2NvcGUgPSB0aGlzLmRlbGVnYXRlLmNyZWF0ZVNjb3BlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcik7XG4gICAgICAgICAgICBzY29wZXNCeUlkZW50aWZpZXIuc2V0KGlkZW50aWZpZXIsIHNjb3BlKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gc2NvcGU7XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpIHtcbiAgICAgICAgY29uc3QgcmVmZXJlbmNlQ291bnQgPSAodGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5nZXQodmFsdWUpIHx8IDApICsgMTtcbiAgICAgICAgdGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5zZXQodmFsdWUsIHJlZmVyZW5jZUNvdW50KTtcbiAgICAgICAgaWYgKHJlZmVyZW5jZUNvdW50ID09IDEpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc2NvcGVDb25uZWN0ZWQodmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRWYWx1ZShlbGVtZW50LCB2YWx1ZSkge1xuICAgICAgICBjb25zdCByZWZlcmVuY2VDb3VudCA9IHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMuZ2V0KHZhbHVlKTtcbiAgICAgICAgaWYgKHJlZmVyZW5jZUNvdW50KSB7XG4gICAgICAgICAgICB0aGlzLnNjb3BlUmVmZXJlbmNlQ291bnRzLnNldCh2YWx1ZSwgcmVmZXJlbmNlQ291bnQgLSAxKTtcbiAgICAgICAgICAgIGlmIChyZWZlcmVuY2VDb3VudCA9PSAxKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zY29wZURpc2Nvbm5lY3RlZCh2YWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgZmV0Y2hTY29wZXNCeUlkZW50aWZpZXJGb3JFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgbGV0IHNjb3Blc0J5SWRlbnRpZmllciA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyQnlFbGVtZW50LmdldChlbGVtZW50KTtcbiAgICAgICAgaWYgKCFzY29wZXNCeUlkZW50aWZpZXIpIHtcbiAgICAgICAgICAgIHNjb3Blc0J5SWRlbnRpZmllciA9IG5ldyBNYXAoKTtcbiAgICAgICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyQnlFbGVtZW50LnNldChlbGVtZW50LCBzY29wZXNCeUlkZW50aWZpZXIpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBzY29wZXNCeUlkZW50aWZpZXI7XG4gICAgfVxufVxuXG5jbGFzcyBSb3V0ZXIge1xuICAgIGNvbnN0cnVjdG9yKGFwcGxpY2F0aW9uKSB7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24gPSBhcHBsaWNhdGlvbjtcbiAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyID0gbmV3IFNjb3BlT2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLnNjaGVtYSwgdGhpcyk7XG4gICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMubW9kdWxlc0J5SWRlbnRpZmllciA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGxvZ2dlcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24ubG9nZ2VyO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlckF0dHJpYnV0ZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGU7XG4gICAgfVxuICAgIGdldCBtb2R1bGVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIudmFsdWVzKCkpO1xuICAgIH1cbiAgICBnZXQgY29udGV4dHMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm1vZHVsZXMucmVkdWNlKChjb250ZXh0cywgbW9kdWxlKSA9PiBjb250ZXh0cy5jb25jYXQobW9kdWxlLmNvbnRleHRzKSwgW10pO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuc2NvcGVPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGxvYWREZWZpbml0aW9uKGRlZmluaXRpb24pIHtcbiAgICAgICAgdGhpcy51bmxvYWRJZGVudGlmaWVyKGRlZmluaXRpb24uaWRlbnRpZmllcik7XG4gICAgICAgIGNvbnN0IG1vZHVsZSA9IG5ldyBNb2R1bGUodGhpcy5hcHBsaWNhdGlvbiwgZGVmaW5pdGlvbik7XG4gICAgICAgIHRoaXMuY29ubmVjdE1vZHVsZShtb2R1bGUpO1xuICAgICAgICBjb25zdCBhZnRlckxvYWQgPSBkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3Rvci5hZnRlckxvYWQ7XG4gICAgICAgIGlmIChhZnRlckxvYWQpIHtcbiAgICAgICAgICAgIGFmdGVyTG9hZC5jYWxsKGRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yLCBkZWZpbml0aW9uLmlkZW50aWZpZXIsIHRoaXMuYXBwbGljYXRpb24pO1xuICAgICAgICB9XG4gICAgfVxuICAgIHVubG9hZElkZW50aWZpZXIoaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RNb2R1bGUobW9kdWxlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXRDb250ZXh0Rm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICByZXR1cm4gbW9kdWxlLmNvbnRleHRzLmZpbmQoKGNvbnRleHQpID0+IGNvbnRleHQuZWxlbWVudCA9PSBlbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9wb3NlVG9Db25uZWN0U2NvcGVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgICAgIGNvbnN0IHNjb3BlID0gdGhpcy5zY29wZU9ic2VydmVyLnBhcnNlVmFsdWVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKHNjb3BlKSB7XG4gICAgICAgICAgICB0aGlzLnNjb3BlT2JzZXJ2ZXIuZWxlbWVudE1hdGNoZWRWYWx1ZShzY29wZS5lbGVtZW50LCBzY29wZSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICBjb25zb2xlLmVycm9yKGBDb3VsZG4ndCBmaW5kIG9yIGNyZWF0ZSBzY29wZSBmb3IgaWRlbnRpZmllcjogXCIke2lkZW50aWZpZXJ9XCIgYW5kIGVsZW1lbnQ6YCwgZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgaGFuZGxlRXJyb3IoZXJyb3IsIG1lc3NhZ2UsIGRldGFpbCkge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwpO1xuICAgIH1cbiAgICBjcmVhdGVTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgcmV0dXJuIG5ldyBTY29wZSh0aGlzLnNjaGVtYSwgZWxlbWVudCwgaWRlbnRpZmllciwgdGhpcy5sb2dnZXIpO1xuICAgIH1cbiAgICBzY29wZUNvbm5lY3RlZChzY29wZSkge1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllci5hZGQoc2NvcGUuaWRlbnRpZmllciwgc2NvcGUpO1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KHNjb3BlLmlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICBtb2R1bGUuY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2NvcGVEaXNjb25uZWN0ZWQoc2NvcGUpIHtcbiAgICAgICAgdGhpcy5zY29wZXNCeUlkZW50aWZpZXIuZGVsZXRlKHNjb3BlLmlkZW50aWZpZXIsIHNjb3BlKTtcbiAgICAgICAgY29uc3QgbW9kdWxlID0gdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmdldChzY29wZS5pZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKG1vZHVsZSkge1xuICAgICAgICAgICAgbW9kdWxlLmRpc2Nvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGNvbm5lY3RNb2R1bGUobW9kdWxlKSB7XG4gICAgICAgIHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5zZXQobW9kdWxlLmlkZW50aWZpZXIsIG1vZHVsZSk7XG4gICAgICAgIGNvbnN0IHNjb3BlcyA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmdldFZhbHVlc0ZvcktleShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIHNjb3Blcy5mb3JFYWNoKChzY29wZSkgPT4gbW9kdWxlLmNvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdE1vZHVsZShtb2R1bGUpIHtcbiAgICAgICAgdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmRlbGV0ZShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIGNvbnN0IHNjb3BlcyA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmdldFZhbHVlc0ZvcktleShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIHNjb3Blcy5mb3JFYWNoKChzY29wZSkgPT4gbW9kdWxlLmRpc2Nvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpKTtcbiAgICB9XG59XG5cbmNvbnN0IGRlZmF1bHRTY2hlbWEgPSB7XG4gICAgY29udHJvbGxlckF0dHJpYnV0ZTogXCJkYXRhLWNvbnRyb2xsZXJcIixcbiAgICBhY3Rpb25BdHRyaWJ1dGU6IFwiZGF0YS1hY3Rpb25cIixcbiAgICB0YXJnZXRBdHRyaWJ1dGU6IFwiZGF0YS10YXJnZXRcIixcbiAgICB0YXJnZXRBdHRyaWJ1dGVGb3JTY29wZTogKGlkZW50aWZpZXIpID0+IGBkYXRhLSR7aWRlbnRpZmllcn0tdGFyZ2V0YCxcbiAgICBvdXRsZXRBdHRyaWJ1dGVGb3JTY29wZTogKGlkZW50aWZpZXIsIG91dGxldCkgPT4gYGRhdGEtJHtpZGVudGlmaWVyfS0ke291dGxldH0tb3V0bGV0YCxcbiAgICBrZXlNYXBwaW5nczogT2JqZWN0LmFzc2lnbihPYmplY3QuYXNzaWduKHsgZW50ZXI6IFwiRW50ZXJcIiwgdGFiOiBcIlRhYlwiLCBlc2M6IFwiRXNjYXBlXCIsIHNwYWNlOiBcIiBcIiwgdXA6IFwiQXJyb3dVcFwiLCBkb3duOiBcIkFycm93RG93blwiLCBsZWZ0OiBcIkFycm93TGVmdFwiLCByaWdodDogXCJBcnJvd1JpZ2h0XCIsIGhvbWU6IFwiSG9tZVwiLCBlbmQ6IFwiRW5kXCIsIHBhZ2VfdXA6IFwiUGFnZVVwXCIsIHBhZ2VfZG93bjogXCJQYWdlRG93blwiIH0sIG9iamVjdEZyb21FbnRyaWVzKFwiYWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXpcIi5zcGxpdChcIlwiKS5tYXAoKGMpID0+IFtjLCBjXSkpKSwgb2JqZWN0RnJvbUVudHJpZXMoXCIwMTIzNDU2Nzg5XCIuc3BsaXQoXCJcIikubWFwKChuKSA9PiBbbiwgbl0pKSksXG59O1xuZnVuY3Rpb24gb2JqZWN0RnJvbUVudHJpZXMoYXJyYXkpIHtcbiAgICByZXR1cm4gYXJyYXkucmVkdWNlKChtZW1vLCBbaywgdl0pID0+IChPYmplY3QuYXNzaWduKE9iamVjdC5hc3NpZ24oe30sIG1lbW8pLCB7IFtrXTogdiB9KSksIHt9KTtcbn1cblxuY2xhc3MgQXBwbGljYXRpb24ge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQgPSBkb2N1bWVudC5kb2N1bWVudEVsZW1lbnQsIHNjaGVtYSA9IGRlZmF1bHRTY2hlbWEpIHtcbiAgICAgICAgdGhpcy5sb2dnZXIgPSBjb25zb2xlO1xuICAgICAgICB0aGlzLmRlYnVnID0gZmFsc2U7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eSA9IChpZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCA9IHt9KSA9PiB7XG4gICAgICAgICAgICBpZiAodGhpcy5kZWJ1Zykge1xuICAgICAgICAgICAgICAgIHRoaXMubG9nRm9ybWF0dGVkTWVzc2FnZShpZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH07XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgICAgICB0aGlzLmRpc3BhdGNoZXIgPSBuZXcgRGlzcGF0Y2hlcih0aGlzKTtcbiAgICAgICAgdGhpcy5yb3V0ZXIgPSBuZXcgUm91dGVyKHRoaXMpO1xuICAgICAgICB0aGlzLmFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzID0gT2JqZWN0LmFzc2lnbih7fSwgZGVmYXVsdEFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzKTtcbiAgICB9XG4gICAgc3RhdGljIHN0YXJ0KGVsZW1lbnQsIHNjaGVtYSkge1xuICAgICAgICBjb25zdCBhcHBsaWNhdGlvbiA9IG5ldyB0aGlzKGVsZW1lbnQsIHNjaGVtYSk7XG4gICAgICAgIGFwcGxpY2F0aW9uLnN0YXJ0KCk7XG4gICAgICAgIHJldHVybiBhcHBsaWNhdGlvbjtcbiAgICB9XG4gICAgYXN5bmMgc3RhcnQoKSB7XG4gICAgICAgIGF3YWl0IGRvbVJlYWR5KCk7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RhcnRpbmdcIik7XG4gICAgICAgIHRoaXMuZGlzcGF0Y2hlci5zdGFydCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5zdGFydCgpO1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJhcHBsaWNhdGlvblwiLCBcInN0YXJ0XCIpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJhcHBsaWNhdGlvblwiLCBcInN0b3BwaW5nXCIpO1xuICAgICAgICB0aGlzLmRpc3BhdGNoZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5zdG9wKCk7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RvcFwiKTtcbiAgICB9XG4gICAgcmVnaXN0ZXIoaWRlbnRpZmllciwgY29udHJvbGxlckNvbnN0cnVjdG9yKSB7XG4gICAgICAgIHRoaXMubG9hZCh7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXJDb25zdHJ1Y3RvciB9KTtcbiAgICB9XG4gICAgcmVnaXN0ZXJBY3Rpb25PcHRpb24obmFtZSwgZmlsdGVyKSB7XG4gICAgICAgIHRoaXMuYWN0aW9uRGVzY3JpcHRvckZpbHRlcnNbbmFtZV0gPSBmaWx0ZXI7XG4gICAgfVxuICAgIGxvYWQoaGVhZCwgLi4ucmVzdCkge1xuICAgICAgICBjb25zdCBkZWZpbml0aW9ucyA9IEFycmF5LmlzQXJyYXkoaGVhZCkgPyBoZWFkIDogW2hlYWQsIC4uLnJlc3RdO1xuICAgICAgICBkZWZpbml0aW9ucy5mb3JFYWNoKChkZWZpbml0aW9uKSA9PiB7XG4gICAgICAgICAgICBpZiAoZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3Iuc2hvdWxkTG9hZCkge1xuICAgICAgICAgICAgICAgIHRoaXMucm91dGVyLmxvYWREZWZpbml0aW9uKGRlZmluaXRpb24pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICB9XG4gICAgdW5sb2FkKGhlYWQsIC4uLnJlc3QpIHtcbiAgICAgICAgY29uc3QgaWRlbnRpZmllcnMgPSBBcnJheS5pc0FycmF5KGhlYWQpID8gaGVhZCA6IFtoZWFkLCAuLi5yZXN0XTtcbiAgICAgICAgaWRlbnRpZmllcnMuZm9yRWFjaCgoaWRlbnRpZmllcikgPT4gdGhpcy5yb3V0ZXIudW5sb2FkSWRlbnRpZmllcihpZGVudGlmaWVyKSk7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVycygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMucm91dGVyLmNvbnRleHRzLm1hcCgoY29udGV4dCkgPT4gY29udGV4dC5jb250cm9sbGVyKTtcbiAgICB9XG4gICAgZ2V0Q29udHJvbGxlckZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgY29uc3QgY29udGV4dCA9IHRoaXMucm91dGVyLmdldENvbnRleHRGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICAgICAgcmV0dXJuIGNvbnRleHQgPyBjb250ZXh0LmNvbnRyb2xsZXIgOiBudWxsO1xuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZXJyb3IoYCVzXFxuXFxuJW9cXG5cXG4lb2AsIG1lc3NhZ2UsIGVycm9yLCBkZXRhaWwpO1xuICAgICAgICAoX2EgPSB3aW5kb3cub25lcnJvcikgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLmNhbGwod2luZG93LCBtZXNzYWdlLCBcIlwiLCAwLCAwLCBlcnJvcik7XG4gICAgfVxuICAgIGxvZ0Zvcm1hdHRlZE1lc3NhZ2UoaWRlbnRpZmllciwgZnVuY3Rpb25OYW1lLCBkZXRhaWwgPSB7fSkge1xuICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgYXBwbGljYXRpb246IHRoaXMgfSwgZGV0YWlsKTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZ3JvdXBDb2xsYXBzZWQoYCR7aWRlbnRpZmllcn0gIyR7ZnVuY3Rpb25OYW1lfWApO1xuICAgICAgICB0aGlzLmxvZ2dlci5sb2coXCJkZXRhaWxzOlwiLCBPYmplY3QuYXNzaWduKHt9LCBkZXRhaWwpKTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZ3JvdXBFbmQoKTtcbiAgICB9XG59XG5mdW5jdGlvbiBkb21SZWFkeSgpIHtcbiAgICByZXR1cm4gbmV3IFByb21pc2UoKHJlc29sdmUpID0+IHtcbiAgICAgICAgaWYgKGRvY3VtZW50LnJlYWR5U3RhdGUgPT0gXCJsb2FkaW5nXCIpIHtcbiAgICAgICAgICAgIGRvY3VtZW50LmFkZEV2ZW50TGlzdGVuZXIoXCJET01Db250ZW50TG9hZGVkXCIsICgpID0+IHJlc29sdmUoKSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXNvbHZlKCk7XG4gICAgICAgIH1cbiAgICB9KTtcbn1cblxuZnVuY3Rpb24gQ2xhc3NQcm9wZXJ0aWVzQmxlc3NpbmcoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBjbGFzc2VzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwiY2xhc3Nlc1wiKTtcbiAgICByZXR1cm4gY2xhc3Nlcy5yZWR1Y2UoKHByb3BlcnRpZXMsIGNsYXNzRGVmaW5pdGlvbikgPT4ge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihwcm9wZXJ0aWVzLCBwcm9wZXJ0aWVzRm9yQ2xhc3NEZWZpbml0aW9uKGNsYXNzRGVmaW5pdGlvbikpO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JDbGFzc0RlZmluaXRpb24oa2V5KSB7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake2tleX1DbGFzc2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgeyBjbGFzc2VzIH0gPSB0aGlzO1xuICAgICAgICAgICAgICAgIGlmIChjbGFzc2VzLmhhcyhrZXkpKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBjbGFzc2VzLmdldChrZXkpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgY29uc3QgYXR0cmlidXRlID0gY2xhc3Nlcy5nZXRBdHRyaWJ1dGVOYW1lKGtleSk7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyBhdHRyaWJ1dGUgXCIke2F0dHJpYnV0ZX1cImApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtrZXl9Q2xhc3Nlc2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMuY2xhc3Nlcy5nZXRBbGwoa2V5KTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKGtleSl9Q2xhc3NgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLmNsYXNzZXMuaGFzKGtleSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5cbmZ1bmN0aW9uIE91dGxldFByb3BlcnRpZXNCbGVzc2luZyhjb25zdHJ1Y3Rvcikge1xuICAgIGNvbnN0IG91dGxldHMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJvdXRsZXRzXCIpO1xuICAgIHJldHVybiBvdXRsZXRzLnJlZHVjZSgocHJvcGVydGllcywgb3V0bGV0RGVmaW5pdGlvbikgPT4ge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihwcm9wZXJ0aWVzLCBwcm9wZXJ0aWVzRm9yT3V0bGV0RGVmaW5pdGlvbihvdXRsZXREZWZpbml0aW9uKSk7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gZ2V0T3V0bGV0Q29udHJvbGxlcihjb250cm9sbGVyLCBlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgcmV0dXJuIGNvbnRyb2xsZXIuYXBwbGljYXRpb24uZ2V0Q29udHJvbGxlckZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xufVxuZnVuY3Rpb24gZ2V0Q29udHJvbGxlckFuZEVuc3VyZUNvbm5lY3RlZFNjb3BlKGNvbnRyb2xsZXIsIGVsZW1lbnQsIG91dGxldE5hbWUpIHtcbiAgICBsZXQgb3V0bGV0Q29udHJvbGxlciA9IGdldE91dGxldENvbnRyb2xsZXIoY29udHJvbGxlciwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgaWYgKG91dGxldENvbnRyb2xsZXIpXG4gICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xuICAgIGNvbnRyb2xsZXIuYXBwbGljYXRpb24ucm91dGVyLnByb3Bvc2VUb0Nvbm5lY3RTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIG91dGxldENvbnRyb2xsZXIgPSBnZXRPdXRsZXRDb250cm9sbGVyKGNvbnRyb2xsZXIsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICByZXR1cm4gb3V0bGV0Q29udHJvbGxlcjtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JPdXRsZXREZWZpbml0aW9uKG5hbWUpIHtcbiAgICBjb25zdCBjYW1lbGl6ZWROYW1lID0gbmFtZXNwYWNlQ2FtZWxpemUobmFtZSk7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake2NhbWVsaXplZE5hbWV9T3V0bGV0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRFbGVtZW50ID0gdGhpcy5vdXRsZXRzLmZpbmQobmFtZSk7XG4gICAgICAgICAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLm91dGxldHMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmIChvdXRsZXRFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IG91dGxldENvbnRyb2xsZXIgPSBnZXRDb250cm9sbGVyQW5kRW5zdXJlQ29ubmVjdGVkU2NvcGUodGhpcywgb3V0bGV0RWxlbWVudCwgbmFtZSk7XG4gICAgICAgICAgICAgICAgICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICAgICAgICAgICAgICAgICAgcmV0dXJuIG91dGxldENvbnRyb2xsZXI7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgVGhlIHByb3ZpZGVkIG91dGxldCBlbGVtZW50IGlzIG1pc3NpbmcgYW4gb3V0bGV0IGNvbnRyb2xsZXIgXCIke25hbWV9XCIgaW5zdGFuY2UgZm9yIGhvc3QgY29udHJvbGxlciBcIiR7dGhpcy5pZGVudGlmaWVyfVwiYCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyBvdXRsZXQgZWxlbWVudCBcIiR7bmFtZX1cIiBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCIuIFN0aW11bHVzIGNvdWxkbid0IGZpbmQgYSBtYXRjaGluZyBvdXRsZXQgZWxlbWVudCB1c2luZyBzZWxlY3RvciBcIiR7c2VsZWN0b3J9XCIuYCk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRzID0gdGhpcy5vdXRsZXRzLmZpbmRBbGwobmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG91dGxldHMubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gb3V0bGV0c1xuICAgICAgICAgICAgICAgICAgICAgICAgLm1hcCgob3V0bGV0RWxlbWVudCkgPT4ge1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc3Qgb3V0bGV0Q29udHJvbGxlciA9IGdldENvbnRyb2xsZXJBbmRFbnN1cmVDb25uZWN0ZWRTY29wZSh0aGlzLCBvdXRsZXRFbGVtZW50LCBuYW1lKTtcbiAgICAgICAgICAgICAgICAgICAgICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc29sZS53YXJuKGBUaGUgcHJvdmlkZWQgb3V0bGV0IGVsZW1lbnQgaXMgbWlzc2luZyBhbiBvdXRsZXQgY29udHJvbGxlciBcIiR7bmFtZX1cIiBpbnN0YW5jZSBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCJgLCBvdXRsZXRFbGVtZW50KTtcbiAgICAgICAgICAgICAgICAgICAgfSlcbiAgICAgICAgICAgICAgICAgICAgICAgIC5maWx0ZXIoKGNvbnRyb2xsZXIpID0+IGNvbnRyb2xsZXIpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICByZXR1cm4gW107XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRFbGVtZW50YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRFbGVtZW50ID0gdGhpcy5vdXRsZXRzLmZpbmQobmFtZSk7XG4gICAgICAgICAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLm91dGxldHMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmIChvdXRsZXRFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRFbGVtZW50O1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBNaXNzaW5nIG91dGxldCBlbGVtZW50IFwiJHtuYW1lfVwiIGZvciBob3N0IGNvbnRyb2xsZXIgXCIke3RoaXMuaWRlbnRpZmllcn1cIi4gU3RpbXVsdXMgY291bGRuJ3QgZmluZCBhIG1hdGNoaW5nIG91dGxldCBlbGVtZW50IHVzaW5nIHNlbGVjdG9yIFwiJHtzZWxlY3Rvcn1cIi5gKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRFbGVtZW50c2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0cy5maW5kQWxsKG5hbWUpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2BoYXMke2NhcGl0YWxpemUoY2FtZWxpemVkTmFtZSl9T3V0bGV0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXRzLmhhcyhuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbn1cblxuZnVuY3Rpb24gVGFyZ2V0UHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgdGFyZ2V0cyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBcInRhcmdldHNcIik7XG4gICAgcmV0dXJuIHRhcmdldHMucmVkdWNlKChwcm9wZXJ0aWVzLCB0YXJnZXREZWZpbml0aW9uKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JUYXJnZXREZWZpbml0aW9uKHRhcmdldERlZmluaXRpb24pKTtcbiAgICB9LCB7fSk7XG59XG5mdW5jdGlvbiBwcm9wZXJ0aWVzRm9yVGFyZ2V0RGVmaW5pdGlvbihuYW1lKSB7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake25hbWV9VGFyZ2V0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCB0YXJnZXQgPSB0aGlzLnRhcmdldHMuZmluZChuYW1lKTtcbiAgICAgICAgICAgICAgICBpZiAodGFyZ2V0KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiB0YXJnZXQ7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICB0aHJvdyBuZXcgRXJyb3IoYE1pc3NpbmcgdGFyZ2V0IGVsZW1lbnQgXCIke25hbWV9XCIgZm9yIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCIgY29udHJvbGxlcmApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtuYW1lfVRhcmdldHNgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLnRhcmdldHMuZmluZEFsbChuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKG5hbWUpfVRhcmdldGBdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMudGFyZ2V0cy5oYXMobmFtZSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5cbmZ1bmN0aW9uIFZhbHVlUHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgdmFsdWVEZWZpbml0aW9uUGFpcnMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNPYmplY3RQYWlycyhjb25zdHJ1Y3RvciwgXCJ2YWx1ZXNcIik7XG4gICAgY29uc3QgcHJvcGVydHlEZXNjcmlwdG9yTWFwID0ge1xuICAgICAgICB2YWx1ZURlc2NyaXB0b3JNYXA6IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdmFsdWVEZWZpbml0aW9uUGFpcnMucmVkdWNlKChyZXN1bHQsIHZhbHVlRGVmaW5pdGlvblBhaXIpID0+IHtcbiAgICAgICAgICAgICAgICAgICAgY29uc3QgdmFsdWVEZXNjcmlwdG9yID0gcGFyc2VWYWx1ZURlZmluaXRpb25QYWlyKHZhbHVlRGVmaW5pdGlvblBhaXIsIHRoaXMuaWRlbnRpZmllcik7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLmRhdGEuZ2V0QXR0cmlidXRlTmFtZUZvcktleSh2YWx1ZURlc2NyaXB0b3Iua2V5KTtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24ocmVzdWx0LCB7IFthdHRyaWJ1dGVOYW1lXTogdmFsdWVEZXNjcmlwdG9yIH0pO1xuICAgICAgICAgICAgICAgIH0sIHt9KTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbiAgICByZXR1cm4gdmFsdWVEZWZpbml0aW9uUGFpcnMucmVkdWNlKChwcm9wZXJ0aWVzLCB2YWx1ZURlZmluaXRpb25QYWlyKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JWYWx1ZURlZmluaXRpb25QYWlyKHZhbHVlRGVmaW5pdGlvblBhaXIpKTtcbiAgICB9LCBwcm9wZXJ0eURlc2NyaXB0b3JNYXApO1xufVxuZnVuY3Rpb24gcHJvcGVydGllc0ZvclZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpciwgY29udHJvbGxlcikge1xuICAgIGNvbnN0IGRlZmluaXRpb24gPSBwYXJzZVZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpciwgY29udHJvbGxlcik7XG4gICAgY29uc3QgeyBrZXksIG5hbWUsIHJlYWRlcjogcmVhZCwgd3JpdGVyOiB3cml0ZSB9ID0gZGVmaW5pdGlvbjtcbiAgICByZXR1cm4ge1xuICAgICAgICBbbmFtZV06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCB2YWx1ZSA9IHRoaXMuZGF0YS5nZXQoa2V5KTtcbiAgICAgICAgICAgICAgICBpZiAodmFsdWUgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIHJlYWQodmFsdWUpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGRlZmluaXRpb24uZGVmYXVsdFZhbHVlO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBzZXQodmFsdWUpIHtcbiAgICAgICAgICAgICAgICBpZiAodmFsdWUgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRhdGEuZGVsZXRlKGtleSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRhdGEuc2V0KGtleSwgd3JpdGUodmFsdWUpKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYGhhcyR7Y2FwaXRhbGl6ZShuYW1lKX1gXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLmRhdGEuaGFzKGtleSkgfHwgZGVmaW5pdGlvbi5oYXNDdXN0b21EZWZhdWx0VmFsdWU7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5mdW5jdGlvbiBwYXJzZVZhbHVlRGVmaW5pdGlvblBhaXIoW3Rva2VuLCB0eXBlRGVmaW5pdGlvbl0sIGNvbnRyb2xsZXIpIHtcbiAgICByZXR1cm4gdmFsdWVEZXNjcmlwdG9yRm9yVG9rZW5BbmRUeXBlRGVmaW5pdGlvbih7XG4gICAgICAgIGNvbnRyb2xsZXIsXG4gICAgICAgIHRva2VuLFxuICAgICAgICB0eXBlRGVmaW5pdGlvbixcbiAgICB9KTtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQoY29uc3RhbnQpIHtcbiAgICBzd2l0Y2ggKGNvbnN0YW50KSB7XG4gICAgICAgIGNhc2UgQXJyYXk6XG4gICAgICAgICAgICByZXR1cm4gXCJhcnJheVwiO1xuICAgICAgICBjYXNlIEJvb2xlYW46XG4gICAgICAgICAgICByZXR1cm4gXCJib29sZWFuXCI7XG4gICAgICAgIGNhc2UgTnVtYmVyOlxuICAgICAgICAgICAgcmV0dXJuIFwibnVtYmVyXCI7XG4gICAgICAgIGNhc2UgT2JqZWN0OlxuICAgICAgICAgICAgcmV0dXJuIFwib2JqZWN0XCI7XG4gICAgICAgIGNhc2UgU3RyaW5nOlxuICAgICAgICAgICAgcmV0dXJuIFwic3RyaW5nXCI7XG4gICAgfVxufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KGRlZmF1bHRWYWx1ZSkge1xuICAgIHN3aXRjaCAodHlwZW9mIGRlZmF1bHRWYWx1ZSkge1xuICAgICAgICBjYXNlIFwiYm9vbGVhblwiOlxuICAgICAgICAgICAgcmV0dXJuIFwiYm9vbGVhblwiO1xuICAgICAgICBjYXNlIFwibnVtYmVyXCI6XG4gICAgICAgICAgICByZXR1cm4gXCJudW1iZXJcIjtcbiAgICAgICAgY2FzZSBcInN0cmluZ1wiOlxuICAgICAgICAgICAgcmV0dXJuIFwic3RyaW5nXCI7XG4gICAgfVxuICAgIGlmIChBcnJheS5pc0FycmF5KGRlZmF1bHRWYWx1ZSkpXG4gICAgICAgIHJldHVybiBcImFycmF5XCI7XG4gICAgaWYgKE9iamVjdC5wcm90b3R5cGUudG9TdHJpbmcuY2FsbChkZWZhdWx0VmFsdWUpID09PSBcIltvYmplY3QgT2JqZWN0XVwiKVxuICAgICAgICByZXR1cm4gXCJvYmplY3RcIjtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVUeXBlT2JqZWN0KHBheWxvYWQpIHtcbiAgICBjb25zdCB7IGNvbnRyb2xsZXIsIHRva2VuLCB0eXBlT2JqZWN0IH0gPSBwYXlsb2FkO1xuICAgIGNvbnN0IGhhc1R5cGUgPSBpc1NvbWV0aGluZyh0eXBlT2JqZWN0LnR5cGUpO1xuICAgIGNvbnN0IGhhc0RlZmF1bHQgPSBpc1NvbWV0aGluZyh0eXBlT2JqZWN0LmRlZmF1bHQpO1xuICAgIGNvbnN0IGZ1bGxPYmplY3QgPSBoYXNUeXBlICYmIGhhc0RlZmF1bHQ7XG4gICAgY29uc3Qgb25seVR5cGUgPSBoYXNUeXBlICYmICFoYXNEZWZhdWx0O1xuICAgIGNvbnN0IG9ubHlEZWZhdWx0ID0gIWhhc1R5cGUgJiYgaGFzRGVmYXVsdDtcbiAgICBjb25zdCB0eXBlRnJvbU9iamVjdCA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZU9iamVjdC50eXBlKTtcbiAgICBjb25zdCB0eXBlRnJvbURlZmF1bHRWYWx1ZSA9IHBhcnNlVmFsdWVUeXBlRGVmYXVsdChwYXlsb2FkLnR5cGVPYmplY3QuZGVmYXVsdCk7XG4gICAgaWYgKG9ubHlUeXBlKVxuICAgICAgICByZXR1cm4gdHlwZUZyb21PYmplY3Q7XG4gICAgaWYgKG9ubHlEZWZhdWx0KVxuICAgICAgICByZXR1cm4gdHlwZUZyb21EZWZhdWx0VmFsdWU7XG4gICAgaWYgKHR5cGVGcm9tT2JqZWN0ICE9PSB0eXBlRnJvbURlZmF1bHRWYWx1ZSkge1xuICAgICAgICBjb25zdCBwcm9wZXJ0eVBhdGggPSBjb250cm9sbGVyID8gYCR7Y29udHJvbGxlcn0uJHt0b2tlbn1gIDogdG9rZW47XG4gICAgICAgIHRocm93IG5ldyBFcnJvcihgVGhlIHNwZWNpZmllZCBkZWZhdWx0IHZhbHVlIGZvciB0aGUgU3RpbXVsdXMgVmFsdWUgXCIke3Byb3BlcnR5UGF0aH1cIiBtdXN0IG1hdGNoIHRoZSBkZWZpbmVkIHR5cGUgXCIke3R5cGVGcm9tT2JqZWN0fVwiLiBUaGUgcHJvdmlkZWQgZGVmYXVsdCB2YWx1ZSBvZiBcIiR7dHlwZU9iamVjdC5kZWZhdWx0fVwiIGlzIG9mIHR5cGUgXCIke3R5cGVGcm9tRGVmYXVsdFZhbHVlfVwiLmApO1xuICAgIH1cbiAgICBpZiAoZnVsbE9iamVjdClcbiAgICAgICAgcmV0dXJuIHR5cGVGcm9tT2JqZWN0O1xufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVEZWZpbml0aW9uKHBheWxvYWQpIHtcbiAgICBjb25zdCB7IGNvbnRyb2xsZXIsIHRva2VuLCB0eXBlRGVmaW5pdGlvbiB9ID0gcGF5bG9hZDtcbiAgICBjb25zdCB0eXBlT2JqZWN0ID0geyBjb250cm9sbGVyLCB0b2tlbiwgdHlwZU9iamVjdDogdHlwZURlZmluaXRpb24gfTtcbiAgICBjb25zdCB0eXBlRnJvbU9iamVjdCA9IHBhcnNlVmFsdWVUeXBlT2JqZWN0KHR5cGVPYmplY3QpO1xuICAgIGNvbnN0IHR5cGVGcm9tRGVmYXVsdFZhbHVlID0gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KHR5cGVEZWZpbml0aW9uKTtcbiAgICBjb25zdCB0eXBlRnJvbUNvbnN0YW50ID0gcGFyc2VWYWx1ZVR5cGVDb25zdGFudCh0eXBlRGVmaW5pdGlvbik7XG4gICAgY29uc3QgdHlwZSA9IHR5cGVGcm9tT2JqZWN0IHx8IHR5cGVGcm9tRGVmYXVsdFZhbHVlIHx8IHR5cGVGcm9tQ29uc3RhbnQ7XG4gICAgaWYgKHR5cGUpXG4gICAgICAgIHJldHVybiB0eXBlO1xuICAgIGNvbnN0IHByb3BlcnR5UGF0aCA9IGNvbnRyb2xsZXIgPyBgJHtjb250cm9sbGVyfS4ke3R5cGVEZWZpbml0aW9ufWAgOiB0b2tlbjtcbiAgICB0aHJvdyBuZXcgRXJyb3IoYFVua25vd24gdmFsdWUgdHlwZSBcIiR7cHJvcGVydHlQYXRofVwiIGZvciBcIiR7dG9rZW59XCIgdmFsdWVgKTtcbn1cbmZ1bmN0aW9uIGRlZmF1bHRWYWx1ZUZvckRlZmluaXRpb24odHlwZURlZmluaXRpb24pIHtcbiAgICBjb25zdCBjb25zdGFudCA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZURlZmluaXRpb24pO1xuICAgIGlmIChjb25zdGFudClcbiAgICAgICAgcmV0dXJuIGRlZmF1bHRWYWx1ZXNCeVR5cGVbY29uc3RhbnRdO1xuICAgIGNvbnN0IGhhc0RlZmF1bHQgPSBoYXNQcm9wZXJ0eSh0eXBlRGVmaW5pdGlvbiwgXCJkZWZhdWx0XCIpO1xuICAgIGNvbnN0IGhhc1R5cGUgPSBoYXNQcm9wZXJ0eSh0eXBlRGVmaW5pdGlvbiwgXCJ0eXBlXCIpO1xuICAgIGNvbnN0IHR5cGVPYmplY3QgPSB0eXBlRGVmaW5pdGlvbjtcbiAgICBpZiAoaGFzRGVmYXVsdClcbiAgICAgICAgcmV0dXJuIHR5cGVPYmplY3QuZGVmYXVsdDtcbiAgICBpZiAoaGFzVHlwZSkge1xuICAgICAgICBjb25zdCB7IHR5cGUgfSA9IHR5cGVPYmplY3Q7XG4gICAgICAgIGNvbnN0IGNvbnN0YW50RnJvbVR5cGUgPSBwYXJzZVZhbHVlVHlwZUNvbnN0YW50KHR5cGUpO1xuICAgICAgICBpZiAoY29uc3RhbnRGcm9tVHlwZSlcbiAgICAgICAgICAgIHJldHVybiBkZWZhdWx0VmFsdWVzQnlUeXBlW2NvbnN0YW50RnJvbVR5cGVdO1xuICAgIH1cbiAgICByZXR1cm4gdHlwZURlZmluaXRpb247XG59XG5mdW5jdGlvbiB2YWx1ZURlc2NyaXB0b3JGb3JUb2tlbkFuZFR5cGVEZWZpbml0aW9uKHBheWxvYWQpIHtcbiAgICBjb25zdCB7IHRva2VuLCB0eXBlRGVmaW5pdGlvbiB9ID0gcGF5bG9hZDtcbiAgICBjb25zdCBrZXkgPSBgJHtkYXNoZXJpemUodG9rZW4pfS12YWx1ZWA7XG4gICAgY29uc3QgdHlwZSA9IHBhcnNlVmFsdWVUeXBlRGVmaW5pdGlvbihwYXlsb2FkKTtcbiAgICByZXR1cm4ge1xuICAgICAgICB0eXBlLFxuICAgICAgICBrZXksXG4gICAgICAgIG5hbWU6IGNhbWVsaXplKGtleSksXG4gICAgICAgIGdldCBkZWZhdWx0VmFsdWUoKSB7XG4gICAgICAgICAgICByZXR1cm4gZGVmYXVsdFZhbHVlRm9yRGVmaW5pdGlvbih0eXBlRGVmaW5pdGlvbik7XG4gICAgICAgIH0sXG4gICAgICAgIGdldCBoYXNDdXN0b21EZWZhdWx0VmFsdWUoKSB7XG4gICAgICAgICAgICByZXR1cm4gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KHR5cGVEZWZpbml0aW9uKSAhPT0gdW5kZWZpbmVkO1xuICAgICAgICB9LFxuICAgICAgICByZWFkZXI6IHJlYWRlcnNbdHlwZV0sXG4gICAgICAgIHdyaXRlcjogd3JpdGVyc1t0eXBlXSB8fCB3cml0ZXJzLmRlZmF1bHQsXG4gICAgfTtcbn1cbmNvbnN0IGRlZmF1bHRWYWx1ZXNCeVR5cGUgPSB7XG4gICAgZ2V0IGFycmF5KCkge1xuICAgICAgICByZXR1cm4gW107XG4gICAgfSxcbiAgICBib29sZWFuOiBmYWxzZSxcbiAgICBudW1iZXI6IDAsXG4gICAgZ2V0IG9iamVjdCgpIHtcbiAgICAgICAgcmV0dXJuIHt9O1xuICAgIH0sXG4gICAgc3RyaW5nOiBcIlwiLFxufTtcbmNvbnN0IHJlYWRlcnMgPSB7XG4gICAgYXJyYXkodmFsdWUpIHtcbiAgICAgICAgY29uc3QgYXJyYXkgPSBKU09OLnBhcnNlKHZhbHVlKTtcbiAgICAgICAgaWYgKCFBcnJheS5pc0FycmF5KGFycmF5KSkge1xuICAgICAgICAgICAgdGhyb3cgbmV3IFR5cGVFcnJvcihgZXhwZWN0ZWQgdmFsdWUgb2YgdHlwZSBcImFycmF5XCIgYnV0IGluc3RlYWQgZ290IHZhbHVlIFwiJHt2YWx1ZX1cIiBvZiB0eXBlIFwiJHtwYXJzZVZhbHVlVHlwZURlZmF1bHQoYXJyYXkpfVwiYCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGFycmF5O1xuICAgIH0sXG4gICAgYm9vbGVhbih2YWx1ZSkge1xuICAgICAgICByZXR1cm4gISh2YWx1ZSA9PSBcIjBcIiB8fCBTdHJpbmcodmFsdWUpLnRvTG93ZXJDYXNlKCkgPT0gXCJmYWxzZVwiKTtcbiAgICB9LFxuICAgIG51bWJlcih2YWx1ZSkge1xuICAgICAgICByZXR1cm4gTnVtYmVyKHZhbHVlLnJlcGxhY2UoL18vZywgXCJcIikpO1xuICAgIH0sXG4gICAgb2JqZWN0KHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IG9iamVjdCA9IEpTT04ucGFyc2UodmFsdWUpO1xuICAgICAgICBpZiAob2JqZWN0ID09PSBudWxsIHx8IHR5cGVvZiBvYmplY3QgIT0gXCJvYmplY3RcIiB8fCBBcnJheS5pc0FycmF5KG9iamVjdCkpIHtcbiAgICAgICAgICAgIHRocm93IG5ldyBUeXBlRXJyb3IoYGV4cGVjdGVkIHZhbHVlIG9mIHR5cGUgXCJvYmplY3RcIiBidXQgaW5zdGVhZCBnb3QgdmFsdWUgXCIke3ZhbHVlfVwiIG9mIHR5cGUgXCIke3BhcnNlVmFsdWVUeXBlRGVmYXVsdChvYmplY3QpfVwiYCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIG9iamVjdDtcbiAgICB9LFxuICAgIHN0cmluZyh2YWx1ZSkge1xuICAgICAgICByZXR1cm4gdmFsdWU7XG4gICAgfSxcbn07XG5jb25zdCB3cml0ZXJzID0ge1xuICAgIGRlZmF1bHQ6IHdyaXRlU3RyaW5nLFxuICAgIGFycmF5OiB3cml0ZUpTT04sXG4gICAgb2JqZWN0OiB3cml0ZUpTT04sXG59O1xuZnVuY3Rpb24gd3JpdGVKU09OKHZhbHVlKSB7XG4gICAgcmV0dXJuIEpTT04uc3RyaW5naWZ5KHZhbHVlKTtcbn1cbmZ1bmN0aW9uIHdyaXRlU3RyaW5nKHZhbHVlKSB7XG4gICAgcmV0dXJuIGAke3ZhbHVlfWA7XG59XG5cbmNsYXNzIENvbnRyb2xsZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICB9XG4gICAgc3RhdGljIGdldCBzaG91bGRMb2FkKCkge1xuICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICB9XG4gICAgc3RhdGljIGFmdGVyTG9hZChfaWRlbnRpZmllciwgX2FwcGxpY2F0aW9uKSB7XG4gICAgICAgIHJldHVybjtcbiAgICB9XG4gICAgZ2V0IGFwcGxpY2F0aW9uKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgc2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NvcGU7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHRhcmdldHMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLnRhcmdldHM7XG4gICAgfVxuICAgIGdldCBvdXRsZXRzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5vdXRsZXRzO1xuICAgIH1cbiAgICBnZXQgY2xhc3NlcygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuY2xhc3NlcztcbiAgICB9XG4gICAgZ2V0IGRhdGEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmRhdGE7XG4gICAgfVxuICAgIGluaXRpYWxpemUoKSB7XG4gICAgfVxuICAgIGNvbm5lY3QoKSB7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3QoKSB7XG4gICAgfVxuICAgIGRpc3BhdGNoKGV2ZW50TmFtZSwgeyB0YXJnZXQgPSB0aGlzLmVsZW1lbnQsIGRldGFpbCA9IHt9LCBwcmVmaXggPSB0aGlzLmlkZW50aWZpZXIsIGJ1YmJsZXMgPSB0cnVlLCBjYW5jZWxhYmxlID0gdHJ1ZSwgfSA9IHt9KSB7XG4gICAgICAgIGNvbnN0IHR5cGUgPSBwcmVmaXggPyBgJHtwcmVmaXh9OiR7ZXZlbnROYW1lfWAgOiBldmVudE5hbWU7XG4gICAgICAgIGNvbnN0IGV2ZW50ID0gbmV3IEN1c3RvbUV2ZW50KHR5cGUsIHsgZGV0YWlsLCBidWJibGVzLCBjYW5jZWxhYmxlIH0pO1xuICAgICAgICB0YXJnZXQuZGlzcGF0Y2hFdmVudChldmVudCk7XG4gICAgICAgIHJldHVybiBldmVudDtcbiAgICB9XG59XG5Db250cm9sbGVyLmJsZXNzaW5ncyA9IFtcbiAgICBDbGFzc1Byb3BlcnRpZXNCbGVzc2luZyxcbiAgICBUYXJnZXRQcm9wZXJ0aWVzQmxlc3NpbmcsXG4gICAgVmFsdWVQcm9wZXJ0aWVzQmxlc3NpbmcsXG4gICAgT3V0bGV0UHJvcGVydGllc0JsZXNzaW5nLFxuXTtcbkNvbnRyb2xsZXIudGFyZ2V0cyA9IFtdO1xuQ29udHJvbGxlci5vdXRsZXRzID0gW107XG5Db250cm9sbGVyLnZhbHVlcyA9IHt9O1xuXG5leHBvcnQgeyBBcHBsaWNhdGlvbiwgQXR0cmlidXRlT2JzZXJ2ZXIsIENvbnRleHQsIENvbnRyb2xsZXIsIEVsZW1lbnRPYnNlcnZlciwgSW5kZXhlZE11bHRpbWFwLCBNdWx0aW1hcCwgU2VsZWN0b3JPYnNlcnZlciwgU3RyaW5nTWFwT2JzZXJ2ZXIsIFRva2VuTGlzdE9ic2VydmVyLCBWYWx1ZUxpc3RPYnNlcnZlciwgYWRkLCBkZWZhdWx0U2NoZW1hLCBkZWwsIGZldGNoLCBwcnVuZSB9O1xuIiwgIi8qKlxuICogU3RyaXBlIE1vZHVsZSBcdTIwMTQgc2hhcmVkIGRlYnVnIGxvZ2dlciB1dGlsaXR5LlxuICpcbiAqIFBoYXNlIDU6IGJ1aWxkLXN0cmlwICsgcnVudGltZSB3cmFwcGVyIChkZWZlbnNlIGluIGRlcHRoKS5cbiAqXG4gKiBTRVRUTEVEIE1FQ0hBTklTTVxuICogLS0tLS0tLS0tLS0tLS0tLS1cbiAqIDEuIFByb2R1Y3Rpb24gZXNidWlsZCBhZGRzIGBwdXJlOiBbJ2NvbnNvbGUubG9nJywgJ2NvbnNvbGUuaW5mbycsXG4gKiAgICAnY29uc29sZS5kZWJ1ZycsICdjb25zb2xlLndhcm4nLCAnY29uc29sZS50cmFjZSddYCBzbyBzdHJheSBsaXRlcmFsXG4gKiAgICBjb25zb2xlLiogY2FsbHMgaW4gY29udHJvbGxlciBzb3VyY2UgZmlsZXMgYXJlIHN0cmlwcGVkIGZyb20gdGhlXG4gKiAgICBtaW5pZmllZCBidW5kbGUgKHRoZWlyIHJldHVybiB2YWx1ZSBpcyB1bnVzZWQgXHUyMDE0IGEgXCJwdXJlXCIgY2FsbCkuXG4gKiAgICBgY29uc29sZS5lcnJvcmAgaXMgTk9UIGluIHRoZSBwdXJlIGxpc3QgYW5kIGlzIE5FVkVSIHN0cmlwcGVkLlxuICpcbiAqIDIuIFRoZSBgY3JlYXRlRGVidWdMb2dnZXJgIHdyYXBwZXIgYmVsb3cgcm91dGVzIGludGVudGlvbmFsIGRpYWdub3N0aWNcbiAqICAgIGxvZ3MgdGhyb3VnaCBgY29uc29sZVJlZi5sb2coLi4uKWAgd2hlcmUgYGNvbnNvbGVSZWZgIGlzIGFuIGFsaWFzZWRcbiAqICAgIHJlZmVyZW5jZSBjYXB0dXJlZCBhdCBtb2R1bGUgaW5pdCAoYGNvbnN0IGNvbnNvbGVSZWYgPSBnbG9iYWxUaGlzLmNvbnNvbGVgKS5cbiAqICAgIEJlY2F1c2UgdGhlIGNhbGwgc2l0ZSBpcyBgY29uc29sZVJlZi5sb2coLi4uKWAgXHUyMDE0IE5PVCB0aGUgbGl0ZXJhbCBzdHJpbmdcbiAqICAgIGBjb25zb2xlLmxvZyguLi4pYCBcdTIwMTQgZXNidWlsZCdzIHB1cmUgcGFzcyBjYW5ub3Qgc3RhdGljYWxseSBtYXRjaCBpdCBhbmRcbiAqICAgIHdpbGwgTk9UIHN0cmlwIHRoZSBjYWxsLiBUaGUgcnVudGltZSBndWFyZCAoZW5hYmxlZCBmbGFnKSBkZWNpZGVzIHdoZXRoZXJcbiAqICAgIGl0IGZpcmVzLCBnaXZpbmcgbGl2ZSBvcHQtaW4gZGlhZ25vc3RpY3Mgd2l0aG91dCByZWRlcGxveWluZy5cbiAqXG4gKiAzLiBHZW51aW5lIGBjb25zb2xlLmVycm9yKC4uLilgIGNhbGxzIHJlbWFpbiBhcyBkaXJlY3QgbGl0ZXJhbHMgaW4gY2FsbGVycy5cbiAqICAgIFRoZXkgYXJlIHVuY29uZGl0aW9uYWwgYW5kIGFsd2F5cyBwcmVzZW50IGluIGV2ZXJ5IGJ1aWxkLlxuICpcbiAqIFVTQUdFXG4gKiAtLS0tLVxuICogSW4gYSBTdGltdWx1cyBjb250cm9sbGVyIHRoYXQgcmVnaXN0ZXJzIGEgYHN0cmlwZURlYnVnYCBCb29sZWFuIHZhbHVlOlxuICpcbiAqICAgaW1wb3J0IHsgY3JlYXRlRGVidWdMb2dnZXIgfSBmcm9tICcuLi9kZWJ1Zy5qcydcbiAqICAgLy8gLi4uXG4gKiAgIGNvbm5lY3QoKSB7XG4gKiAgICAgdGhpcy5fZGVidWcgPSBjcmVhdGVEZWJ1Z0xvZ2dlcigoKSA9PiB0aGlzLnN0cmlwZURlYnVnVmFsdWUpXG4gKiAgICAgdGhpcy5fZGVidWcoJ2NvbnRyb2xsZXIgY29ubmVjdGVkJywgeyBrZXk6IHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSB9KVxuICogICB9XG4gKi9cblxuLy8gQWxpYXMgY29uc29sZSBzbyBlc2J1aWxkIGBwdXJlYCBjYW5ub3Qgc3RhdGljYWxseSBtYXRjaCB0aGUgY2FsbCBzaXRlLlxuLy8gSU1QT1JUQU5UOiBrZWVwIHRoaXMgYXMgYGdsb2JhbFRoaXMuY29uc29sZWAgKE5PVCB0aGUgbGl0ZXJhbCBgY29uc29sZWApXG4vLyBzbyB0aGF0IHRoZSBtaW5pZmllciBsZWF2ZXMgdGhlIGNhbGwgaW4gcGxhY2UgZm9yIHRoZSBydW50aW1lIGZsYWcgdG8gd29yay5cbmNvbnN0IGNvbnNvbGVSZWYgPSBnbG9iYWxUaGlzLmNvbnNvbGVcblxuLyoqXG4gKiBGYWN0b3J5IHRoYXQgcmV0dXJucyBhIGRlYnVnIGxvZyBmdW5jdGlvbiBnYXRlZCBieSB0aGUgc3VwcGxpZWQgZmxhZyBnZXR0ZXIuXG4gKlxuICogQHBhcmFtIHsoKSA9PiBib29sZWFufSBpc0VuYWJsZWQgLSBSZXR1cm5zIHRydWUgd2hlbiBkZWJ1ZyBvdXRwdXQgaXMgd2FudGVkLlxuICogQHJldHVybnMgeyguLi5hcmdzOiB1bmtub3duW10pID0+IHZvaWR9XG4gKi9cbmV4cG9ydCBmdW5jdGlvbiBjcmVhdGVEZWJ1Z0xvZ2dlcihpc0VuYWJsZWQpIHtcbiAgcmV0dXJuIGZ1bmN0aW9uIGRlYnVnKC4uLmFyZ3MpIHtcbiAgICBpZiAoIWlzRW5hYmxlZCgpKSB7XG4gICAgICByZXR1cm5cbiAgICB9XG4gICAgLy8gVXNlIGFsaWFzZWQgcmVmZXJlbmNlIFx1MjAxNCBOT1QgYSBsaXRlcmFsIGBjb25zb2xlLmxvZyguLi4pYCBcdTIwMTQgc28gdGhlXG4gICAgLy8gcHJvZHVjdGlvbiBlc2J1aWxkIGBwdXJlYCBwYXNzIGNhbm5vdCBzdHJpcCB0aGlzIGNhbGwuXG4gICAgY29uc29sZVJlZi5sb2coLi4uYXJncylcbiAgfVxufVxuIiwgImltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tIFwiQGhvdHdpcmVkL3N0aW11bHVzXCJcbmltcG9ydCB7IGNyZWF0ZURlYnVnTG9nZ2VyIH0gZnJvbSAnLi4vZGVidWcuanMnXG5cbi8qKlxuICogU3RpbXVsdXMgQ29udHJvbGxlciBmb3IgU3RyaXBlIFBheW1lbnQgRWxlbWVudCBvbiBPcmRlciBQYWdlXG4gKlxuICogSGFuZGxlcyBTdHJpcGUgcGF5bWVudCBmb3JtIGluaXRpYWxpemF0aW9uIGFuZCBzdWJtaXNzaW9uIG9uIHRoZSBvcmRlciBjb25maXJtYXRpb24gcGFnZVxuICpcbiAqIFVzYWdlIGluIFR3aWc6XG4gKiA8ZGl2IGRhdGEtY29udHJvbGxlcj1cInN0cmlwZS1vcmRlclwiXG4gKiAgICAgIGRhdGEtc3RyaXBlLW9yZGVyLXB1Ymxpc2hhYmxlLWtleS12YWx1ZT1cInBrXy4uLlwiXG4gKiAgICAgIGRhdGEtc3RyaXBlLW9yZGVyLWNsaWVudC1zZWNyZXQtdmFsdWU9XCJwaV8uLi5fc2VjcmV0Xy4uLlwiXG4gKiAgICAgIGRhdGEtc3RyaXBlLW9yZGVyLXN0cmlwZS1kZWJ1Zy12YWx1ZT1cImZhbHNlXCI+XG4gKiAgIDxkaXYgaWQ9XCJwYXltZW50LWVsZW1lbnRcIj48L2Rpdj5cbiAqICAgPGRpdiBpZD1cInBheW1lbnQtZXJyb3JzXCIgc3R5bGU9XCJkaXNwbGF5Om5vbmVcIj5cbiAqICAgICA8c3BhbiBkYXRhLXN0cmlwZS1vcmRlci10YXJnZXQ9XCJlcnJvck1lc3NhZ2VcIj48L3NwYW4+XG4gKiAgIDwvZGl2PlxuICogPC9kaXY+XG4gKlxuICogUGhhc2UgNTogc3RyaXBlRGVidWcgU3RpbXVsdXMgdmFsdWUgZHJpdmVzIHRoZSBzaGFyZWQgZGVidWcoKSBsb2dnZXIuXG4gKiBXaGVuIGZhbHNlIChwcm9kdWN0aW9uIGRlZmF1bHQpLCBhbGwgZGVidWcoKSBjYWxscyBhcmUgbm8tb3BzLlxuICogV2hlbiB0cnVlIChsZXZlbD1kZWJ1ZyBpbiBhZG1pbiksIGNvbnNvbGUgb3V0cHV0IGlzIGVuYWJsZWQgYXQgcnVudGltZS5cbiAqL1xuZXhwb3J0IGRlZmF1bHQgY2xhc3MgZXh0ZW5kcyBDb250cm9sbGVyIHtcbiAgc3RhdGljIHZhbHVlcyA9IHtcbiAgICBwdWJsaXNoYWJsZUtleTogU3RyaW5nLFxuICAgIGNsaWVudFNlY3JldDogU3RyaW5nLFxuICAgIHN0cmlwZURlYnVnOiB7IHR5cGU6IEJvb2xlYW4sIGRlZmF1bHQ6IGZhbHNlIH1cbiAgfVxuXG4gIHN0YXRpYyB0YXJnZXRzID0gW1wiZXJyb3JNZXNzYWdlXCIsIFwibG9hZGluZ1wiXVxuXG4gIGNvbm5lY3QoKSB7XG4gICAgdGhpcy5fZGVidWcgPSBjcmVhdGVEZWJ1Z0xvZ2dlcigoKSA9PiB0aGlzLnN0cmlwZURlYnVnVmFsdWUpXG5cbiAgICB0aGlzLl9kZWJ1ZygnU3RyaXBlIE9yZGVyIGNvbnRyb2xsZXIgY29ubmVjdGVkJywge1xuICAgICAgaGFzUHVibGlzaGFibGVLZXk6ICEhdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlLFxuICAgICAgcHVibGlzaGFibGVLZXk6IHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSA/IHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZS5zdWJzdHJpbmcoMCwgMTApICsgJy4uLicgOiAnbWlzc2luZycsXG4gICAgfSlcblxuICAgIC8vIEdldCBkZWJ1ZyBpbmZvIGZyb20gZWxlbWVudFxuICAgIGNvbnN0IGRlYnVnSW5mbyA9IHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUoJ2RhdGEtZGVidWctaW5mbycpXG4gICAgaWYgKGRlYnVnSW5mbykge1xuICAgICAgdGhpcy5fZGVidWcoJ0RlYnVnIGluZm86JywgZGVidWdJbmZvKVxuICAgIH1cblxuICAgIC8vIFZhbGlkYXRlIHJlcXVpcmVkIGNvbmZpZ3VyYXRpb25cbiAgICBpZiAoIXRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSkge1xuICAgICAgY29uc29sZS5lcnJvcignU3RyaXBlIHB1Ymxpc2hhYmxlIGtleSBub3QgY29uZmlndXJlZCcpXG4gICAgICB0aGlzLnNob3dFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uQ09ORklHX0VSUk9SIHx8ICdTdHJpcGUgY29uZmlndXJhdGlvbiBlcnJvci4gUGxlYXNlIGNvbnRhY3Qgc3VwcG9ydC4nKVxuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgLy8gV2FpdCBmb3IgU3RyaXBlLmpzIHRvIGxvYWRcbiAgICB0aGlzLmluaXRpYWxpemVTdHJpcGUoKVxuICB9XG5cbiAgZGlzY29ubmVjdCgpIHtcbiAgICAvLyBDbGVhbnVwIGlmIG5lZWRlZFxuICAgIGlmICh0aGlzLnBheW1lbnRFbGVtZW50KSB7XG4gICAgICB0aGlzLnBheW1lbnRFbGVtZW50LnVubW91bnQoKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBJbml0aWFsaXplIFN0cmlwZSBhbmQgbW91bnQgUGF5bWVudCBFbGVtZW50XG4gICAqL1xuICBhc3luYyBpbml0aWFsaXplU3RyaXBlKCkge1xuICAgIC8vIFdhaXQgZm9yIFN0cmlwZS5qcyB0byBiZSBhdmFpbGFibGVcbiAgICBpZiAodHlwZW9mIFN0cmlwZSA9PT0gJ3VuZGVmaW5lZCcpIHtcbiAgICAgIHRoaXMuX2RlYnVnKCdXYWl0aW5nIGZvciBTdHJpcGUuanMgdG8gbG9hZC4uLicpXG4gICAgICBhd2FpdCB0aGlzLndhaXRGb3JTdHJpcGUoKVxuICAgIH1cblxuICAgIHRyeSB7XG4gICAgICAvLyBJbml0aWFsaXplIFN0cmlwZVxuICAgICAgdGhpcy5zdHJpcGUgPSBTdHJpcGUodGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlKVxuXG4gICAgICAvLyBDcmVhdGUgRWxlbWVudHMgd2l0aCBzdHlsaW5nXG4gICAgICBjb25zdCBhcHBlYXJhbmNlID0ge1xuICAgICAgICB0aGVtZTogJ3N0cmlwZScsXG4gICAgICAgIHZhcmlhYmxlczoge1xuICAgICAgICAgIGNvbG9yUHJpbWFyeTogJyMwNTcwZGUnLFxuICAgICAgICAgIGNvbG9yQmFja2dyb3VuZDogJyNmZmZmZmYnLFxuICAgICAgICAgIGNvbG9yVGV4dDogJyMzMDMxM2QnLFxuICAgICAgICAgIGZvbnRGYW1pbHk6ICdzeXN0ZW0tdWksIHNhbnMtc2VyaWYnLFxuICAgICAgICAgIGJvcmRlclJhZGl1czogJzRweCdcbiAgICAgICAgfVxuICAgICAgfVxuXG4gICAgICB0aGlzLmVsZW1lbnRzID0gdGhpcy5zdHJpcGUuZWxlbWVudHMoe1xuICAgICAgICBhcHBlYXJhbmNlOiBhcHBlYXJhbmNlXG4gICAgICB9KVxuXG4gICAgICB0aGlzLmNhcmQgPSB0aGlzLmVsZW1lbnRzLmNyZWF0ZSgnY2FyZCcpO1xuICAgICAgdGhpcy5jYXJkLm1vdW50KCcjY2FyZC1lbGVtZW50Jyk7XG5cbiAgICAgIHRoaXMuX2RlYnVnKCdTdHJpcGUgUGF5bWVudCBFbGVtZW50IGluaXRpYWxpemVkIHN1Y2Nlc3NmdWxseScpXG5cbiAgICB9IGNhdGNoIChlcnJvcikge1xuICAgICAgY29uc29sZS5lcnJvcignRmFpbGVkIHRvIGluaXRpYWxpemUgU3RyaXBlOicsIGVycm9yKVxuICAgICAgdGhpcy5zaG93RXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LklOSVRfRkFJTEVEIHx8ICdGYWlsZWQgdG8gaW5pdGlhbGl6ZSBwYXltZW50IGZvcm0uIFBsZWFzZSByZWZyZXNoIHRoZSBwYWdlLicpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFdhaXQgZm9yIFN0cmlwZS5qcyBsaWJyYXJ5IHRvIGxvYWRcbiAgICogQHJldHVybnMge1Byb21pc2V9XG4gICAqL1xuICB3YWl0Rm9yU3RyaXBlKCkge1xuICAgIHJldHVybiBuZXcgUHJvbWlzZSgocmVzb2x2ZSkgPT4ge1xuICAgICAgY29uc3QgY2hlY2tTdHJpcGUgPSAoKSA9PiB7XG4gICAgICAgIGlmICh0eXBlb2YgU3RyaXBlICE9PSAndW5kZWZpbmVkJykge1xuICAgICAgICAgIHJlc29sdmUoKVxuICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgIHNldFRpbWVvdXQoY2hlY2tTdHJpcGUsIDEwMClcbiAgICAgICAgfVxuICAgICAgfVxuICAgICAgY2hlY2tTdHJpcGUoKVxuICAgIH0pXG4gIH1cblxuICAvKipcbiAgICogU2hvdyBsb2FkaW5nIGluZGljYXRvclxuICAgKi9cbiAgc2hvd0xvYWRpbmcoKSB7XG4gICAgaWYgKHRoaXMuaGFzTG9hZGluZ1RhcmdldCkge1xuICAgICAgdGhpcy5sb2FkaW5nVGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFNob3cgZXJyb3IgbWVzc2FnZVxuICAgKiBAcGFyYW0ge1N0cmluZ30gbWVzc2FnZVxuICAgKi9cbiAgc2hvd0Vycm9yKG1lc3NhZ2UpIHtcbiAgICBjb25zdCBlcnJvckRpdiA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdwYXltZW50LWVycm9ycycpXG4gICAgaWYgKGVycm9yRGl2ICYmIHRoaXMuaGFzRXJyb3JNZXNzYWdlVGFyZ2V0KSB7XG4gICAgICBlcnJvckRpdi5zdHlsZS5kaXNwbGF5ID0gJ2Jsb2NrJ1xuICAgICAgdGhpcy5lcnJvck1lc3NhZ2VUYXJnZXQudGV4dENvbnRlbnQgPSBtZXNzYWdlXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhpZGUgZXJyb3IgbWVzc2FnZVxuICAgKi9cbiAgaGlkZUVycm9yKCkge1xuICAgIGNvbnN0IGVycm9yRGl2ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ3BheW1lbnQtZXJyb3JzJylcbiAgICBpZiAoZXJyb3JEaXYpIHtcbiAgICAgIGVycm9yRGl2LnN0eWxlLmRpc3BsYXkgPSAnbm9uZSdcbiAgICAgIGlmICh0aGlzLmhhc0Vycm9yTWVzc2FnZVRhcmdldCkge1xuICAgICAgICB0aGlzLmVycm9yTWVzc2FnZVRhcmdldC50ZXh0Q29udGVudCA9ICcnXG4gICAgICB9XG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhpZGUgbG9hZGluZyBpbmRpY2F0b3JcbiAgICovXG4gIGhpZGVMb2FkaW5nKCkge1xuICAgIGlmICh0aGlzLmhhc0xvYWRpbmdUYXJnZXQpIHtcbiAgICAgIHRoaXMubG9hZGluZ1RhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ25vbmUnXG4gICAgfVxuICB9XG5cbn1cbiIsICJpbXBvcnQgeyBDb250cm9sbGVyIH0gZnJvbSBcIkBob3R3aXJlZC9zdGltdWx1c1wiXG5pbXBvcnQgeyBjcmVhdGVEZWJ1Z0xvZ2dlciB9IGZyb20gJy4uL2RlYnVnLmpzJ1xuXG4vKipcbiAqIFN0aW11bHVzIENvbnRyb2xsZXIgZm9yIE9yZGVyIFN1Ym1pdCBCdXR0b25cbiAqXG4gKiBIYW5kbGVzIG9yZGVyIHN1Ym1pc3Npb24gb24gdGhlIGNoZWNrb3V0IG9yZGVyIHBhZ2UuXG4gKiBTdXBwb3J0cyB0d28gcGF5bWVudCBmbG93czpcbiAqIDEuIFN0cmlwZSBDaGVja291dCAoaG9zdGVkIHBhZ2UpIC0gZm9yIHdhbGxldCBwYXltZW50c1xuICogMi4gUGF5bWVudCBJbnRlbnQgKGNhcmQgZWxlbWVudCkgLSBmb3IgY2FyZCBwYXltZW50c1xuICpcbiAqIFVzYWdlIGluIFR3aWc6XG4gKiA8YnV0dG9uIGRhdGEtY29udHJvbGxlcj1cIm9yZGVyLXN1Ym1pdFwiXG4gKiAgICAgICAgIGRhdGEtYWN0aW9uPVwiY2xpY2stPm9yZGVyLXN1Ym1pdCNoYW5kbGVTdWJtaXRcIlxuICogICAgICAgICBkYXRhLW9yZGVyLXN1Ym1pdC11cmwtdmFsdWU9XCIuLi5cIlxuICogICAgICAgICBkYXRhLW9yZGVyLXN1Ym1pdC1wYXltZW50LXR5cGUtdmFsdWU9XCJ3YWxsZXR8Y2FyZFwiXG4gKiAgICAgICAgIGRhdGEtb3JkZXItc3VibWl0LXN0cmlwZS1kZWJ1Zy12YWx1ZT1cImZhbHNlXCJcbiAqICAgICAgICAgdHlwZT1cImJ1dHRvblwiPlxuICogICBTdWJtaXQgT3JkZXJcbiAqIDwvYnV0dG9uPlxuICpcbiAqIFBoYXNlIDU6IHN0cmlwZURlYnVnIFN0aW11bHVzIHZhbHVlIGRyaXZlcyB0aGUgc2hhcmVkIGRlYnVnKCkgbG9nZ2VyLlxuICogV2hlbiBmYWxzZSAocHJvZHVjdGlvbiBkZWZhdWx0KSwgYWxsIGRlYnVnKCkgY2FsbHMgYXJlIG5vLW9wcy5cbiAqIFdoZW4gdHJ1ZSAobGV2ZWw9ZGVidWcgaW4gYWRtaW4pLCBjb25zb2xlIG91dHB1dCBpcyBlbmFibGVkIGF0IHJ1bnRpbWUuXG4gKi9cbmV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgQ29udHJvbGxlciB7XG4gIHN0YXRpYyB0YXJnZXRzID0gW1wic3RhdHVzXCJdXG4gIHN0YXRpYyB2YWx1ZXMgPSB7XG4gICAgdXJsOiBTdHJpbmcsXG4gICAgcGF5bWVudFR5cGU6IFN0cmluZyxcbiAgICBwdWJsaXNoYWJsZUtleTogU3RyaW5nLFxuICAgIHN0cmlwZURlYnVnOiB7IHR5cGU6IEJvb2xlYW4sIGRlZmF1bHQ6IGZhbHNlIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBDYWxsZWQgd2hlbiBjb250cm9sbGVyIGlzIGNvbm5lY3RlZCB0byBET00uXG4gICAqXG4gICAqIFNwcmludCAxMjI6IFJlZ2lzdGVyIGEgcGFnZXNob3cgbGlzdGVuZXIgc28gdGhhdCB3aGVuIHRoZSBicm93c2VyIHJlc3RvcmVzXG4gICAqIHRoaXMgcGFnZSBmcm9tIHRoZSBiYWNrLWZvcndhcmQgY2FjaGUgKGJmY2FjaGUpIGFmdGVyIGEgU3RyaXBlIHJlZGlyZWN0LFxuICAgKiBoaWRlTG9hZGluZygpIGNsZWFycyB0aGUgZnJvemVuIG1pZC1zdWJtaXQgc3RhdGUgYW5kIGRpc3BhdGNoZXNcbiAgICogJ29lOnN0cmlwZTpzdWJtaXQtZW5kJyBcdTIwMTQgYWxsb3dpbmcgYWdiLXZhbGlkYXRpb24gdG8gcmVjb21wdXRlIHRoZSByZXN0aW5nXG4gICAqIGJ1dHRvbiBzdGF0ZSBhcyB0aGUgYXV0aG9yaXRhdGl2ZSBsYXN0IHN0ZXAgKHNlZSBzcHJpbnQgcGxhbiBcdTAwQTc0LjIpLlxuICAgKi9cbiAgY29ubmVjdCgpIHtcbiAgICB0aGlzLl9kZWJ1ZyA9IGNyZWF0ZURlYnVnTG9nZ2VyKCgpID0+IHRoaXMuc3RyaXBlRGVidWdWYWx1ZSlcblxuICAgIHRoaXMuX2RlYnVnKCdPcmRlciBTdWJtaXQgY29udHJvbGxlciBjb25uZWN0ZWQnKVxuICAgIHRoaXMuX2RlYnVnKCdCdXR0b24gZWxlbWVudDonLCB0aGlzLmVsZW1lbnQpXG5cbiAgICB0aGlzLl9vblBhZ2VTaG93ID0gKGUpID0+IHsgaWYgKGUucGVyc2lzdGVkKSB0aGlzLmhpZGVMb2FkaW5nKCkgfVxuICAgIHdpbmRvdy5hZGRFdmVudExpc3RlbmVyKCdwYWdlc2hvdycsIHRoaXMuX29uUGFnZVNob3cpXG4gIH1cblxuICAvKipcbiAgICogQ2FsbGVkIHdoZW4gY29udHJvbGxlciBpcyBkaXNjb25uZWN0ZWQgZnJvbSBET00uXG4gICAqXG4gICAqIFNwcmludCAxMjI6IFJlbW92ZSB0aGUgcGFnZXNob3cgbGlzdGVuZXIgdXNpbmcgdGhlIGV4YWN0IHNhbWUgYm91bmRcbiAgICogcmVmZXJlbmNlIHN0b3JlZCBpbiBjb25uZWN0KCkgXHUyMDE0IHN5bW1ldHJpYywgbGVhay1mcmVlLlxuICAgKi9cbiAgZGlzY29ubmVjdCgpIHtcbiAgICB0aGlzLl9kZWJ1ZygnT3JkZXIgU3VibWl0IGNvbnRyb2xsZXIgZGlzY29ubmVjdGVkJylcblxuICAgIHdpbmRvdy5yZW1vdmVFdmVudExpc3RlbmVyKCdwYWdlc2hvdycsIHRoaXMuX29uUGFnZVNob3cpXG4gIH1cblxuICAvKipcbiAgICogR2V0IHRoZSBzdHJpcGUtb3JkZXIgY29udHJvbGxlciBpbnN0YW5jZVxuICAgKiBAcmV0dXJucyB7Q29udHJvbGxlcnxudWxsfVxuICAgKi9cbiAgZ2V0U3RyaXBlT3JkZXJDb250cm9sbGVyKCkge1xuICAgIGNvbnN0IGNhcmRFbGVtZW50ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ2NhcmQtZWxlbWVudCcpXG4gICAgaWYgKCFjYXJkRWxlbWVudCkge1xuICAgICAgY29uc29sZS5lcnJvcignQ2FyZCBlbGVtZW50IG5vdCBmb3VuZCcpXG4gICAgICByZXR1cm4gbnVsbFxuICAgIH1cblxuICAgIGNvbnN0IGNvbnRyb2xsZXIgPSB0aGlzLmFwcGxpY2F0aW9uLmdldENvbnRyb2xsZXJGb3JFbGVtZW50QW5kSWRlbnRpZmllcihcbiAgICAgIGNhcmRFbGVtZW50LFxuICAgICAgJ3N0cmlwZS1vcmRlcidcbiAgICApXG5cbiAgICBpZiAoIWNvbnRyb2xsZXIpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ1N0cmlwZSBvcmRlciBjb250cm9sbGVyIG5vdCBmb3VuZCBvbiBjYXJkIGVsZW1lbnQnKVxuICAgICAgcmV0dXJuIG51bGxcbiAgICB9XG5cbiAgICB0aGlzLl9kZWJ1ZygnRm91bmQgc3RyaXBlLW9yZGVyIGNvbnRyb2xsZXI6JywgY29udHJvbGxlcilcbiAgICByZXR1cm4gY29udHJvbGxlclxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBvcmRlciBzdWJtaXQgYnV0dG9uIGNsaWNrXG4gICAqIFJvdXRlcyB0byBhcHByb3ByaWF0ZSBwYXltZW50IGZsb3cgYmFzZWQgb24gcGF5bWVudCB0eXBlXG4gICAqIEBwYXJhbSB7RXZlbnR9IGV2ZW50IC0gVGhlIGNsaWNrIGV2ZW50XG4gICAqL1xuICBhc3luYyBoYW5kbGVTdWJtaXQoZXZlbnQpIHtcbiAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpXG5cbiAgICB0aGlzLl9kZWJ1ZygnT3JkZXIgc3VibWl0IGJ1dHRvbiBjbGlja2VkJywge1xuICAgICAgYnV0dG9uSWQ6IHRoaXMuZWxlbWVudC5pZCxcbiAgICAgIHBheW1lbnRUeXBlOiB0aGlzLnBheW1lbnRUeXBlVmFsdWUsXG4gICAgICB0aW1lc3RhbXA6IG5ldyBEYXRlKCkudG9JU09TdHJpbmcoKVxuICAgIH0pXG5cbiAgICB0aGlzLnNob3dMb2FkaW5nKClcblxuICAgIHRyeSB7XG4gICAgICAvLyBSb3V0ZSB0byBhcHByb3ByaWF0ZSBwYXltZW50IGZsb3dcbiAgICAgIGlmICh0aGlzLnBheW1lbnRUeXBlVmFsdWUgPT09ICd3YWxsZXQnKSB7XG4gICAgICAgIGF3YWl0IHRoaXMuaGFuZGxlU3RyaXBlQ2hlY2tvdXQoKVxuICAgICAgfSBlbHNlIHtcbiAgICAgICAgYXdhaXQgdGhpcy5oYW5kbGVQYXltZW50SW50ZW50KClcbiAgICAgIH1cbiAgICB9IGNhdGNoIChlcnJvcikge1xuICAgICAgY29uc29sZS5lcnJvcignT3JkZXIgc3VibWlzc2lvbiBmYWlsZWQnLCBlcnJvcilcbiAgICAgIHRoaXMuc2hvd0Vycm9yKGVycm9yLm1lc3NhZ2UgfHwgd2luZG93Lm9TdHJpcGU/LmkxOG4/LlBBWU1FTlRfRkFJTEVEIHx8ICdQYXltZW50IHByb2Nlc3NpbmcgZmFpbGVkJylcbiAgICB9IGZpbmFsbHkge1xuICAgICAgdGhpcy5oaWRlTG9hZGluZygpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBTdHJpcGUgQ2hlY2tvdXQgZmxvdyAoaG9zdGVkIHBheW1lbnQgcGFnZSlcbiAgICogVXNlZCBmb3Igd2FsbGV0IHBheW1lbnRzIChBcHBsZSBQYXksIEdvb2dsZSBQYXkpXG4gICAqL1xuICBhc3luYyBoYW5kbGVTdHJpcGVDaGVja291dCgpIHtcbiAgICBpZiAoIXdpbmRvdy5TdHJpcGUpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uSlNfTk9UX0xPQURFRCB8fCAnU3RyaXBlLmpzIG5vdCBsb2FkZWQnKVxuICAgIH1cblxuICAgIC8vIEdldCBTdHJpcGUgcHVibGlzaGFibGUga2V5IGZyb20gU3RpbXVsdXMgdmFsdWVcbiAgICBpZiAoIXRoaXMuaGFzUHVibGlzaGFibGVLZXlWYWx1ZSB8fCAhdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LktFWV9OT1RfQ09ORklHVVJFRCB8fCAnU3RyaXBlIHB1Ymxpc2hhYmxlIGtleSBub3QgY29uZmlndXJlZCcpXG4gICAgfVxuXG4gICAgY29uc3Qgc3RyaXBlID0gU3RyaXBlKHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSlcblxuICAgIHRoaXMuc2V0U3RhdHVzKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5DUkVBVElOR19TRVNTSU9OIHx8ICdDcmVhdGluZyBjaGVja291dCBzZXNzaW9uLi4uJylcblxuICAgIC8vIENyZWF0ZSBDaGVja291dCBTZXNzaW9uIChpbmNsdWRlIHN0b2tlbiBmb3IgQ1NSRiBwcm90ZWN0aW9uKVxuICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2godGhpcy5hcHBlbmRBZ2JTdGF0ZSh0aGlzLmJ1aWxkVXJsV2l0aENzcmZUb2tlbih0aGlzLnVybFZhbHVlKSksIHtcbiAgICAgIG1ldGhvZDogJ1BPU1QnLFxuICAgICAgaGVhZGVyczoge1xuICAgICAgICAnQ29udGVudC1UeXBlJzogJ2FwcGxpY2F0aW9uL2pzb24nXG4gICAgICB9LFxuICAgICAgYm9keTogSlNPTi5zdHJpbmdpZnkoe1xuICAgICAgICBjYXB0dXJlOiAnYXV0b21hdGljJyAvLyBDYW4gYmUgbWFkZSBjb25maWd1cmFibGVcbiAgICAgIH0pLFxuICAgICAgY3JlZGVudGlhbHM6ICdzYW1lLW9yaWdpbidcbiAgICB9KVxuXG4gICAgaWYgKCFyZXNwb25zZS5vaykge1xuICAgICAgY29uc3QgZXJyb3JEYXRhID0gYXdhaXQgcmVzcG9uc2UuanNvbigpLmNhdGNoKCgpID0+ICh7fSkpXG4gICAgICAvLyBTVFJQLTEyOTogYSA0MjIgY2FycmllcyBwZXItZmllbGQgdmFsaWRhdGlvbiBtZXNzYWdlcyBpbiBgZXJyb3JzW11gLlxuICAgICAgLy8gUmVuZGVyIHRoZW0gaW4gdGhlIHN0YW5kYXJkIE9YSUQgcmVkIGVycm9yIGJveCAobm90IHRoZSBnZW5lcmljXG4gICAgICAvLyBmYWxsYmFjaykgc28gdGhlIHNob3BwZXIgc2VlcyB3aGljaCBmaWVsZCBpcyBpbnZhbGlkIGFuZCB3aGljaCBzeW1ib2xzXG4gICAgICAvLyBhcmUgYWxsb3dlZCwgdGhlbiBzdG9wIHRoZSBjaGVja291dCBmbG93LlxuICAgICAgY29uc3QgbWVzc2FnZXMgPSB0aGlzLmNvbGxlY3RWYWxpZGF0aW9uTWVzc2FnZXMoZXJyb3JEYXRhKVxuICAgICAgaWYgKG1lc3NhZ2VzLmxlbmd0aCkge1xuICAgICAgICB0aGlzLnJlbmRlckZpZWxkVmFsaWRhdGlvbkVycm9ycyhlcnJvckRhdGEuZXJyb3JzKVxuICAgICAgICB0aGlzLnNob3dWYWxpZGF0aW9uQm94KG1lc3NhZ2VzKVxuICAgICAgICByZXR1cm5cbiAgICAgIH1cbiAgICAgIHRocm93IG5ldyBFcnJvcihlcnJvckRhdGEuZXJyb3IgfHwgd2luZG93Lm9TdHJpcGU/LmkxOG4/LlNFU1NJT05fRkFJTEVEIHx8ICdGYWlsZWQgdG8gY3JlYXRlIGNoZWNrb3V0IHNlc3Npb24nKVxuICAgIH1cblxuICAgIGNvbnN0IGRhdGEgPSBhd2FpdCByZXNwb25zZS5qc29uKClcblxuICAgIGlmICghZGF0YS5pZCkge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5TRVNTSU9OX0lOVkFMSUQgfHwgJ0ludmFsaWQgY2hlY2tvdXQgc2Vzc2lvbiByZXNwb25zZScpXG4gICAgfVxuXG4gICAgdGhpcy5fZGVidWcoJ0NoZWNrb3V0IFNlc3Npb24gY3JlYXRlZDonLCBkYXRhLmlkLCAnVVJMOicsIGRhdGEudXJsKVxuICAgIHRoaXMuX2RlYnVnKCdEZWJ1ZyBpbmZvOicsIGRhdGEuX2RlYnVnKVxuXG4gICAgLy8gUmVkaXJlY3QgdG8gU3RyaXBlIENoZWNrb3V0IHVzaW5nIGRpcmVjdCBVUkwgKG1vcmUgcmVsaWFibGUpXG4gICAgaWYgKGRhdGEudXJsKSB7XG4gICAgICB3aW5kb3cubG9jYXRpb24uaHJlZiA9IGRhdGEudXJsXG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICAvLyBGYWxsYmFjayB0byByZWRpcmVjdFRvQ2hlY2tvdXQgaWYgVVJMIG5vdCBhdmFpbGFibGVcbiAgICBjb25zdCB7IGVycm9yIH0gPSBhd2FpdCBzdHJpcGUucmVkaXJlY3RUb0NoZWNrb3V0KHtcbiAgICAgIHNlc3Npb25JZDogZGF0YS5pZFxuICAgIH0pXG5cbiAgICBpZiAoZXJyb3IpIHtcbiAgICAgIHRocm93IGVycm9yXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBQYXltZW50IEludGVudCBmbG93IChjYXJkIGVsZW1lbnQpXG4gICAqIFVzZWQgZm9yIGNhcmQgcGF5bWVudHNcbiAgICovXG4gIGFzeW5jIGhhbmRsZVBheW1lbnRJbnRlbnQoKSB7XG4gICAgLy8gR2V0IHN0cmlwZS1vcmRlciBjb250cm9sbGVyIGluc3RhbmNlXG4gICAgY29uc3Qgc3RyaXBlT3JkZXJDb250cm9sbGVyID0gdGhpcy5nZXRTdHJpcGVPcmRlckNvbnRyb2xsZXIoKVxuXG4gICAgaWYgKCFzdHJpcGVPcmRlckNvbnRyb2xsZXIpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uQ09OVFJPTExFUl9OT1RfRk9VTkQgfHwgJ1N0cmlwZSBwYXltZW50IGNvbnRyb2xsZXIgbm90IGZvdW5kLiBQbGVhc2UgcmVmcmVzaCB0aGUgcGFnZS4nKVxuICAgIH1cblxuICAgIC8vIFZlcmlmeSBjYXJkIGVsZW1lbnQgYW5kIHN0cmlwZSBhcmUgYXZhaWxhYmxlXG4gICAgaWYgKCFzdHJpcGVPcmRlckNvbnRyb2xsZXIuY2FyZCB8fCAhc3RyaXBlT3JkZXJDb250cm9sbGVyLnN0cmlwZSkge1xuICAgICAgY29uc29sZS5lcnJvcignUGF5bWVudCBmb3JtIG5vdCByZWFkeTonLCB7XG4gICAgICAgIGhhc0NhcmQ6ICEhc3RyaXBlT3JkZXJDb250cm9sbGVyLmNhcmQsXG4gICAgICAgIGhhc1N0cmlwZTogISFzdHJpcGVPcmRlckNvbnRyb2xsZXIuc3RyaXBlXG4gICAgICB9KVxuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5GT1JNX05PVF9SRUFEWSB8fCAnUGF5bWVudCBmb3JtIG5vdCBpbml0aWFsaXplZC4gUGxlYXNlIHJlZnJlc2ggdGhlIHBhZ2UuJylcbiAgICB9XG5cbiAgICB0aGlzLl9kZWJ1ZygnU3RyaXBlIGNvbnRyb2xsZXIgcmVhZHk6Jywge1xuICAgICAgaGFzQ2FyZDogISFzdHJpcGVPcmRlckNvbnRyb2xsZXIuY2FyZCxcbiAgICAgIGhhc1N0cmlwZTogISFzdHJpcGVPcmRlckNvbnRyb2xsZXIuc3RyaXBlXG4gICAgfSlcblxuICAgIGNvbnN0IHBheW1lbnRJbnRlbnRSZXNwb25zZSA9IGF3YWl0IHRoaXMuaGFuZGxlUGF5bWVudCgpXG4gICAgY29uc3QgY2xpZW50U2VjcmV0ID0gcGF5bWVudEludGVudFJlc3BvbnNlLmNsaWVudFNlY3JldFxuXG4gICAgY29uc3QgY29uZmlybVBheW1lbnRSZXNwb25zZSA9IGF3YWl0IHN0cmlwZU9yZGVyQ29udHJvbGxlci5zdHJpcGUuY29uZmlybUNhcmRQYXltZW50KGNsaWVudFNlY3JldCwge1xuICAgICAgcGF5bWVudF9tZXRob2Q6IHtcbiAgICAgICAgY2FyZDogc3RyaXBlT3JkZXJDb250cm9sbGVyLmNhcmRcbiAgICAgIH1cbiAgICB9KTtcblxuICAgIGlmIChjb25maXJtUGF5bWVudFJlc3BvbnNlLmVycm9yKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IoY29uZmlybVBheW1lbnRSZXNwb25zZS5lcnJvci5tZXNzYWdlKVxuICAgIH0gZWxzZSBpZiAoY29uZmlybVBheW1lbnRSZXNwb25zZS5wYXltZW50SW50ZW50ICYmIGNvbmZpcm1QYXltZW50UmVzcG9uc2UucGF5bWVudEludGVudC5zdGF0dXMgPT09ICdzdWNjZWVkZWQnKSB7XG4gICAgICB0aGlzLl9kZWJ1ZygnUGF5bWVudCBzdWNjZWVkZWQnLCBjb25maXJtUGF5bWVudFJlc3BvbnNlLnBheW1lbnRJbnRlbnQpXG4gICAgICAvLyBUT0RPOiBTdWJtaXQgZmluYWwgb3JkZXIgdG8gYmFja2VuZFxuICAgIH0gZWxzZSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LlBBWU1FTlRfTk9UX0NPTVBMRVRFRCB8fCAnUGF5bWVudCBub3QgY29tcGxldGVkJylcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogRmV0Y2ggcGF5bWVudCBpbnRlbnQgY3JlYXRpb24gVVJMIGFuZCByZXR1cm4gcmVzcG9uc2VcbiAgICogQHJldHVybnMge1Byb21pc2U8T2JqZWN0Pn0gUGF5bWVudCBpbnRlbnQgcmVzcG9uc2Ugd2l0aCBjbGllbnRTZWNyZXQsIGFtb3VudCwgY3VycmVuY3lcbiAgICogQHRocm93cyB7RXJyb3J9IElmIGZldGNoIGZhaWxzIG9yIHJlc3BvbnNlIGlzIG5vdCBva1xuICAgKi9cbiAgYXN5bmMgaGFuZGxlUGF5bWVudCgpIHtcbiAgICBpZiAoIXRoaXMuaGFzVXJsVmFsdWUpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uVVJMX05PVF9DT05GSUdVUkVEIHx8ICdQYXltZW50IFVSTCBpcyBub3QgY29uZmlndXJlZCcpXG4gICAgfVxuXG4gICAgdGhpcy5fZGVidWcoJ0NyZWF0aW5nIHBheW1lbnQgaW50ZW50IHZpYSBVUkw6JywgdGhpcy51cmxWYWx1ZSlcblxuICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2godGhpcy5hcHBlbmRBZ2JTdGF0ZSh0aGlzLmJ1aWxkVXJsV2l0aENzcmZUb2tlbih0aGlzLnVybFZhbHVlKSksIHtcbiAgICAgIG1ldGhvZDogJ1BPU1QnLFxuICAgICAgaGVhZGVyczoge1xuICAgICAgICAnQ29udGVudC1UeXBlJzogJ2FwcGxpY2F0aW9uL2pzb24nXG4gICAgICB9LFxuICAgICAgY3JlZGVudGlhbHM6ICdzYW1lLW9yaWdpbidcbiAgICB9KVxuXG4gICAgaWYgKCFyZXNwb25zZS5vaykge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKGBIVFRQIGVycm9yISBzdGF0dXM6ICR7cmVzcG9uc2Uuc3RhdHVzfWApXG4gICAgfVxuXG4gICAgY29uc3QgcmVzcG9uc2VEYXRhID0gYXdhaXQgcmVzcG9uc2UuanNvbigpXG5cbiAgICBpZiAocmVzcG9uc2VEYXRhLmVycm9yKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IocmVzcG9uc2VEYXRhLmVycm9yKVxuICAgIH1cblxuICAgIGlmICghcmVzcG9uc2VEYXRhLnN1Y2Nlc3MgfHwgIXJlc3BvbnNlRGF0YS5jbGllbnRTZWNyZXQpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uSU5URU5UX0lOVkFMSUQgfHwgJ0ludmFsaWQgcGF5bWVudCBpbnRlbnQgcmVzcG9uc2UnKVxuICAgIH1cblxuICAgIHJldHVybiByZXNwb25zZURhdGFcbiAgfVxuXG4gIC8qKlxuICAgKiBBcHBlbmQgc3Rva2VuIChDU1JGIHRva2VuKSB0byBVUkwgZm9yIHNlc3Npb24gY2hhbGxlbmdlIHZhbGlkYXRpb24uXG4gICAqIE9YSUQgaW5jbHVkZXMgc3Rva2VuIGluIGZvcm1zIHZpYSBvVmlld0NvbmYuZ2V0U2Vzc2lvbkNoYWxsZW5nZVRva2VuKCkuXG4gICAqIEBwYXJhbSB7c3RyaW5nfSB1cmwgLSBUaGUgYmFzZSBVUkxcbiAgICogQHJldHVybnMge3N0cmluZ30gVVJMIHdpdGggc3Rva2VuIHBhcmFtZXRlciBhcHBlbmRlZFxuICAgKi9cbiAgYnVpbGRVcmxXaXRoQ3NyZlRva2VuKHVybCkge1xuICAgIGNvbnN0IHN0b2tlbiA9IGRvY3VtZW50LnF1ZXJ5U2VsZWN0b3IoJ2lucHV0W25hbWU9XCJzdG9rZW5cIl0nKT8udmFsdWUgfHwgJydcbiAgICBpZiAoIXN0b2tlbikge1xuICAgICAgdGhpcy5fZGVidWcoJ0NTUkYgdG9rZW4gKHN0b2tlbikgbm90IGZvdW5kIGluIGZvcm0nKVxuICAgICAgcmV0dXJuIHVybFxuICAgIH1cbiAgICBjb25zdCBzZXBhcmF0b3IgPSB1cmwuaW5jbHVkZXMoJz8nKSA/ICcmJyA6ICc/J1xuICAgIHJldHVybiB1cmwgKyBzZXBhcmF0b3IgKyAnc3Rva2VuPScgKyBlbmNvZGVVUklDb21wb25lbnQoc3Rva2VuKVxuICB9XG5cbiAgLyoqXG4gICAqIEFwcGVuZCB0aGUgQUdCIGFjY2VwdGFuY2UgZmxhZyAob3JkX2FnYj0xKSB3aGVuIHRoZSBjdXN0b21lciBoYXMgdGlja2VkXG4gICAqIHRoZSBhcGV4IFRlcm1zLWFuZC1Db25kaXRpb25zIGNoZWNrYm94ICgjY2hlY2tBZ2JUb3ApLiBUaGUgU3RyaXBlIG9yZGVyXG4gICAqIGZldGNoIHBvc3RzIGEgSlNPTiBib2R5LCB3aGljaCBPWElEJ3MgUmVnaXN0cnk6OmdldFJlcXVlc3QoKSBkb2VzIG5vdFxuICAgKiBwYXJzZSBcdTIwMTQgcGxhY2luZyBvcmRfYWdiIGluIHRoZSBxdWVyeSBzdHJpbmcgaXMgdGhlIHNpbXBsZXN0IHdheSB0byBtYWtlXG4gICAqIFN0cmlwZU9yZGVyQ29udHJvbGxlcjo6Y3JlYXRlQ2hlY2tvdXRTZXNzaW9uKCkgc2VlIGl0LlxuICAgKlxuICAgKiBAcGFyYW0ge3N0cmluZ30gdXJsXG4gICAqIEByZXR1cm5zIHtzdHJpbmd9XG4gICAqL1xuICBhcHBlbmRBZ2JTdGF0ZSh1cmwpIHtcbiAgICBjb25zdCBjaGVja2JveCA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdjaGVja0FnYlRvcCcpXG4gICAgaWYgKCFjaGVja2JveCB8fCAhY2hlY2tib3guY2hlY2tlZCkge1xuICAgICAgcmV0dXJuIHVybFxuICAgIH1cbiAgICBjb25zdCBzZXBhcmF0b3IgPSB1cmwuaW5jbHVkZXMoJz8nKSA/ICcmJyA6ICc/J1xuICAgIHJldHVybiB1cmwgKyBzZXBhcmF0b3IgKyAnb3JkX2FnYj0xJ1xuICB9XG5cbiAgLyoqXG4gICAqIFNob3cgbG9hZGluZyBzdGF0ZSBvbiBidXR0b24uXG4gICAqXG4gICAqIFNwcmludCAxMjM6IERpc3BhdGNoICdvZTpzdHJpcGU6c3VibWl0LXN0YXJ0JyBzbyB0aGF0IGFnYi12YWxpZGF0aW9uXG4gICAqIGNhbiBsb2NrIHRoZSBBR0IgY2hlY2tib3ggZm9yIHRoZSBkdXJhdGlvbiBvZiB0aGUgc3VibWl0LCBwcmV2ZW50aW5nIHRoZVxuICAgKiBjdXN0b21lciBmcm9tIHVudGlja2luZyBpdCB3aGlsZSB0aGUgcmVxdWVzdCBpcyBpbiBmbGlnaHQuIFRoZSBsb2NrIGlzXG4gICAqIGF1dG9tYXRpY2FsbHkgbGlmdGVkIHdoZW4gaGlkZUxvYWRpbmcoKSBmaXJlcyAoZXJyb3IgcGF0aCwgYmZjYWNoZSByZXN0b3JlKVxuICAgKiB2aWEgdGhlIG1pcnJvciAnb2U6c3RyaXBlOnN1Ym1pdC1lbmQnIGV2ZW50IGVzdGFibGlzaGVkIGluIFNwcmludCAxMjIuXG4gICAqL1xuICBzaG93TG9hZGluZygpIHtcbiAgICB0aGlzLmVsZW1lbnQuZGlzYWJsZWQgPSB0cnVlXG4gICAgdGhpcy5vcmlnaW5hbFRleHQgPSB0aGlzLmVsZW1lbnQudGV4dENvbnRlbnRcbiAgICB0aGlzLmVsZW1lbnQudGV4dENvbnRlbnQgPSB3aW5kb3cub1N0cmlwZT8uaTE4bj8uUFJPQ0VTU0lORyB8fCAnUHJvY2Vzc2luZy4uLidcbiAgICBkb2N1bWVudC5kaXNwYXRjaEV2ZW50KG5ldyBDdXN0b21FdmVudCgnb2U6c3RyaXBlOnN1Ym1pdC1zdGFydCcpKVxuICB9XG5cbiAgLyoqXG4gICAqIEhpZGUgbG9hZGluZyBzdGF0ZSBvbiBidXR0b24uXG4gICAqXG4gICAqIFNwcmludCAxMjI6IEFmdGVyIHJlc3RvcmluZyB0aGUgYnV0dG9uJ3MgcmVzdGluZyBET00gc3RhdGUsIGRpc3BhdGNoXG4gICAqICdvZTpzdHJpcGU6c3VibWl0LWVuZCcgc28gdGhhdCBhZ2ItdmFsaWRhdGlvbiAodGhlIGF1dGhvcml0eSBvbiB0aGVcbiAgICogcmVzdGluZyBkaXNhYmxlZCB2YWx1ZSkgcmVjb21wdXRlcyBmcm9tIHRoZSBsaXZlIGNoZWNrYm94LiBUaGUgZGlzcGF0Y2hcbiAgICogaXMgc3luY2hyb25vdXMgXHUyMDE0IGFnYi12YWxpZGF0aW9uJ3MgcmVjb21wdXRlIHJ1bnMgYmVmb3JlIGhpZGVMb2FkaW5nKClcbiAgICogcmV0dXJucywgZW5zdXJpbmcgZGV0ZXJtaW5pc3RpYyBvcmRlcmluZyB3aXRoIG5vIGxpc3RlbmVyLW9yZGVyaW5nIHJhY2VcbiAgICogKHNlZSBzcHJpbnQgcGxhbiBcdTAwQTc0LjIpLlxuICAgKlxuICAgKiBUaGlzIGZpcmVzIG9uIHRocmVlIHBhdGhzOiBub3JtYWwgZXJyb3IgKGZpbmFsbHkgYmxvY2spLCBiZmNhY2hlIHJlc3RvcmVcbiAgICogKHBhZ2VzaG93IHBlcnNpc3RlZCBoYW5kbGVyKSwgYW5kIGFueSBmdXR1cmUgZXhwbGljaXQgY2FsbCBcdTIwMTQgYWxsIGFyZSBzYWZlXG4gICAqIGJlY2F1c2UgaGlkZUxvYWRpbmcoKSBhbmQgdXBkYXRlQnV0dG9uU3RhdGVzKCkgYXJlIGlkZW1wb3RlbnQuXG4gICAqL1xuICBoaWRlTG9hZGluZygpIHtcbiAgICB0aGlzLmVsZW1lbnQuZGlzYWJsZWQgPSBmYWxzZVxuICAgIGlmICh0aGlzLm9yaWdpbmFsVGV4dCkge1xuICAgICAgdGhpcy5lbGVtZW50LnRleHRDb250ZW50ID0gdGhpcy5vcmlnaW5hbFRleHRcbiAgICB9XG4gICAgZG9jdW1lbnQuZGlzcGF0Y2hFdmVudChuZXcgQ3VzdG9tRXZlbnQoJ29lOnN0cmlwZTpzdWJtaXQtZW5kJykpXG4gIH1cblxuICAvKipcbiAgICogU2V0IHN0YXR1cyBtZXNzYWdlXG4gICAqIEBwYXJhbSB7c3RyaW5nfSBtZXNzYWdlIC0gU3RhdHVzIG1lc3NhZ2UgdG8gZGlzcGxheVxuICAgKi9cbiAgc2V0U3RhdHVzKG1lc3NhZ2UpIHtcbiAgICBpZiAodGhpcy5oYXNTdGF0dXNUYXJnZXQpIHtcbiAgICAgIHRoaXMuc3RhdHVzVGFyZ2V0LnRleHRDb250ZW50ID0gbWVzc2FnZVxuICAgICAgdGhpcy5zdGF0dXNUYXJnZXQuY2xhc3NOYW1lID0gJ210LTIgdGV4dC1jZW50ZXIgdGV4dC1tdXRlZCdcbiAgICB9XG4gICAgdGhpcy5fZGVidWcoJ1N0YXR1czonLCBtZXNzYWdlKVxuICB9XG5cbiAgLyoqXG4gICAqIEV4dHJhY3QgdGhlIHBlci1maWVsZCB2YWxpZGF0aW9uIG1lc3NhZ2VzIGZyb20gYSA0MjIgcGF5bG9hZC5cbiAgICogQHBhcmFtIHt7ZXJyb3JzPzogQXJyYXk8e21lc3NhZ2U/OiBzdHJpbmd9Pn19IGVycm9yRGF0YVxuICAgKiBAcmV0dXJucyB7c3RyaW5nW119IHBlci1maWVsZCBtZXNzYWdlcyAoZW1wdHkgaWYgbm9uZSlcbiAgICovXG4gIGNvbGxlY3RWYWxpZGF0aW9uTWVzc2FnZXMoZXJyb3JEYXRhKSB7XG4gICAgaWYgKCFlcnJvckRhdGEgfHwgIUFycmF5LmlzQXJyYXkoZXJyb3JEYXRhLmVycm9ycykpIHtcbiAgICAgIHJldHVybiBbXVxuICAgIH1cbiAgICByZXR1cm4gZXJyb3JEYXRhLmVycm9ycy5tYXAoKGUpID0+IGUgJiYgZS5tZXNzYWdlKS5maWx0ZXIoQm9vbGVhbilcbiAgfVxuXG4gIC8qKlxuICAgKiBSZW5kZXIgdGhlIHZhbGlkYXRpb24gbWVzc2FnZXMgaW4gdGhlIHN0YW5kYXJkIE9YSUQgcmVkIGVycm9yIGJveFxuICAgKiAoI3N0cmlwZS12YWxpZGF0aW9uLWVycm9ycywgcGxhY2VkIGFib3ZlIHRoZSBjaGVja291dCBmb3JtKS4gVGhlIGJveCBpc1xuICAgKiBkaXNtaXNzZWQgYnkgdGhlIFwiVW5kZXJzdGFuZFwiIGJ1dHRvbiBPUiBieSBwcmVzc2luZyBhbnkga2V5LlxuICAgKiBGYWxscyBiYWNrIHRvIHRoZSBzdGF0dXMgdGFyZ2V0IGlmIHRoZSBjb250YWluZXIgaXMgYWJzZW50LlxuICAgKiBAcGFyYW0ge3N0cmluZ1tdfSBtZXNzYWdlc1xuICAgKi9cbiAgc2hvd1ZhbGlkYXRpb25Cb3gobWVzc2FnZXMpIHtcbiAgICBjb25zdCBjb250YWluZXIgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgnc3RyaXBlLXZhbGlkYXRpb24tZXJyb3JzJylcbiAgICBpZiAoIWNvbnRhaW5lcikge1xuICAgICAgdGhpcy5zaG93RXJyb3IobWVzc2FnZXMuam9pbignICcpKVxuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgY29uc3QgdW5kZXJzdGFuZFRleHQgPSBjb250YWluZXIuZ2V0QXR0cmlidXRlKCdkYXRhLXN0cmlwZS12YWxpZGF0aW9uLXVuZGVyc3RhbmQnKSB8fCAnVW5kZXJzdGFuZCdcbiAgICBjb250YWluZXIuaW5uZXJIVE1MID0gJydcblxuICAgIC8vIE9uZSBlcnJvciAtPiBvbmUgYm94LlxuICAgIGNvbnN0IGRpc21pc3NBbGwgPSAoKSA9PiB7XG4gICAgICBjb250YWluZXIuaW5uZXJIVE1MID0gJydcbiAgICAgIGRvY3VtZW50LnJlbW92ZUV2ZW50TGlzdGVuZXIoJ2tleWRvd24nLCBkaXNtaXNzQWxsKVxuICAgIH1cblxuICAgIGxldCBmaXJzdEJveCA9IG51bGxcbiAgICBmb3IgKGNvbnN0IG1lc3NhZ2Ugb2YgbWVzc2FnZXMpIHtcbiAgICAgIGNvbnN0IGJveCA9IHRoaXMuYnVpbGRFcnJvckJveChtZXNzYWdlLCB1bmRlcnN0YW5kVGV4dClcbiAgICAgIGNvbnRhaW5lci5hcHBlbmRDaGlsZChib3gpXG4gICAgICBmaXJzdEJveCA9IGZpcnN0Qm94IHx8IGJveFxuICAgIH1cblxuICAgIC8vIEFueSBrZXlwcmVzcyBkaXNtaXNzZXMgZXZlcnkgYm94LlxuICAgIGRvY3VtZW50LmFkZEV2ZW50TGlzdGVuZXIoJ2tleWRvd24nLCBkaXNtaXNzQWxsLCB7IG9uY2U6IHRydWUgfSlcblxuICAgIGlmIChmaXJzdEJveCkge1xuICAgICAgZmlyc3RCb3guc2Nyb2xsSW50b1ZpZXcoeyBiZWhhdmlvcjogJ3Ntb290aCcsIGJsb2NrOiAnY2VudGVyJyB9KVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBCdWlsZCBhIHNpbmdsZSBPWElELXN0eWxlIHJlZCBlcnJvciBib3ggZm9yIG9uZSBtZXNzYWdlLlxuICAgKiBUaGUgXCJVbmRlcnN0YW5kXCIgYnV0dG9uIHJlbW92ZXMganVzdCB0aGlzIGJveC5cbiAgICogQHBhcmFtIHtzdHJpbmd9IG1lc3NhZ2VcbiAgICogQHBhcmFtIHtzdHJpbmd9IHVuZGVyc3RhbmRUZXh0XG4gICAqIEByZXR1cm5zIHtIVE1MRWxlbWVudH1cbiAgICovXG4gIGJ1aWxkRXJyb3JCb3gobWVzc2FnZSwgdW5kZXJzdGFuZFRleHQpIHtcbiAgICBjb25zdCBib3ggPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdkaXYnKVxuICAgIGJveC5jbGFzc05hbWUgPSAnYWxlcnQgYWxlcnQtZGFuZ2VyIGQtZmxleCBqdXN0aWZ5LWNvbnRlbnQtYmV0d2VlbiBhbGlnbi1pdGVtcy1jZW50ZXIgcHgtNCdcblxuICAgIGNvbnN0IHRleHRXcmFwID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnZGl2JylcbiAgICB0ZXh0V3JhcC5jbGFzc05hbWUgPSAncHMtMiBwZS0zIHRleHQtc3RhcnQgZmxleC1ncm93LTEnXG4gICAgdGV4dFdyYXAuc3R5bGUudGV4dEFsaWduID0gJ2xlZnQnXG5cbiAgICBjb25zdCBwID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgncCcpXG4gICAgcC5jbGFzc05hbWUgPSAnbWItMCdcbiAgICAvLyBPdmVycmlkZSB0aGUgdGhlbWUncyBgLmFsZXJ0LWRhbmdlciBwIHsgdGV4dC1hbGlnbjogY2VudGVyIH1gIHJ1bGUuXG4gICAgcC5zdHlsZS50ZXh0QWxpZ24gPSAnbGVmdCdcbiAgICBwLnRleHRDb250ZW50ID0gbWVzc2FnZVxuICAgIHRleHRXcmFwLmFwcGVuZENoaWxkKHApXG5cbiAgICBjb25zdCBidXR0b24gPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdidXR0b24nKVxuICAgIGJ1dHRvbi50eXBlID0gJ2J1dHRvbidcbiAgICBidXR0b24uY2xhc3NOYW1lID0gJ2J0biBidG4tb3V0bGluZS1saWdodCBidG4tc20gdGV4dC13aGl0ZSBib3JkZXIgYm9yZGVyLXdoaXRlIGZsZXgtc2hyaW5rLTAnXG4gICAgYnV0dG9uLnRleHRDb250ZW50ID0gdW5kZXJzdGFuZFRleHRcbiAgICBidXR0b24uYWRkRXZlbnRMaXN0ZW5lcignY2xpY2snLCAoKSA9PiBib3gucmVtb3ZlKCkpXG5cbiAgICBib3guYXBwZW5kQ2hpbGQodGV4dFdyYXApXG4gICAgYm94LmFwcGVuZENoaWxkKGJ1dHRvbilcbiAgICByZXR1cm4gYm94XG4gIH1cblxuICAvKipcbiAgICogTWFyayB0aGUgY29ycmVzcG9uZGluZyBPWElEIGFkZHJlc3MgaW5wdXRzIGludmFsaWQgKyByZW5kZXIgaW5saW5lIGZlZWRiYWNrLFxuICAgKiB3aGVuIHN1Y2ggaW5wdXRzIGV4aXN0IGluIHRoZSBET00gKGVkaXRhYmxlIGNoZWNrb3V0IHRoZW1lcyAvIGNsPXVzZXIgc3RlcCkuXG4gICAqIE9uIHRoZSByZWFkLW9ubHkgb3JkZXIgcGFnZSB0aGlzIGlzIGEgbm8tb3A7IHRoZSBlcnJvciBib3ggY2FycmllcyB0aGUgbWVzc2FnZS5cbiAgICogQHBhcmFtIHtBcnJheTx7ZmllbGQ/OiBzdHJpbmcsIG1lc3NhZ2U/OiBzdHJpbmd9Pn0gZXJyb3JzXG4gICAqL1xuICByZW5kZXJGaWVsZFZhbGlkYXRpb25FcnJvcnMoZXJyb3JzKSB7XG4gICAgaWYgKCFBcnJheS5pc0FycmF5KGVycm9ycykpIHtcbiAgICAgIHJldHVyblxuICAgIH1cbiAgICBjb25zdCBOQU1FX01BUCA9IHtcbiAgICAgIGZpcnN0TmFtZTogJ294dXNlcl9fb3hmbmFtZScsIGxhc3ROYW1lOiAnb3h1c2VyX19veGxuYW1lJyxcbiAgICAgIGFkZGl0aW9uYWxJbmZvOiAnb3h1c2VyX19veGFkZGluZm8nLCBzdHJlZXQ6ICdveHVzZXJfX294c3RyZWV0JyxcbiAgICAgIGhvdXNlTnVtYmVyOiAnb3h1c2VyX19veHN0cmVldG5yJywgcG9zdGFsQ29kZTogJ294dXNlcl9fb3h6aXAnLFxuICAgICAgY2l0eTogJ294dXNlcl9fb3hjaXR5JywgY29tcGFueTogJ294dXNlcl9fb3hjb21wYW55JywgdmF0SWQ6ICdveHVzZXJfX294dXN0aWQnLFxuICAgICAgcGhvbmU6ICdveHVzZXJfX294Zm9uJywgY2VsbFBob25lOiAnb3h1c2VyX19veHByaXZmb24nLFxuICAgICAgcGVyc29uYWxQaG9uZTogJ294dXNlcl9fb3htb2Jmb24nLCBmYXg6ICdveHVzZXJfX294ZmF4J1xuICAgIH1cbiAgICBmb3IgKGNvbnN0IGVyciBvZiBlcnJvcnMpIHtcbiAgICAgIGNvbnN0IG5hbWUgPSBOQU1FX01BUFtlcnIgJiYgZXJyLmZpZWxkXVxuICAgICAgY29uc3QgZWwgPSBuYW1lID8gZG9jdW1lbnQucXVlcnlTZWxlY3RvcignW25hbWU9XCInICsgbmFtZSArICdcIl0nKSA6IG51bGxcbiAgICAgIGlmICghZWwpIHtcbiAgICAgICAgY29udGludWVcbiAgICAgIH1cbiAgICAgIGVsLmNsYXNzTGlzdC5hZGQoJ2lzLWludmFsaWQnKVxuICAgICAgY29uc3QgZXhpc3RpbmcgPSBlbC5wYXJlbnRFbGVtZW50ICYmIGVsLnBhcmVudEVsZW1lbnQucXVlcnlTZWxlY3RvcignLmludmFsaWQtZmVlZGJhY2tbZGF0YS1zdHJpcGUtdmFsaWRhdGlvbl0nKVxuICAgICAgaWYgKGV4aXN0aW5nKSBleGlzdGluZy5yZW1vdmUoKVxuICAgICAgY29uc3QgZmVlZGJhY2sgPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdkaXYnKVxuICAgICAgZmVlZGJhY2suY2xhc3NOYW1lID0gJ2ludmFsaWQtZmVlZGJhY2snXG4gICAgICBmZWVkYmFjay5zZXRBdHRyaWJ1dGUoJ2RhdGEtc3RyaXBlLXZhbGlkYXRpb24nLCAndHJ1ZScpXG4gICAgICBmZWVkYmFjay50ZXh0Q29udGVudCA9IGVyci5tZXNzYWdlIHx8ICgnSW52YWxpZCB2YWx1ZSBmb3IgJyArIGVyci5maWVsZClcbiAgICAgIGVsLmluc2VydEFkamFjZW50RWxlbWVudCgnYWZ0ZXJlbmQnLCBmZWVkYmFjaylcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogU2hvdyBlcnJvciBtZXNzYWdlXG4gICAqIEBwYXJhbSB7c3RyaW5nfSBtZXNzYWdlIC0gRXJyb3IgbWVzc2FnZSB0byBkaXNwbGF5XG4gICAqL1xuICBzaG93RXJyb3IobWVzc2FnZSkge1xuICAgIGlmICh0aGlzLmhhc1N0YXR1c1RhcmdldCkge1xuICAgICAgdGhpcy5zdGF0dXNUYXJnZXQudGV4dENvbnRlbnQgPSBtZXNzYWdlXG4gICAgICB0aGlzLnN0YXR1c1RhcmdldC5jbGFzc05hbWUgPSAnbXQtMiB0ZXh0LWNlbnRlciB0ZXh0LWRhbmdlcidcbiAgICB9IGVsc2Uge1xuICAgICAgYWxlcnQoJ0Vycm9yOiAnICsgbWVzc2FnZSlcbiAgICB9XG4gIH1cbn1cbiIsICJpbXBvcnQgeyBDb250cm9sbGVyIH0gZnJvbSAnQGhvdHdpcmVkL3N0aW11bHVzJ1xuXG4vKipcbiAqIFN0aW11bHVzIGNvbnRyb2xsZXIgZm9yIEFHQiAoVGVybXMgYW5kIENvbmRpdGlvbnMpIGNoZWNrYm94IHZhbGlkYXRpb24uXG4gKlxuICogV2hlbiBibENvbmZpcm1BR0IgaXMgZW5hYmxlZCwgZGlzYWJsZXMgc3VibWl0IGJ1dHRvbnMgdW50aWwgdGhlIGN1c3RvbWVyXG4gKiB0aWNrcyB0aGUgQUdCIGNoZWNrYm94IGFuZCByZS1lbmFibGVzIHRoZW0gb24gY2hhbmdlLlxuICpcbiAqIFRoZSBBR0IgY2hlY2tib3ggKCNjaGVja0FnYlRvcCkgbGl2ZXMgaW4gdGhlIGFwZXggdGhlbWUgcGFydGlhbCBhbmRcbiAqIGNhbm5vdCBjYXJyeSBhIFN0aW11bHVzIHRhcmdldCBhdHRyaWJ1dGUgKGVkaXQgYm91bmRhcnkpLiBUaGlzIGNvbnRyb2xsZXJcbiAqIHJlc29sdmVzIGl0IGJ5IGl0cyBzdGFibGUgYXBleCBET00gSUQgaW4gY29ubmVjdCgpIGluc3RlYWQuXG4gKlxuICogVXNhZ2UgaW4gdGVtcGxhdGU6XG4gKiA8ZGl2IGRhdGEtY29udHJvbGxlcj1cImFnYi12YWxpZGF0aW9uXCIgZGF0YS1hZ2ItdmFsaWRhdGlvbi1lbmFibGVkLXZhbHVlPVwidHJ1ZVwiPlxuICogICA8YnV0dG9uIGRhdGEtYWdiLXZhbGlkYXRpb24tdGFyZ2V0PVwic3VibWl0QnV0dG9uXCI+T3JkZXI8L2J1dHRvbj5cbiAqIDwvZGl2PlxuICovXG5leHBvcnQgZGVmYXVsdCBjbGFzcyBleHRlbmRzIENvbnRyb2xsZXIge1xuICBzdGF0aWMgdGFyZ2V0cyA9IFsnc3VibWl0QnV0dG9uJ11cbiAgc3RhdGljIHZhbHVlcyA9IHtcbiAgICBlbmFibGVkOiBCb29sZWFuLFxuICAgIHByaW9yQ29uc2VudDogQm9vbGVhblxuICB9XG5cbiAgLyoqXG4gICAqIEluaXRpYWxpemUgdGhlIGNvbnRyb2xsZXIgd2hlbiBjb25uZWN0ZWQgdG8gdGhlIERPTS5cbiAgICpcbiAgICogU3ByaW50IDEwMTogVGhlIEFHQiBjaGVja2JveCAoI2NoZWNrQWdiVG9wKSBsaXZlcyBpbiB0aGUgYXBleCB0aGVtZVxuICAgKiBwYXJ0aWFsIHdoaWNoIHRoZSBTdHJpcGUgbW9kdWxlIG1heSBub3QgbW9kaWZ5IChlZGl0IGJvdW5kYXJ5IFx1MDBBNzAgb2ZcbiAgICogc3ByaW50IHBsYW4pLiBXZSByZXNvbHZlIGl0IGJ5IGl0cyBzdGFibGUgYXBleCBET00gSUQgXHUyMDE0IHRoZSBzYW1lIElEXG4gICAqIE9YSUQncyBvd24gYWdiLmpzIGNvbnN1bWVzIFx1MjAxNCBhbmQgYXR0YWNoIGEgY2hhbmdlIGxpc3RlbmVyLlxuICAgKlxuICAgKiBJZiB0aGUgY2hlY2tib3ggaXMgYWJzZW50IGZyb20gdGhlIERPTSAoYmxDb25maXJtQUdCIGlzIG9mZiBhbmQgYXBleFxuICAgKiBkaWQgbm90IHJlbmRlciBpdCksIHRoZSBudWxsIGd1YXJkIGxlYXZlcyBhbGwgYnV0dG9ucyBlbmFibGVkLCB3aGljaFxuICAgKiBpcyB0aGUgY29ycmVjdCBvdXRjb21lIGZvciB0aGF0IGNvbmZpZ3VyYXRpb24gcGF0aC5cbiAgICovXG4gIGNvbm5lY3QoKSB7XG4gICAgLy8gUmVzb2x2ZSB0aGUgYXBleCBBR0IgY2hlY2tib3ggYnkgaXRzIHN0YWJsZSBJRFxuICAgIHRoaXMuX2NvcmVDaGVja2JveCA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdjaGVja0FnYlRvcCcpXG4gICAgaWYgKHRoaXMuX2NvcmVDaGVja2JveCkge1xuICAgICAgdGhpcy5fY29yZUNoZWNrYm94LmFkZEV2ZW50TGlzdGVuZXIoJ2NoYW5nZScsICgpID0+IHRoaXMuY2hlY2tib3hDaGFuZ2VkKCkpXG4gICAgfVxuXG4gICAgLy8gU3ByaW50IDEyMjogTGlzdGVuIGZvciB0aGUgc3VibWl0LWxpZmVjeWNsZS1lbmRlZCBzaWduYWwgZGlzcGF0Y2hlZCBieVxuICAgIC8vIG9yZGVyX3N1Ym1pdF9jb250cm9sbGVyI2hpZGVMb2FkaW5nKCkuIFRoaXMgZmlyZXMgb24gZXJyb3IsIG9uIGJmY2FjaGVcbiAgICAvLyByZXN0b3JlLCBhbmQgb24gYW55IGV4cGxpY2l0IGNhbGwgXHUyMDE0IGFsd2F5cyBzYWZlICh1cGRhdGVCdXR0b25TdGF0ZXMgaXNcbiAgICAvLyBpZGVtcG90ZW50KS4gYWdiLXZhbGlkYXRpb24gaXMgdGhlIGF1dGhvcml0eSBvbiB0aGUgcmVzdGluZyBkaXNhYmxlZFxuICAgIC8vIHZhbHVlOyBpdCBtdXN0IGFsd2F5cyBoYXZlIHRoZSBsYXN0IHdvcmQgKHNlZSBzcHJpbnQgcGxhbiBcdTAwQTc0LjIpLlxuICAgIC8vIE5vIG93biBwYWdlc2hvdyBsaXN0ZW5lciBoZXJlIFx1MjAxNCB0aGUgc2VhbSBpcyB0aGUgb25seSBjb29yZGluYXRpb24gcGF0aC5cbiAgICAvL1xuICAgIC8vIFNwcmludCAxMjM6IEV4dGVuZGVkIHRvIGFsc28gdW5sb2NrIHRoZSBjaGVja2JveCBiZWZvcmUgcmVjb21wdXRpbmdcbiAgICAvLyBidXR0b24gc3RhdGVzLCBzbyB0aGUgY3VzdG9tZXIgY2FuIHJldHJ5IGFmdGVyIGFuIGVycm9yIG9yIGJmY2FjaGUgcmV0dXJuLlxuICAgIHRoaXMuX29uU3VibWl0RW5kID0gKCkgPT4geyB0aGlzLnVubG9ja0NoZWNrYm94KCk7IGlmICh0aGlzLmVuYWJsZWRWYWx1ZSkgdGhpcy51cGRhdGVCdXR0b25TdGF0ZXMoKSB9XG4gICAgZG9jdW1lbnQuYWRkRXZlbnRMaXN0ZW5lcignb2U6c3RyaXBlOnN1Ym1pdC1lbmQnLCB0aGlzLl9vblN1Ym1pdEVuZClcblxuICAgIC8vIFNwcmludCAxMjM6IExpc3RlbiBmb3IgdGhlIHN1Ym1pdC1saWZlY3ljbGUtc3RhcnRlZCBzaWduYWwgZGlzcGF0Y2hlZCBieVxuICAgIC8vIG9yZGVyX3N1Ym1pdF9jb250cm9sbGVyI3Nob3dMb2FkaW5nKCkuIExvY2tzIHRoZSBBR0IgY2hlY2tib3ggZm9yIHRoZVxuICAgIC8vIGR1cmF0aW9uIG9mIHRoZSBpbi1mbGlnaHQgc3VibWl0IHRvIHByZXZlbnQgdmlzaWJsZSBjb25zZW50IGNvbnRyYWRpY3Rpb24uXG4gICAgdGhpcy5fb25TdWJtaXRTdGFydCA9ICgpID0+IHRoaXMubG9ja0NoZWNrYm94KClcbiAgICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCdvZTpzdHJpcGU6c3VibWl0LXN0YXJ0JywgdGhpcy5fb25TdWJtaXRTdGFydClcblxuICAgIC8vIFNwcmludCAxMjg6IHJlc3RvcmUgY29uc2VudCB0aGUgY3VzdG9tZXIgZ2F2ZSBiZWZvcmUgdGhlIHJlZGlyZWN0IHNvIHRoZVxuICAgIC8vIE9yZGVyLW5vdyBidXR0b24gaXMgZW5hYmxlZCBvbiBhIGZyZXNoLWxvYWQgcmV0dXJuLiBUaGUgZmxhZyBpcyByZW5kZXJlZFxuICAgIC8vIGZyb20gdGhlIHNlcnZlci1wZXJzaXN0ZWQgc2Vzc2lvbiBjb25zZW50LiBSZS1jaGVja2luZyAjY2hlY2tBZ2JUb3AgaGVyZSBpc1xuICAgIC8vIGluLWJvdW5kcyAodGhpcyBjb250cm9sbGVyIGFscmVhZHkgbG9ja3MvdW5sb2NrcyBpdCkuIE51bGwtZ3VhcmRlZC5cbiAgICBpZiAodGhpcy5lbmFibGVkVmFsdWUgJiYgdGhpcy5wcmlvckNvbnNlbnRWYWx1ZVxuICAgICAgICAmJiB0aGlzLl9jb3JlQ2hlY2tib3ggJiYgIXRoaXMuX2NvcmVDaGVja2JveC5jaGVja2VkKSB7XG4gICAgICB0aGlzLl9jb3JlQ2hlY2tib3guY2hlY2tlZCA9IHRydWVcbiAgICB9XG5cbiAgICBpZiAodGhpcy5lbmFibGVkVmFsdWUpIHtcbiAgICAgIHRoaXMudXBkYXRlQnV0dG9uU3RhdGVzKClcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogQ2FsbGVkIHdoZW4gY29udHJvbGxlciBpcyBkaXNjb25uZWN0ZWQgZnJvbSBET00uXG4gICAqXG4gICAqIFNwcmludCAxMjIvMTIzOiBSZW1vdmUgYm90aCBzdWJtaXQtbGlmZWN5Y2xlIGxpc3RlbmVycyB1c2luZyB0aGUgZXhhY3RcbiAgICogYm91bmQgcmVmZXJlbmNlcyBzdG9yZWQgaW4gY29ubmVjdCgpIFx1MjAxNCBzeW1tZXRyaWMsIGxlYWstZnJlZSBhY3Jvc3NcbiAgICogU3RpbXVsdXMgcmVjb25uZWN0cy5cbiAgICovXG4gIGRpc2Nvbm5lY3QoKSB7XG4gICAgZG9jdW1lbnQucmVtb3ZlRXZlbnRMaXN0ZW5lcignb2U6c3RyaXBlOnN1Ym1pdC1lbmQnLCB0aGlzLl9vblN1Ym1pdEVuZClcbiAgICBkb2N1bWVudC5yZW1vdmVFdmVudExpc3RlbmVyKCdvZTpzdHJpcGU6c3VibWl0LXN0YXJ0JywgdGhpcy5fb25TdWJtaXRTdGFydClcbiAgfVxuXG4gIC8qKlxuICAgKiBMb2NrIHRoZSBBR0IgY2hlY2tib3ggc28gaXQgY2Fubm90IGJlIHRvZ2dsZWQgd2hpbGUgYSBzdWJtaXQgaXMgaW4gZmxpZ2h0LlxuICAgKlxuICAgKiBTcHJpbnQgMTIzOiBVSS1pbnRlZ3JpdHkgZml4IG9ubHkgXHUyMDE0IHRoZSBjb25zZW50IGlzIGFscmVhZHkgY2FwdHVyZWQgaW5cbiAgICogb3JkX2FnYj0xIGJ5IGFwcGVuZEFnYlN0YXRlKCkgYmVmb3JlIHRoaXMgbG9jayBtYXR0ZXJzIChcdTAwQTcwL1x1MDBBNzQuMyBvZlxuICAgKiBzcHJpbnQgcGxhbikuIE51bGwtZ3VhcmRlZDogaWYgYmxDb25maXJtQUdCIGlzIG9mZiBhbmQgdGhlIGNoZWNrYm94IGlzXG4gICAqIGFic2VudCwgdGhpcyBpcyBhIHNhZmUgbm8tb3AuXG4gICAqL1xuICBsb2NrQ2hlY2tib3goKSB7XG4gICAgaWYgKHRoaXMuX2NvcmVDaGVja2JveCkge1xuICAgICAgdGhpcy5fY29yZUNoZWNrYm94LmRpc2FibGVkID0gdHJ1ZVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBVbmxvY2sgdGhlIEFHQiBjaGVja2JveCBhZnRlciBhIHN1Ym1pdCBsaWZlY3ljbGUgZW5kcyAoZXJyb3IsIGJmY2FjaGVcbiAgICogcmVzdG9yZSwgb3IgYW55IGZ1dHVyZSBwYXRoIHRoYXQgY2FsbHMgaGlkZUxvYWRpbmcoKSkuXG4gICAqXG4gICAqIFNwcmludCAxMjM6IElkZW1wb3RlbnQgXHUyMDE0IHNhZmUgdG8gY2FsbCBtdWx0aXBsZSB0aW1lcy4gTnVsbC1ndWFyZGVkIGZvclxuICAgKiB0aGUgYmxDb25maXJtQUdCPW9mZiBjYXNlIHdoZXJlIG5vIGNoZWNrYm94IGlzIHJlbmRlcmVkLlxuICAgKi9cbiAgdW5sb2NrQ2hlY2tib3goKSB7XG4gICAgaWYgKHRoaXMuX2NvcmVDaGVja2JveCkge1xuICAgICAgdGhpcy5fY29yZUNoZWNrYm94LmRpc2FibGVkID0gZmFsc2VcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIGNoZWNrYm94IHN0YXRlIGNoYW5nZXNcbiAgICovXG4gIGNoZWNrYm94Q2hhbmdlZCgpIHtcbiAgICBpZiAodGhpcy5lbmFibGVkVmFsdWUpIHtcbiAgICAgIHRoaXMudXBkYXRlQnV0dG9uU3RhdGVzKClcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogVXBkYXRlIHRoZSBkaXNhYmxlZCBzdGF0ZSBvZiBhbGwgc3VibWl0IGJ1dHRvbnMgYmFzZWQgb24gY2hlY2tib3ggc3RhdGUuXG4gICAqXG4gICAqIFJlYWRzIGZyb20gdGhpcy5fY29yZUNoZWNrYm94ICh0aGUgYXBleCAjY2hlY2tBZ2JUb3AgZWxlbWVudCByZXNvbHZlZFxuICAgKiBpbiBjb25uZWN0KCkpLiBJZiB0aGUgY2hlY2tib3ggaXMgbm90IHByZXNlbnQsIGxlYXZlcyBidXR0b25zIGVuYWJsZWQuXG4gICAqL1xuICB1cGRhdGVCdXR0b25TdGF0ZXMoKSB7XG4gICAgaWYgKCF0aGlzLmhhc1N1Ym1pdEJ1dHRvblRhcmdldCkge1xuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgLy8gSWYgdGhlIEFHQiBjaGVja2JveCBpcyBub3QgcmVuZGVyZWQgKGJsQ29uZmlybUFHQiBvZmYpLCBsZWF2ZSBidXR0b25zIGVuYWJsZWRcbiAgICBpZiAoIXRoaXMuX2NvcmVDaGVja2JveCkge1xuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgY29uc3QgaXNDaGVja2VkID0gdGhpcy5fY29yZUNoZWNrYm94LmNoZWNrZWRcblxuICAgIC8vIFVwZGF0ZSBhbGwgc3VibWl0IGJ1dHRvbnNcbiAgICB0aGlzLnN1Ym1pdEJ1dHRvblRhcmdldHMuZm9yRWFjaChidXR0b24gPT4ge1xuICAgICAgYnV0dG9uLmRpc2FibGVkID0gIWlzQ2hlY2tlZFxuXG4gICAgICAvLyBBZGQgdmlzdWFsIGZlZWRiYWNrXG4gICAgICBpZiAoaXNDaGVja2VkKSB7XG4gICAgICAgIGJ1dHRvbi5jbGFzc0xpc3QucmVtb3ZlKCdkaXNhYmxlZCcpXG4gICAgICAgIGJ1dHRvbi5yZW1vdmVBdHRyaWJ1dGUoJ3RpdGxlJylcbiAgICAgIH0gZWxzZSB7XG4gICAgICAgIGJ1dHRvbi5jbGFzc0xpc3QuYWRkKCdkaXNhYmxlZCcpXG4gICAgICAgIGJ1dHRvbi5zZXRBdHRyaWJ1dGUoJ3RpdGxlJywgd2luZG93Lm9TdHJpcGU/LmkxOG4/LkFHQl9SRVFVSVJFRCB8fCAnUGxlYXNlIGFjY2VwdCB0aGUgdGVybXMgYW5kIGNvbmRpdGlvbnMnKVxuICAgICAgfVxuICAgIH0pXG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIGZvcm0gc3VibWlzc2lvbiBhdHRlbXB0c1xuICAgKiBAcGFyYW0ge0V2ZW50fSBldmVudCAtIFRoZSBzdWJtaXQgZXZlbnRcbiAgICovXG4gIGhhbmRsZVN1Ym1pdChldmVudCkge1xuICAgIGlmICghdGhpcy5lbmFibGVkVmFsdWUpIHtcbiAgICAgIHJldHVybiB0cnVlXG4gICAgfVxuXG4gICAgaWYgKCF0aGlzLl9jb3JlQ2hlY2tib3ggfHwgIXRoaXMuX2NvcmVDaGVja2JveC5jaGVja2VkKSB7XG4gICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpXG4gICAgICBldmVudC5zdG9wUHJvcGFnYXRpb24oKVxuXG4gICAgICAvLyBTaG93IHZpc3VhbCBmZWVkYmFjayBvbiB0aGUgY2hlY2tib3ggd3JhcHBlclxuICAgICAgaWYgKHRoaXMuX2NvcmVDaGVja2JveCkge1xuICAgICAgICBjb25zdCBjaGVja2JveFdyYXBwZXIgPSB0aGlzLl9jb3JlQ2hlY2tib3guY2xvc2VzdCgnLmZvcm0tY2hlY2snKVxuICAgICAgICBpZiAoY2hlY2tib3hXcmFwcGVyKSB7XG4gICAgICAgICAgY2hlY2tib3hXcmFwcGVyLmNsYXNzTGlzdC5hZGQoJ2JvcmRlcicsICdib3JkZXItZGFuZ2VyJywgJ3AtMicsICdyb3VuZGVkJylcblxuICAgICAgICAgIC8vIFJlbW92ZSB0aGUgaGlnaGxpZ2h0IGFmdGVyIDMgc2Vjb25kc1xuICAgICAgICAgIHNldFRpbWVvdXQoKCkgPT4ge1xuICAgICAgICAgICAgY2hlY2tib3hXcmFwcGVyLmNsYXNzTGlzdC5yZW1vdmUoJ2JvcmRlcicsICdib3JkZXItZGFuZ2VyJywgJ3AtMicsICdyb3VuZGVkJylcbiAgICAgICAgICB9LCAzMDAwKVxuICAgICAgICB9XG4gICAgICB9XG5cbiAgICAgIHJldHVybiBmYWxzZVxuICAgIH1cblxuICAgIHJldHVybiB0cnVlXG4gIH1cbn1cbiIsICIvKipcbiAqIFN0cmlwZSBNb2R1bGUgLSBKYXZhU2NyaXB0IEVudHJ5IFBvaW50XG4gKlxuICogSW5pdGlhbGl6ZXMgU3RpbXVsdXMuanMgYW5kIHJlZ2lzdGVycyBhbGwgY29udHJvbGxlcnNcbiAqL1xuXG5pbXBvcnQgeyBBcHBsaWNhdGlvbiB9IGZyb20gXCJAaG90d2lyZWQvc3RpbXVsdXNcIlxuXG4vLyBJbXBvcnQgY29udHJvbGxlcnNcbmltcG9ydCBTdHJpcGVPcmRlckNvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvc3RyaXBlX29yZGVyX2NvbnRyb2xsZXJcIlxuaW1wb3J0IE9yZGVyU3VibWl0Q29udHJvbGxlciBmcm9tIFwiLi9jb250cm9sbGVycy9vcmRlcl9zdWJtaXRfY29udHJvbGxlclwiXG5pbXBvcnQgQWdiVmFsaWRhdGlvbkNvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvYWdiX3ZhbGlkYXRpb25fY29udHJvbGxlclwiXG5pbXBvcnQgeyBjcmVhdGVEZWJ1Z0xvZ2dlciB9IGZyb20gXCIuL2RlYnVnLmpzXCJcblxuLy8gU3RhcnQgU3RpbXVsdXMgYXBwbGljYXRpb25cbndpbmRvdy5TdGltdWx1cyA9IEFwcGxpY2F0aW9uLnN0YXJ0KClcblxuLy8gUmVnaXN0ZXIgY29udHJvbGxlcnNcblN0aW11bHVzLnJlZ2lzdGVyKFwic3RyaXBlLW9yZGVyXCIsIFN0cmlwZU9yZGVyQ29udHJvbGxlcilcblN0aW11bHVzLnJlZ2lzdGVyKFwib3JkZXItc3VibWl0XCIsIE9yZGVyU3VibWl0Q29udHJvbGxlcilcblN0aW11bHVzLnJlZ2lzdGVyKFwiYWdiLXZhbGlkYXRpb25cIiwgQWdiVmFsaWRhdGlvbkNvbnRyb2xsZXIpXG5cbi8vIEZyb250ZW5kIGRlYnVnIGlzIGdhdGVkIGJ5IHRoZSBtb2R1bGUncyBsb2cgbGV2ZWwgKHNTdHJpcGVMb2dMZXZlbCA9PT0gJ2RlYnVnJyksXG4vLyBzdXJmYWNlZCB0byBKUyBhcyB3aW5kb3cub1N0cmlwZS5kZWJ1ZyBieSBzdHJpcGVfaTE4bi5odG1sLnR3aWcuIEl0IGlzIE5PVCB0aWVkXG4vLyB0byB0aGUgYnVpbGQgdGFyZ2V0IG9yIGRvbWFpbiwgc28gc3dpdGNoaW5nIHRoZSBsb2dnaW5nIGZlYXR1cmUgb2ZmIHNpbGVuY2VzIHRoZVxuLy8gY29uc29sZSBcdTIwMTQgaW5jbHVkaW5nIFN0aW11bHVzJ3Mgb3duIGxpZmVjeWNsZSBsb2dnaW5nIFx1MjAxNCBldmVuIG9uIGRldiBkb21haW5zLlxuY29uc3Qgc3RyaXBlRGVidWdFbmFibGVkID0gKCkgPT4gd2luZG93Lm9TdHJpcGU/LmRlYnVnID09PSB0cnVlXG5jb25zdCBkZWJ1ZyA9IGNyZWF0ZURlYnVnTG9nZ2VyKHN0cmlwZURlYnVnRW5hYmxlZClcblxuU3RpbXVsdXMuZGVidWcgPSBzdHJpcGVEZWJ1Z0VuYWJsZWQoKVxuZGVidWcoJ1N0cmlwZSBNb2R1bGU6IFN0aW11bHVzIGluaXRpYWxpemVkIHdpdGggY29udHJvbGxlcnM6JywgU3RpbXVsdXMucm91dGVyLm1vZHVsZXNCeUlkZW50aWZpZXIpXG5kZWJ1ZygnU3RyaXBlIE1vZHVsZTogSmF2YVNjcmlwdCBsb2FkZWQgYW5kIHJlYWR5JylcbiJdLAogICJtYXBwaW5ncyI6ICI7Ozs7Ozs7OztBQUlBLE1BQU0sZ0JBQU4sTUFBb0I7QUFBQSxJQUNoQixZQUFZLGFBQWEsV0FBVyxjQUFjO0FBQzlDLFdBQUssY0FBYztBQUNuQixXQUFLLFlBQVk7QUFDakIsV0FBSyxlQUFlO0FBQ3BCLFdBQUssb0JBQW9CLG9CQUFJLElBQUk7QUFBQSxJQUNyQztBQUFBLElBQ0EsVUFBVTtBQUNOLFdBQUssWUFBWSxpQkFBaUIsS0FBSyxXQUFXLE1BQU0sS0FBSyxZQUFZO0FBQUEsSUFDN0U7QUFBQSxJQUNBLGFBQWE7QUFDVCxXQUFLLFlBQVksb0JBQW9CLEtBQUssV0FBVyxNQUFNLEtBQUssWUFBWTtBQUFBLElBQ2hGO0FBQUEsSUFDQSxpQkFBaUIsU0FBUztBQUN0QixXQUFLLGtCQUFrQixJQUFJLE9BQU87QUFBQSxJQUN0QztBQUFBLElBQ0Esb0JBQW9CLFNBQVM7QUFDekIsV0FBSyxrQkFBa0IsT0FBTyxPQUFPO0FBQUEsSUFDekM7QUFBQSxJQUNBLFlBQVksT0FBTztBQUNmLFlBQU0sZ0JBQWdCLFlBQVksS0FBSztBQUN2QyxpQkFBVyxXQUFXLEtBQUssVUFBVTtBQUNqQyxZQUFJLGNBQWMsNkJBQTZCO0FBQzNDO0FBQUEsUUFDSixPQUNLO0FBQ0Qsa0JBQVEsWUFBWSxhQUFhO0FBQUEsUUFDckM7QUFBQSxNQUNKO0FBQUEsSUFDSjtBQUFBLElBQ0EsY0FBYztBQUNWLGFBQU8sS0FBSyxrQkFBa0IsT0FB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QUksQ0FBQyxLQUFLLGNBQWM7QUFDdEIsZUFBTztBQUFBLE1BQ1Q7QUFFQSxVQUFJLENBQUMsS0FBSyxpQkFBaUIsQ0FBQyxLQUFLLGNBQWMsU0FBUztBQUN0RCxjQUFNLGVBQWU7QUFDckIsY0FBTSxnQkFBZ0I7QUFHdEIsWUFBSSxLQUFLLGVBQWU7QUFDdEIsZ0JBQU0sa0JBQWtCLEtBQUssY0FBYyxRQUFRLGFBQWE7QUFDaEUsY0FBSSxpQkFBaUI7QUFDbkIsNEJBQWdCLFVBQVUsSUFBSSxVQUFVLGlCQUFpQixPQUFPLFNBQVM7QUFHekUsdUJBQVcsTUFBTTtBQUNmLDhCQUFnQixVQUFVLE9BQU8sVUFBVSxpQkFBaUIsT0FBTyxTQUFTO0FBQUEsWUFDOUUsR0FBRyxHQUFJO0FBQUEsVUFDVDtBQUFBLFFBQ0Y7QUFFQSxlQUFPO0FBQUEsTUFDVDtBQUVBLGFBQU87QUFBQSxJQUNUO0FBQUEsRUFDRjtBQXpLRSxnQkFESyxtQ0FDRSxXQUFVLENBQUMsY0FBYztBQUNoQyxnQkFGSyxtQ0FFRSxVQUFTO0FBQUEsSUFDZCxTQUFTO0FBQUEsSUFDVCxjQUFjO0FBQUEsRUFDaEI7OztBQ1BGLFNBQU8sV0FBVyxZQUFZLE1BQU07QUFHcEMsV0FBUyxTQUFTLGdCQUFnQiwrQkFBcUI7QUFDdkQsV0FBUyxTQUFTLGdCQUFnQiwrQkFBcUI7QUFDdkQsV0FBUyxTQUFTLGtCQUFrQixpQ0FBdUI7QUFNM0QsTUFBTSxxQkFBcUIsTUFBRztBQTFCOUI7QUEwQmlDLHlCQUFPLFlBQVAsbUJBQWdCLFdBQVU7QUFBQTtBQUMzRCxNQUFNLFFBQVEsa0JBQWtCLGtCQUFrQjtBQUVsRCxXQUFTLFFBQVEsbUJBQW1CO0FBQ3BDLFFBQU0seURBQXlELFNBQVMsT0FBTyxtQkFBbUI7QUFDbEcsUUFBTSw0Q0FBNEM7IiwKICAibmFtZXMiOiBbImVycm9yIiwgImZldGNoIiwgIm1hdGNoIiwgIm9sZFZhbHVlIiwgImVycm9yIiwgImNvbnN0cnVjdG9yIiwgImVsZW1lbnQiLCAiZGVidWciLCAiZXJyb3IiLCAiZXJyb3IiXQp9Cg==
