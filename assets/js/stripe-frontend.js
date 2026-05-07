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

  // resources/build/js/controllers/stripe_order_controller.js
  var stripe_order_controller_default = class extends Controller {
    connect() {
      var _a, _b;
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
      var _a, _b;
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
      var _a, _b, _c, _d;
      if (!this.hasUrlValue) {
        throw new Error(((_b = (_a = window.oStripe) == null ? void 0 : _a.i18n) == null ? void 0 : _b.URL_NOT_CONFIGURED) || "Payment URL is not configured");
      }
      console.log("Creating payment intent via URL:", this.urlValue);
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
        console.warn("CSRF token (stoken) not found in form");
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
     * Show loading state on button
     */
    showLoading() {
      var _a, _b;
      this.element.disabled = true;
      this.originalText = this.element.textContent;
      this.element.textContent = ((_b = (_a = window.oStripe) == null ? void 0 : _a.i18n) == null ? void 0 : _b.PROCESSING) || "Processing...";
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
    enabled: Boolean
  });

  // resources/build/js/app.js
  window.Stimulus = Application.start();
  Stimulus.register("stripe-order", stripe_order_controller_default);
  Stimulus.register("order-submit", order_submit_controller_default);
  Stimulus.register("agb-validation", agb_validation_controller_default);
  if (true) {
    Stimulus.debug = true;
    console.log("Stripe Module: Stimulus initialized with controllers:", Stimulus.router.modulesByIdentifier);
  }
  console.log("Stripe Module: JavaScript loaded and ready");
})();
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsiLi4vLi4vbm9kZV9tb2R1bGVzL0Bob3R3aXJlZC9zdGltdWx1cy9kaXN0L3N0aW11bHVzLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9jb250cm9sbGVycy9zdHJpcGVfb3JkZXJfY29udHJvbGxlci5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvY29udHJvbGxlcnMvb3JkZXJfc3VibWl0X2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL2NvbnRyb2xsZXJzL2FnYl92YWxpZGF0aW9uX2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL2FwcC5qcyJdLAogICJzb3VyY2VzQ29udGVudCI6IFsiLypcblN0aW11bHVzIDMuMi4xXG5Db3B5cmlnaHQgXHUwMEE5IDIwMjMgQmFzZWNhbXAsIExMQ1xuICovXG5jbGFzcyBFdmVudExpc3RlbmVyIHtcbiAgICBjb25zdHJ1Y3RvcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpIHtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldCA9IGV2ZW50VGFyZ2V0O1xuICAgICAgICB0aGlzLmV2ZW50TmFtZSA9IGV2ZW50TmFtZTtcbiAgICAgICAgdGhpcy5ldmVudE9wdGlvbnMgPSBldmVudE9wdGlvbnM7XG4gICAgICAgIHRoaXMudW5vcmRlcmVkQmluZGluZ3MgPSBuZXcgU2V0KCk7XG4gICAgfVxuICAgIGNvbm5lY3QoKSB7XG4gICAgICAgIHRoaXMuZXZlbnRUYXJnZXQuYWRkRXZlbnRMaXN0ZW5lcih0aGlzLmV2ZW50TmFtZSwgdGhpcywgdGhpcy5ldmVudE9wdGlvbnMpO1xuICAgIH1cbiAgICBkaXNjb25uZWN0KCkge1xuICAgICAgICB0aGlzLmV2ZW50VGFyZ2V0LnJlbW92ZUV2ZW50TGlzdGVuZXIodGhpcy5ldmVudE5hbWUsIHRoaXMsIHRoaXMuZXZlbnRPcHRpb25zKTtcbiAgICB9XG4gICAgYmluZGluZ0Nvbm5lY3RlZChiaW5kaW5nKSB7XG4gICAgICAgIHRoaXMudW5vcmRlcmVkQmluZGluZ3MuYWRkKGJpbmRpbmcpO1xuICAgIH1cbiAgICBiaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcpIHtcbiAgICAgICAgdGhpcy51bm9yZGVyZWRCaW5kaW5ncy5kZWxldGUoYmluZGluZyk7XG4gICAgfVxuICAgIGhhbmRsZUV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IGV4dGVuZGVkRXZlbnQgPSBleHRlbmRFdmVudChldmVudCk7XG4gICAgICAgIGZvciAoY29uc3QgYmluZGluZyBvZiB0aGlzLmJpbmRpbmdzKSB7XG4gICAgICAgICAgICBpZiAoZXh0ZW5kZWRFdmVudC5pbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWQpIHtcbiAgICAgICAgICAgICAgICBicmVhaztcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgIGJpbmRpbmcuaGFuZGxlRXZlbnQoZXh0ZW5kZWRFdmVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgaGFzQmluZGluZ3MoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnVub3JkZXJlZEJpbmRpbmdzLnNpemUgPiAwO1xuICAgIH1cbiAgICBnZXQgYmluZGluZ3MoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMudW5vcmRlcmVkQmluZGluZ3MpLnNvcnQoKGxlZnQsIHJpZ2h0KSA9PiB7XG4gICAgICAgICAgICBjb25zdCBsZWZ0SW5kZXggPSBsZWZ0LmluZGV4LCByaWdodEluZGV4ID0gcmlnaHQuaW5kZXg7XG4gICAgICAgICAgICByZXR1cm4gbGVmdEluZGV4IDwgcmlnaHRJbmRleCA/IC0xIDogbGVmdEluZGV4ID4gcmlnaHRJbmRleCA/IDEgOiAwO1xuICAgICAgICB9KTtcbiAgICB9XG59XG5mdW5jdGlvbiBleHRlbmRFdmVudChldmVudCkge1xuICAgIGlmIChcImltbWVkaWF0ZVByb3BhZ2F0aW9uU3RvcHBlZFwiIGluIGV2ZW50KSB7XG4gICAgICAgIHJldHVybiBldmVudDtcbiAgICB9XG4gICAgZWxzZSB7XG4gICAgICAgIGNvbnN0IHsgc3RvcEltbWVkaWF0ZVByb3BhZ2F0aW9uIH0gPSBldmVudDtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24oZXZlbnQsIHtcbiAgICAgICAgICAgIGltbWVkaWF0ZVByb3BhZ2F0aW9uU3RvcHBlZDogZmFsc2UsXG4gICAgICAgICAgICBzdG9wSW1tZWRpYXRlUHJvcGFnYXRpb24oKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5pbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWQgPSB0cnVlO1xuICAgICAgICAgICAgICAgIHN0b3BJbW1lZGlhdGVQcm9wYWdhdGlvbi5jYWxsKHRoaXMpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSk7XG4gICAgfVxufVxuXG5jbGFzcyBEaXNwYXRjaGVyIHtcbiAgICBjb25zdHJ1Y3RvcihhcHBsaWNhdGlvbikge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uID0gYXBwbGljYXRpb247XG4gICAgICAgIHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMgPSBuZXcgTWFwKCk7XG4gICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IHRydWU7XG4gICAgICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJzLmZvckVhY2goKGV2ZW50TGlzdGVuZXIpID0+IGV2ZW50TGlzdGVuZXIuY29ubmVjdCgpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgICAgIHRoaXMuZXZlbnRMaXN0ZW5lcnMuZm9yRWFjaCgoZXZlbnRMaXN0ZW5lcikgPT4gZXZlbnRMaXN0ZW5lci5kaXNjb25uZWN0KCkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBldmVudExpc3RlbmVycygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5ldmVudExpc3RlbmVyTWFwcy52YWx1ZXMoKSkucmVkdWNlKChsaXN0ZW5lcnMsIG1hcCkgPT4gbGlzdGVuZXJzLmNvbmNhdChBcnJheS5mcm9tKG1hcC52YWx1ZXMoKSkpLCBbXSk7XG4gICAgfVxuICAgIGJpbmRpbmdDb25uZWN0ZWQoYmluZGluZykge1xuICAgICAgICB0aGlzLmZldGNoRXZlbnRMaXN0ZW5lckZvckJpbmRpbmcoYmluZGluZykuYmluZGluZ0Nvbm5lY3RlZChiaW5kaW5nKTtcbiAgICB9XG4gICAgYmluZGluZ0Rpc2Nvbm5lY3RlZChiaW5kaW5nLCBjbGVhckV2ZW50TGlzdGVuZXJzID0gZmFsc2UpIHtcbiAgICAgICAgdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJGb3JCaW5kaW5nKGJpbmRpbmcpLmJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZyk7XG4gICAgICAgIGlmIChjbGVhckV2ZW50TGlzdGVuZXJzKVxuICAgICAgICAgICAgdGhpcy5jbGVhckV2ZW50TGlzdGVuZXJzRm9yQmluZGluZyhiaW5kaW5nKTtcbiAgICB9XG4gICAgaGFuZGxlRXJyb3IoZXJyb3IsIG1lc3NhZ2UsIGRldGFpbCA9IHt9KSB7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24uaGFuZGxlRXJyb3IoZXJyb3IsIGBFcnJvciAke21lc3NhZ2V9YCwgZGV0YWlsKTtcbiAgICB9XG4gICAgY2xlYXJFdmVudExpc3RlbmVyc0ZvckJpbmRpbmcoYmluZGluZykge1xuICAgICAgICBjb25zdCBldmVudExpc3RlbmVyID0gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJGb3JCaW5kaW5nKGJpbmRpbmcpO1xuICAgICAgICBpZiAoIWV2ZW50TGlzdGVuZXIuaGFzQmluZGluZ3MoKSkge1xuICAgICAgICAgICAgZXZlbnRMaXN0ZW5lci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLnJlbW92ZU1hcHBlZEV2ZW50TGlzdGVuZXJGb3IoYmluZGluZyk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVtb3ZlTWFwcGVkRXZlbnRMaXN0ZW5lckZvcihiaW5kaW5nKSB7XG4gICAgICAgIGNvbnN0IHsgZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zIH0gPSBiaW5kaW5nO1xuICAgICAgICBjb25zdCBldmVudExpc3RlbmVyTWFwID0gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJNYXBGb3JFdmVudFRhcmdldChldmVudFRhcmdldCk7XG4gICAgICAgIGNvbnN0IGNhY2hlS2V5ID0gdGhpcy5jYWNoZUtleShldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgIGV2ZW50TGlzdGVuZXJNYXAuZGVsZXRlKGNhY2hlS2V5KTtcbiAgICAgICAgaWYgKGV2ZW50TGlzdGVuZXJNYXAuc2l6ZSA9PSAwKVxuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVyTWFwcy5kZWxldGUoZXZlbnRUYXJnZXQpO1xuICAgIH1cbiAgICBmZXRjaEV2ZW50TGlzdGVuZXJGb3JCaW5kaW5nKGJpbmRpbmcpIHtcbiAgICAgICAgY29uc3QgeyBldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMgfSA9IGJpbmRpbmc7XG4gICAgICAgIHJldHVybiB0aGlzLmZldGNoRXZlbnRMaXN0ZW5lcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpO1xuICAgIH1cbiAgICBmZXRjaEV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKSB7XG4gICAgICAgIGNvbnN0IGV2ZW50TGlzdGVuZXJNYXAgPSB0aGlzLmZldGNoRXZlbnRMaXN0ZW5lck1hcEZvckV2ZW50VGFyZ2V0KGV2ZW50VGFyZ2V0KTtcbiAgICAgICAgY29uc3QgY2FjaGVLZXkgPSB0aGlzLmNhY2hlS2V5KGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICAgICAgbGV0IGV2ZW50TGlzdGVuZXIgPSBldmVudExpc3RlbmVyTWFwLmdldChjYWNoZUtleSk7XG4gICAgICAgIGlmICghZXZlbnRMaXN0ZW5lcikge1xuICAgICAgICAgICAgZXZlbnRMaXN0ZW5lciA9IHRoaXMuY3JlYXRlRXZlbnRMaXN0ZW5lcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpO1xuICAgICAgICAgICAgZXZlbnRMaXN0ZW5lck1hcC5zZXQoY2FjaGVLZXksIGV2ZW50TGlzdGVuZXIpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudExpc3RlbmVyO1xuICAgIH1cbiAgICBjcmVhdGVFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICBjb25zdCBldmVudExpc3RlbmVyID0gbmV3IEV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgZXZlbnRMaXN0ZW5lci5jb25uZWN0KCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGV2ZW50TGlzdGVuZXI7XG4gICAgfVxuICAgIGZldGNoRXZlbnRMaXN0ZW5lck1hcEZvckV2ZW50VGFyZ2V0KGV2ZW50VGFyZ2V0KSB7XG4gICAgICAgIGxldCBldmVudExpc3RlbmVyTWFwID0gdGhpcy5ldmVudExpc3RlbmVyTWFwcy5nZXQoZXZlbnRUYXJnZXQpO1xuICAgICAgICBpZiAoIWV2ZW50TGlzdGVuZXJNYXApIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXJNYXAgPSBuZXcgTWFwKCk7XG4gICAgICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJNYXBzLnNldChldmVudFRhcmdldCwgZXZlbnRMaXN0ZW5lck1hcCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGV2ZW50TGlzdGVuZXJNYXA7XG4gICAgfVxuICAgIGNhY2hlS2V5KGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKSB7XG4gICAgICAgIGNvbnN0IHBhcnRzID0gW2V2ZW50TmFtZV07XG4gICAgICAgIE9iamVjdC5rZXlzKGV2ZW50T3B0aW9ucylcbiAgICAgICAgICAgIC5zb3J0KClcbiAgICAgICAgICAgIC5mb3JFYWNoKChrZXkpID0+IHtcbiAgICAgICAgICAgIHBhcnRzLnB1c2goYCR7ZXZlbnRPcHRpb25zW2tleV0gPyBcIlwiIDogXCIhXCJ9JHtrZXl9YCk7XG4gICAgICAgIH0pO1xuICAgICAgICByZXR1cm4gcGFydHMuam9pbihcIjpcIik7XG4gICAgfVxufVxuXG5jb25zdCBkZWZhdWx0QWN0aW9uRGVzY3JpcHRvckZpbHRlcnMgPSB7XG4gICAgc3RvcCh7IGV2ZW50LCB2YWx1ZSB9KSB7XG4gICAgICAgIGlmICh2YWx1ZSlcbiAgICAgICAgICAgIGV2ZW50LnN0b3BQcm9wYWdhdGlvbigpO1xuICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICB9LFxuICAgIHByZXZlbnQoeyBldmVudCwgdmFsdWUgfSkge1xuICAgICAgICBpZiAodmFsdWUpXG4gICAgICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpO1xuICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICB9LFxuICAgIHNlbGYoeyBldmVudCwgdmFsdWUsIGVsZW1lbnQgfSkge1xuICAgICAgICBpZiAodmFsdWUpIHtcbiAgICAgICAgICAgIHJldHVybiBlbGVtZW50ID09PSBldmVudC50YXJnZXQ7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgIH0sXG59O1xuY29uc3QgZGVzY3JpcHRvclBhdHRlcm4gPSAvXig/Oig/OihbXi5dKz8pXFwrKT8oLis/KSg/OlxcLiguKz8pKT8oPzpAKHdpbmRvd3xkb2N1bWVudCkpPy0+KT8oLis/KSg/OiMoW146XSs/KSkoPzo6KC4rKSk/JC87XG5mdW5jdGlvbiBwYXJzZUFjdGlvbkRlc2NyaXB0b3JTdHJpbmcoZGVzY3JpcHRvclN0cmluZykge1xuICAgIGNvbnN0IHNvdXJjZSA9IGRlc2NyaXB0b3JTdHJpbmcudHJpbSgpO1xuICAgIGNvbnN0IG1hdGNoZXMgPSBzb3VyY2UubWF0Y2goZGVzY3JpcHRvclBhdHRlcm4pIHx8IFtdO1xuICAgIGxldCBldmVudE5hbWUgPSBtYXRjaGVzWzJdO1xuICAgIGxldCBrZXlGaWx0ZXIgPSBtYXRjaGVzWzNdO1xuICAgIGlmIChrZXlGaWx0ZXIgJiYgIVtcImtleWRvd25cIiwgXCJrZXl1cFwiLCBcImtleXByZXNzXCJdLmluY2x1ZGVzKGV2ZW50TmFtZSkpIHtcbiAgICAgICAgZXZlbnROYW1lICs9IGAuJHtrZXlGaWx0ZXJ9YDtcbiAgICAgICAga2V5RmlsdGVyID0gXCJcIjtcbiAgICB9XG4gICAgcmV0dXJuIHtcbiAgICAgICAgZXZlbnRUYXJnZXQ6IHBhcnNlRXZlbnRUYXJnZXQobWF0Y2hlc1s0XSksXG4gICAgICAgIGV2ZW50TmFtZSxcbiAgICAgICAgZXZlbnRPcHRpb25zOiBtYXRjaGVzWzddID8gcGFyc2VFdmVudE9wdGlvbnMobWF0Y2hlc1s3XSkgOiB7fSxcbiAgICAgICAgaWRlbnRpZmllcjogbWF0Y2hlc1s1XSxcbiAgICAgICAgbWV0aG9kTmFtZTogbWF0Y2hlc1s2XSxcbiAgICAgICAga2V5RmlsdGVyOiBtYXRjaGVzWzFdIHx8IGtleUZpbHRlcixcbiAgICB9O1xufVxuZnVuY3Rpb24gcGFyc2VFdmVudFRhcmdldChldmVudFRhcmdldE5hbWUpIHtcbiAgICBpZiAoZXZlbnRUYXJnZXROYW1lID09IFwid2luZG93XCIpIHtcbiAgICAgICAgcmV0dXJuIHdpbmRvdztcbiAgICB9XG4gICAgZWxzZSBpZiAoZXZlbnRUYXJnZXROYW1lID09IFwiZG9jdW1lbnRcIikge1xuICAgICAgICByZXR1cm4gZG9jdW1lbnQ7XG4gICAgfVxufVxuZnVuY3Rpb24gcGFyc2VFdmVudE9wdGlvbnMoZXZlbnRPcHRpb25zKSB7XG4gICAgcmV0dXJuIGV2ZW50T3B0aW9uc1xuICAgICAgICAuc3BsaXQoXCI6XCIpXG4gICAgICAgIC5yZWR1Y2UoKG9wdGlvbnMsIHRva2VuKSA9PiBPYmplY3QuYXNzaWduKG9wdGlvbnMsIHsgW3Rva2VuLnJlcGxhY2UoL14hLywgXCJcIildOiAhL14hLy50ZXN0KHRva2VuKSB9KSwge30pO1xufVxuZnVuY3Rpb24gc3RyaW5naWZ5RXZlbnRUYXJnZXQoZXZlbnRUYXJnZXQpIHtcbiAgICBpZiAoZXZlbnRUYXJnZXQgPT0gd2luZG93KSB7XG4gICAgICAgIHJldHVybiBcIndpbmRvd1wiO1xuICAgIH1cbiAgICBlbHNlIGlmIChldmVudFRhcmdldCA9PSBkb2N1bWVudCkge1xuICAgICAgICByZXR1cm4gXCJkb2N1bWVudFwiO1xuICAgIH1cbn1cblxuZnVuY3Rpb24gY2FtZWxpemUodmFsdWUpIHtcbiAgICByZXR1cm4gdmFsdWUucmVwbGFjZSgvKD86W18tXSkoW2EtejAtOV0pL2csIChfLCBjaGFyKSA9PiBjaGFyLnRvVXBwZXJDYXNlKCkpO1xufVxuZnVuY3Rpb24gbmFtZXNwYWNlQ2FtZWxpemUodmFsdWUpIHtcbiAgICByZXR1cm4gY2FtZWxpemUodmFsdWUucmVwbGFjZSgvLS0vZywgXCItXCIpLnJlcGxhY2UoL19fL2csIFwiX1wiKSk7XG59XG5mdW5jdGlvbiBjYXBpdGFsaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLmNoYXJBdCgwKS50b1VwcGVyQ2FzZSgpICsgdmFsdWUuc2xpY2UoMSk7XG59XG5mdW5jdGlvbiBkYXNoZXJpemUodmFsdWUpIHtcbiAgICByZXR1cm4gdmFsdWUucmVwbGFjZSgvKFtBLVpdKS9nLCAoXywgY2hhcikgPT4gYC0ke2NoYXIudG9Mb3dlckNhc2UoKX1gKTtcbn1cbmZ1bmN0aW9uIHRva2VuaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLm1hdGNoKC9bXlxcc10rL2cpIHx8IFtdO1xufVxuXG5mdW5jdGlvbiBpc1NvbWV0aGluZyhvYmplY3QpIHtcbiAgICByZXR1cm4gb2JqZWN0ICE9PSBudWxsICYmIG9iamVjdCAhPT0gdW5kZWZpbmVkO1xufVxuZnVuY3Rpb24gaGFzUHJvcGVydHkob2JqZWN0LCBwcm9wZXJ0eSkge1xuICAgIHJldHVybiBPYmplY3QucHJvdG90eXBlLmhhc093blByb3BlcnR5LmNhbGwob2JqZWN0LCBwcm9wZXJ0eSk7XG59XG5cbmNvbnN0IGFsbE1vZGlmaWVycyA9IFtcIm1ldGFcIiwgXCJjdHJsXCIsIFwiYWx0XCIsIFwic2hpZnRcIl07XG5jbGFzcyBBY3Rpb24ge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGluZGV4LCBkZXNjcmlwdG9yLCBzY2hlbWEpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5pbmRleCA9IGluZGV4O1xuICAgICAgICB0aGlzLmV2ZW50VGFyZ2V0ID0gZGVzY3JpcHRvci5ldmVudFRhcmdldCB8fCBlbGVtZW50O1xuICAgICAgICB0aGlzLmV2ZW50TmFtZSA9IGRlc2NyaXB0b3IuZXZlbnROYW1lIHx8IGdldERlZmF1bHRFdmVudE5hbWVGb3JFbGVtZW50KGVsZW1lbnQpIHx8IGVycm9yKFwibWlzc2luZyBldmVudCBuYW1lXCIpO1xuICAgICAgICB0aGlzLmV2ZW50T3B0aW9ucyA9IGRlc2NyaXB0b3IuZXZlbnRPcHRpb25zIHx8IHt9O1xuICAgICAgICB0aGlzLmlkZW50aWZpZXIgPSBkZXNjcmlwdG9yLmlkZW50aWZpZXIgfHwgZXJyb3IoXCJtaXNzaW5nIGlkZW50aWZpZXJcIik7XG4gICAgICAgIHRoaXMubWV0aG9kTmFtZSA9IGRlc2NyaXB0b3IubWV0aG9kTmFtZSB8fCBlcnJvcihcIm1pc3NpbmcgbWV0aG9kIG5hbWVcIik7XG4gICAgICAgIHRoaXMua2V5RmlsdGVyID0gZGVzY3JpcHRvci5rZXlGaWx0ZXIgfHwgXCJcIjtcbiAgICAgICAgdGhpcy5zY2hlbWEgPSBzY2hlbWE7XG4gICAgfVxuICAgIHN0YXRpYyBmb3JUb2tlbih0b2tlbiwgc2NoZW1hKSB7XG4gICAgICAgIHJldHVybiBuZXcgdGhpcyh0b2tlbi5lbGVtZW50LCB0b2tlbi5pbmRleCwgcGFyc2VBY3Rpb25EZXNjcmlwdG9yU3RyaW5nKHRva2VuLmNvbnRlbnQpLCBzY2hlbWEpO1xuICAgIH1cbiAgICB0b1N0cmluZygpIHtcbiAgICAgICAgY29uc3QgZXZlbnRGaWx0ZXIgPSB0aGlzLmtleUZpbHRlciA/IGAuJHt0aGlzLmtleUZpbHRlcn1gIDogXCJcIjtcbiAgICAgICAgY29uc3QgZXZlbnRUYXJnZXQgPSB0aGlzLmV2ZW50VGFyZ2V0TmFtZSA/IGBAJHt0aGlzLmV2ZW50VGFyZ2V0TmFtZX1gIDogXCJcIjtcbiAgICAgICAgcmV0dXJuIGAke3RoaXMuZXZlbnROYW1lfSR7ZXZlbnRGaWx0ZXJ9JHtldmVudFRhcmdldH0tPiR7dGhpcy5pZGVudGlmaWVyfSMke3RoaXMubWV0aG9kTmFtZX1gO1xuICAgIH1cbiAgICBzaG91bGRJZ25vcmVLZXlib2FyZEV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGlmICghdGhpcy5rZXlGaWx0ZXIpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBjb25zdCBmaWx0ZXJzID0gdGhpcy5rZXlGaWx0ZXIuc3BsaXQoXCIrXCIpO1xuICAgICAgICBpZiAodGhpcy5rZXlGaWx0ZXJEaXNzYXRpc2ZpZWQoZXZlbnQsIGZpbHRlcnMpKSB7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgICAgICBjb25zdCBzdGFuZGFyZEZpbHRlciA9IGZpbHRlcnMuZmlsdGVyKChrZXkpID0+ICFhbGxNb2RpZmllcnMuaW5jbHVkZXMoa2V5KSlbMF07XG4gICAgICAgIGlmICghc3RhbmRhcmRGaWx0ZXIpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBpZiAoIWhhc1Byb3BlcnR5KHRoaXMua2V5TWFwcGluZ3MsIHN0YW5kYXJkRmlsdGVyKSkge1xuICAgICAgICAgICAgZXJyb3IoYGNvbnRhaW5zIHVua25vd24ga2V5IGZpbHRlcjogJHt0aGlzLmtleUZpbHRlcn1gKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gdGhpcy5rZXlNYXBwaW5nc1tzdGFuZGFyZEZpbHRlcl0udG9Mb3dlckNhc2UoKSAhPT0gZXZlbnQua2V5LnRvTG93ZXJDYXNlKCk7XG4gICAgfVxuICAgIHNob3VsZElnbm9yZU1vdXNlRXZlbnQoZXZlbnQpIHtcbiAgICAgICAgaWYgKCF0aGlzLmtleUZpbHRlcikge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGNvbnN0IGZpbHRlcnMgPSBbdGhpcy5rZXlGaWx0ZXJdO1xuICAgICAgICBpZiAodGhpcy5rZXlGaWx0ZXJEaXNzYXRpc2ZpZWQoZXZlbnQsIGZpbHRlcnMpKSB7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgfVxuICAgIGdldCBwYXJhbXMoKSB7XG4gICAgICAgIGNvbnN0IHBhcmFtcyA9IHt9O1xuICAgICAgICBjb25zdCBwYXR0ZXJuID0gbmV3IFJlZ0V4cChgXmRhdGEtJHt0aGlzLmlkZW50aWZpZXJ9LSguKyktcGFyYW0kYCwgXCJpXCIpO1xuICAgICAgICBmb3IgKGNvbnN0IHsgbmFtZSwgdmFsdWUgfSBvZiBBcnJheS5mcm9tKHRoaXMuZWxlbWVudC5hdHRyaWJ1dGVzKSkge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2ggPSBuYW1lLm1hdGNoKHBhdHRlcm4pO1xuICAgICAgICAgICAgY29uc3Qga2V5ID0gbWF0Y2ggJiYgbWF0Y2hbMV07XG4gICAgICAgICAgICBpZiAoa2V5KSB7XG4gICAgICAgICAgICAgICAgcGFyYW1zW2NhbWVsaXplKGtleSldID0gdHlwZWNhc3QodmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgICAgIHJldHVybiBwYXJhbXM7XG4gICAgfVxuICAgIGdldCBldmVudFRhcmdldE5hbWUoKSB7XG4gICAgICAgIHJldHVybiBzdHJpbmdpZnlFdmVudFRhcmdldCh0aGlzLmV2ZW50VGFyZ2V0KTtcbiAgICB9XG4gICAgZ2V0IGtleU1hcHBpbmdzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY2hlbWEua2V5TWFwcGluZ3M7XG4gICAgfVxuICAgIGtleUZpbHRlckRpc3NhdGlzZmllZChldmVudCwgZmlsdGVycykge1xuICAgICAgICBjb25zdCBbbWV0YSwgY3RybCwgYWx0LCBzaGlmdF0gPSBhbGxNb2RpZmllcnMubWFwKChtb2RpZmllcikgPT4gZmlsdGVycy5pbmNsdWRlcyhtb2RpZmllcikpO1xuICAgICAgICByZXR1cm4gZXZlbnQubWV0YUtleSAhPT0gbWV0YSB8fCBldmVudC5jdHJsS2V5ICE9PSBjdHJsIHx8IGV2ZW50LmFsdEtleSAhPT0gYWx0IHx8IGV2ZW50LnNoaWZ0S2V5ICE9PSBzaGlmdDtcbiAgICB9XG59XG5jb25zdCBkZWZhdWx0RXZlbnROYW1lcyA9IHtcbiAgICBhOiAoKSA9PiBcImNsaWNrXCIsXG4gICAgYnV0dG9uOiAoKSA9PiBcImNsaWNrXCIsXG4gICAgZm9ybTogKCkgPT4gXCJzdWJtaXRcIixcbiAgICBkZXRhaWxzOiAoKSA9PiBcInRvZ2dsZVwiLFxuICAgIGlucHV0OiAoZSkgPT4gKGUuZ2V0QXR0cmlidXRlKFwidHlwZVwiKSA9PSBcInN1Ym1pdFwiID8gXCJjbGlja1wiIDogXCJpbnB1dFwiKSxcbiAgICBzZWxlY3Q6ICgpID0+IFwiY2hhbmdlXCIsXG4gICAgdGV4dGFyZWE6ICgpID0+IFwiaW5wdXRcIixcbn07XG5mdW5jdGlvbiBnZXREZWZhdWx0RXZlbnROYW1lRm9yRWxlbWVudChlbGVtZW50KSB7XG4gICAgY29uc3QgdGFnTmFtZSA9IGVsZW1lbnQudGFnTmFtZS50b0xvd2VyQ2FzZSgpO1xuICAgIGlmICh0YWdOYW1lIGluIGRlZmF1bHRFdmVudE5hbWVzKSB7XG4gICAgICAgIHJldHVybiBkZWZhdWx0RXZlbnROYW1lc1t0YWdOYW1lXShlbGVtZW50KTtcbiAgICB9XG59XG5mdW5jdGlvbiBlcnJvcihtZXNzYWdlKSB7XG4gICAgdGhyb3cgbmV3IEVycm9yKG1lc3NhZ2UpO1xufVxuZnVuY3Rpb24gdHlwZWNhc3QodmFsdWUpIHtcbiAgICB0cnkge1xuICAgICAgICByZXR1cm4gSlNPTi5wYXJzZSh2YWx1ZSk7XG4gICAgfVxuICAgIGNhdGNoIChvX08pIHtcbiAgICAgICAgcmV0dXJuIHZhbHVlO1xuICAgIH1cbn1cblxuY2xhc3MgQmluZGluZyB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgYWN0aW9uKSB7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMuYWN0aW9uID0gYWN0aW9uO1xuICAgIH1cbiAgICBnZXQgaW5kZXgoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFjdGlvbi5pbmRleDtcbiAgICB9XG4gICAgZ2V0IGV2ZW50VGFyZ2V0KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uZXZlbnRUYXJnZXQ7XG4gICAgfVxuICAgIGdldCBldmVudE9wdGlvbnMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFjdGlvbi5ldmVudE9wdGlvbnM7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGhhbmRsZUV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IGFjdGlvbkV2ZW50ID0gdGhpcy5wcmVwYXJlQWN0aW9uRXZlbnQoZXZlbnQpO1xuICAgICAgICBpZiAodGhpcy53aWxsQmVJbnZva2VkQnlFdmVudChldmVudCkgJiYgdGhpcy5hcHBseUV2ZW50TW9kaWZpZXJzKGFjdGlvbkV2ZW50KSkge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VXaXRoRXZlbnQoYWN0aW9uRXZlbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBldmVudE5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFjdGlvbi5ldmVudE5hbWU7XG4gICAgfVxuICAgIGdldCBtZXRob2QoKSB7XG4gICAgICAgIGNvbnN0IG1ldGhvZCA9IHRoaXMuY29udHJvbGxlclt0aGlzLm1ldGhvZE5hbWVdO1xuICAgICAgICBpZiAodHlwZW9mIG1ldGhvZCA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgICAgIHJldHVybiBtZXRob2Q7XG4gICAgICAgIH1cbiAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBBY3Rpb24gXCIke3RoaXMuYWN0aW9ufVwiIHJlZmVyZW5jZXMgdW5kZWZpbmVkIG1ldGhvZCBcIiR7dGhpcy5tZXRob2ROYW1lfVwiYCk7XG4gICAgfVxuICAgIGFwcGx5RXZlbnRNb2RpZmllcnMoZXZlbnQpIHtcbiAgICAgICAgY29uc3QgeyBlbGVtZW50IH0gPSB0aGlzLmFjdGlvbjtcbiAgICAgICAgY29uc3QgeyBhY3Rpb25EZXNjcmlwdG9yRmlsdGVycyB9ID0gdGhpcy5jb250ZXh0LmFwcGxpY2F0aW9uO1xuICAgICAgICBjb25zdCB7IGNvbnRyb2xsZXIgfSA9IHRoaXMuY29udGV4dDtcbiAgICAgICAgbGV0IHBhc3NlcyA9IHRydWU7XG4gICAgICAgIGZvciAoY29uc3QgW25hbWUsIHZhbHVlXSBvZiBPYmplY3QuZW50cmllcyh0aGlzLmV2ZW50T3B0aW9ucykpIHtcbiAgICAgICAgICAgIGlmIChuYW1lIGluIGFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgZmlsdGVyID0gYWN0aW9uRGVzY3JpcHRvckZpbHRlcnNbbmFtZV07XG4gICAgICAgICAgICAgICAgcGFzc2VzID0gcGFzc2VzICYmIGZpbHRlcih7IG5hbWUsIHZhbHVlLCBldmVudCwgZWxlbWVudCwgY29udHJvbGxlciB9KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgIGNvbnRpbnVlO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgICAgIHJldHVybiBwYXNzZXM7XG4gICAgfVxuICAgIHByZXBhcmVBY3Rpb25FdmVudChldmVudCkge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihldmVudCwgeyBwYXJhbXM6IHRoaXMuYWN0aW9uLnBhcmFtcyB9KTtcbiAgICB9XG4gICAgaW52b2tlV2l0aEV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IHsgdGFyZ2V0LCBjdXJyZW50VGFyZ2V0IH0gPSBldmVudDtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIHRoaXMubWV0aG9kLmNhbGwodGhpcy5jb250cm9sbGVyLCBldmVudCk7XG4gICAgICAgICAgICB0aGlzLmNvbnRleHQubG9nRGVidWdBY3Rpdml0eSh0aGlzLm1ldGhvZE5hbWUsIHsgZXZlbnQsIHRhcmdldCwgY3VycmVudFRhcmdldCwgYWN0aW9uOiB0aGlzLm1ldGhvZE5hbWUgfSk7XG4gICAgICAgIH1cbiAgICAgICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICBjb25zdCB7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXIsIGVsZW1lbnQsIGluZGV4IH0gPSB0aGlzO1xuICAgICAgICAgICAgY29uc3QgZGV0YWlsID0geyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50LCBpbmRleCwgZXZlbnQgfTtcbiAgICAgICAgICAgIHRoaXMuY29udGV4dC5oYW5kbGVFcnJvcihlcnJvciwgYGludm9raW5nIGFjdGlvbiBcIiR7dGhpcy5hY3Rpb259XCJgLCBkZXRhaWwpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHdpbGxCZUludm9rZWRCeUV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IGV2ZW50VGFyZ2V0ID0gZXZlbnQudGFyZ2V0O1xuICAgICAgICBpZiAoZXZlbnQgaW5zdGFuY2VvZiBLZXlib2FyZEV2ZW50ICYmIHRoaXMuYWN0aW9uLnNob3VsZElnbm9yZUtleWJvYXJkRXZlbnQoZXZlbnQpKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKGV2ZW50IGluc3RhbmNlb2YgTW91c2VFdmVudCAmJiB0aGlzLmFjdGlvbi5zaG91bGRJZ25vcmVNb3VzZUV2ZW50KGV2ZW50KSkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmICh0aGlzLmVsZW1lbnQgPT09IGV2ZW50VGFyZ2V0KSB7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIGlmIChldmVudFRhcmdldCBpbnN0YW5jZW9mIEVsZW1lbnQgJiYgdGhpcy5lbGVtZW50LmNvbnRhaW5zKGV2ZW50VGFyZ2V0KSkge1xuICAgICAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuY29udGFpbnNFbGVtZW50KGV2ZW50VGFyZ2V0KTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmNvbnRhaW5zRWxlbWVudCh0aGlzLmFjdGlvbi5lbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgY29udHJvbGxlcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5jb250cm9sbGVyO1xuICAgIH1cbiAgICBnZXQgbWV0aG9kTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYWN0aW9uLm1ldGhvZE5hbWU7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgc2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NvcGU7XG4gICAgfVxufVxuXG5jbGFzcyBFbGVtZW50T2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlckluaXQgPSB7IGF0dHJpYnV0ZXM6IHRydWUsIGNoaWxkTGlzdDogdHJ1ZSwgc3VidHJlZTogdHJ1ZSB9O1xuICAgICAgICB0aGlzLmVsZW1lbnQgPSBlbGVtZW50O1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLmVsZW1lbnRzID0gbmV3IFNldCgpO1xuICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIgPSBuZXcgTXV0YXRpb25PYnNlcnZlcigobXV0YXRpb25zKSA9PiB0aGlzLnByb2Nlc3NNdXRhdGlvbnMobXV0YXRpb25zKSk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5vYnNlcnZlKHRoaXMuZWxlbWVudCwgdGhpcy5tdXRhdGlvbk9ic2VydmVySW5pdCk7XG4gICAgICAgICAgICB0aGlzLnJlZnJlc2goKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgY2FsbGJhY2soKTtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5vYnNlcnZlKHRoaXMuZWxlbWVudCwgdGhpcy5tdXRhdGlvbk9ic2VydmVySW5pdCk7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci50YWtlUmVjb3JkcygpO1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoZXMgPSBuZXcgU2V0KHRoaXMubWF0Y2hFbGVtZW50c0luVHJlZSgpKTtcbiAgICAgICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiBBcnJheS5mcm9tKHRoaXMuZWxlbWVudHMpKSB7XG4gICAgICAgICAgICAgICAgaWYgKCFtYXRjaGVzLmhhcyhlbGVtZW50KSkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLnJlbW92ZUVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZm9yIChjb25zdCBlbGVtZW50IG9mIEFycmF5LmZyb20obWF0Y2hlcykpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmFkZEVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc011dGF0aW9ucyhtdXRhdGlvbnMpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgZm9yIChjb25zdCBtdXRhdGlvbiBvZiBtdXRhdGlvbnMpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnByb2Nlc3NNdXRhdGlvbihtdXRhdGlvbik7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc011dGF0aW9uKG11dGF0aW9uKSB7XG4gICAgICAgIGlmIChtdXRhdGlvbi50eXBlID09IFwiYXR0cmlidXRlc1wiKSB7XG4gICAgICAgICAgICB0aGlzLnByb2Nlc3NBdHRyaWJ1dGVDaGFuZ2UobXV0YXRpb24udGFyZ2V0LCBtdXRhdGlvbi5hdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIGlmIChtdXRhdGlvbi50eXBlID09IFwiY2hpbGRMaXN0XCIpIHtcbiAgICAgICAgICAgIHRoaXMucHJvY2Vzc1JlbW92ZWROb2RlcyhtdXRhdGlvbi5yZW1vdmVkTm9kZXMpO1xuICAgICAgICAgICAgdGhpcy5wcm9jZXNzQWRkZWROb2RlcyhtdXRhdGlvbi5hZGRlZE5vZGVzKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzQXR0cmlidXRlQ2hhbmdlKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZWxlbWVudHMuaGFzKGVsZW1lbnQpKSB7XG4gICAgICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5lbGVtZW50QXR0cmlidXRlQ2hhbmdlZCAmJiB0aGlzLm1hdGNoRWxlbWVudChlbGVtZW50KSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZUNoYW5nZWQoZWxlbWVudCwgYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICB0aGlzLnJlbW92ZUVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAodGhpcy5tYXRjaEVsZW1lbnQoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuYWRkRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzUmVtb3ZlZE5vZGVzKG5vZGVzKSB7XG4gICAgICAgIGZvciAoY29uc3Qgbm9kZSBvZiBBcnJheS5mcm9tKG5vZGVzKSkge1xuICAgICAgICAgICAgY29uc3QgZWxlbWVudCA9IHRoaXMuZWxlbWVudEZyb21Ob2RlKG5vZGUpO1xuICAgICAgICAgICAgaWYgKGVsZW1lbnQpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnByb2Nlc3NUcmVlKGVsZW1lbnQsIHRoaXMucmVtb3ZlRWxlbWVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc0FkZGVkTm9kZXMobm9kZXMpIHtcbiAgICAgICAgZm9yIChjb25zdCBub2RlIG9mIEFycmF5LmZyb20obm9kZXMpKSB7XG4gICAgICAgICAgICBjb25zdCBlbGVtZW50ID0gdGhpcy5lbGVtZW50RnJvbU5vZGUobm9kZSk7XG4gICAgICAgICAgICBpZiAoZWxlbWVudCAmJiB0aGlzLmVsZW1lbnRJc0FjdGl2ZShlbGVtZW50KSkge1xuICAgICAgICAgICAgICAgIHRoaXMucHJvY2Vzc1RyZWUoZWxlbWVudCwgdGhpcy5hZGRFbGVtZW50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBtYXRjaEVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICByZXR1cm4gdGhpcy5kZWxlZ2F0ZS5tYXRjaEVsZW1lbnQoZWxlbWVudCk7XG4gICAgfVxuICAgIG1hdGNoRWxlbWVudHNJblRyZWUodHJlZSA9IHRoaXMuZWxlbWVudCkge1xuICAgICAgICByZXR1cm4gdGhpcy5kZWxlZ2F0ZS5tYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUpO1xuICAgIH1cbiAgICBwcm9jZXNzVHJlZSh0cmVlLCBwcm9jZXNzb3IpIHtcbiAgICAgICAgZm9yIChjb25zdCBlbGVtZW50IG9mIHRoaXMubWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKSkge1xuICAgICAgICAgICAgcHJvY2Vzc29yLmNhbGwodGhpcywgZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudEZyb21Ob2RlKG5vZGUpIHtcbiAgICAgICAgaWYgKG5vZGUubm9kZVR5cGUgPT0gTm9kZS5FTEVNRU5UX05PREUpIHtcbiAgICAgICAgICAgIHJldHVybiBub2RlO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRJc0FjdGl2ZShlbGVtZW50KSB7XG4gICAgICAgIGlmIChlbGVtZW50LmlzQ29ubmVjdGVkICE9IHRoaXMuZWxlbWVudC5pc0Nvbm5lY3RlZCkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5jb250YWlucyhlbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBhZGRFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgaWYgKCF0aGlzLmVsZW1lbnRzLmhhcyhlbGVtZW50KSkge1xuICAgICAgICAgICAgaWYgKHRoaXMuZWxlbWVudElzQWN0aXZlKGVsZW1lbnQpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5lbGVtZW50cy5hZGQoZWxlbWVudCk7XG4gICAgICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWQpIHtcbiAgICAgICAgICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50TWF0Y2hlZChlbGVtZW50KTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVtb3ZlRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGlmICh0aGlzLmVsZW1lbnRzLmhhcyhlbGVtZW50KSkge1xuICAgICAgICAgICAgdGhpcy5lbGVtZW50cy5kZWxldGUoZWxlbWVudCk7XG4gICAgICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5lbGVtZW50VW5tYXRjaGVkKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50VW5tYXRjaGVkKGVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxufVxuXG5jbGFzcyBBdHRyaWJ1dGVPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVOYW1lID0gYXR0cmlidXRlTmFtZTtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlciA9IG5ldyBFbGVtZW50T2JzZXJ2ZXIoZWxlbWVudCwgdGhpcyk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IHNlbGVjdG9yKCkge1xuICAgICAgICByZXR1cm4gYFske3RoaXMuYXR0cmlidXRlTmFtZX1dYDtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHBhdXNlKGNhbGxiYWNrKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnBhdXNlKGNhbGxiYWNrKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RhcnRlZDtcbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnQuaGFzQXR0cmlidXRlKHRoaXMuYXR0cmlidXRlTmFtZSk7XG4gICAgfVxuICAgIG1hdGNoRWxlbWVudHNJblRyZWUodHJlZSkge1xuICAgICAgICBjb25zdCBtYXRjaCA9IHRoaXMubWF0Y2hFbGVtZW50KHRyZWUpID8gW3RyZWVdIDogW107XG4gICAgICAgIGNvbnN0IG1hdGNoZXMgPSBBcnJheS5mcm9tKHRyZWUucXVlcnlTZWxlY3RvckFsbCh0aGlzLnNlbGVjdG9yKSk7XG4gICAgICAgIHJldHVybiBtYXRjaC5jb25jYXQobWF0Y2hlcyk7XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkKGVsZW1lbnQpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWRBdHRyaWJ1dGUpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWRBdHRyaWJ1dGUoZWxlbWVudCwgdGhpcy5hdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkKGVsZW1lbnQpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZEF0dHJpYnV0ZSkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50VW5tYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQsIHRoaXMuYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudEF0dHJpYnV0ZUNoYW5nZWQoZWxlbWVudCwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5lbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkICYmIHRoaXMuYXR0cmlidXRlTmFtZSA9PSBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRBdHRyaWJ1dGVWYWx1ZUNoYW5nZWQoZWxlbWVudCwgYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmZ1bmN0aW9uIGFkZChtYXAsIGtleSwgdmFsdWUpIHtcbiAgICBmZXRjaChtYXAsIGtleSkuYWRkKHZhbHVlKTtcbn1cbmZ1bmN0aW9uIGRlbChtYXAsIGtleSwgdmFsdWUpIHtcbiAgICBmZXRjaChtYXAsIGtleSkuZGVsZXRlKHZhbHVlKTtcbiAgICBwcnVuZShtYXAsIGtleSk7XG59XG5mdW5jdGlvbiBmZXRjaChtYXAsIGtleSkge1xuICAgIGxldCB2YWx1ZXMgPSBtYXAuZ2V0KGtleSk7XG4gICAgaWYgKCF2YWx1ZXMpIHtcbiAgICAgICAgdmFsdWVzID0gbmV3IFNldCgpO1xuICAgICAgICBtYXAuc2V0KGtleSwgdmFsdWVzKTtcbiAgICB9XG4gICAgcmV0dXJuIHZhbHVlcztcbn1cbmZ1bmN0aW9uIHBydW5lKG1hcCwga2V5KSB7XG4gICAgY29uc3QgdmFsdWVzID0gbWFwLmdldChrZXkpO1xuICAgIGlmICh2YWx1ZXMgIT0gbnVsbCAmJiB2YWx1ZXMuc2l6ZSA9PSAwKSB7XG4gICAgICAgIG1hcC5kZWxldGUoa2V5KTtcbiAgICB9XG59XG5cbmNsYXNzIE11bHRpbWFwIHtcbiAgICBjb25zdHJ1Y3RvcigpIHtcbiAgICAgICAgdGhpcy52YWx1ZXNCeUtleSA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgZ2V0IGtleXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkua2V5cygpKTtcbiAgICB9XG4gICAgZ2V0IHZhbHVlcygpIHtcbiAgICAgICAgY29uc3Qgc2V0cyA9IEFycmF5LmZyb20odGhpcy52YWx1ZXNCeUtleS52YWx1ZXMoKSk7XG4gICAgICAgIHJldHVybiBzZXRzLnJlZHVjZSgodmFsdWVzLCBzZXQpID0+IHZhbHVlcy5jb25jYXQoQXJyYXkuZnJvbShzZXQpKSwgW10pO1xuICAgIH1cbiAgICBnZXQgc2l6ZSgpIHtcbiAgICAgICAgY29uc3Qgc2V0cyA9IEFycmF5LmZyb20odGhpcy52YWx1ZXNCeUtleS52YWx1ZXMoKSk7XG4gICAgICAgIHJldHVybiBzZXRzLnJlZHVjZSgoc2l6ZSwgc2V0KSA9PiBzaXplICsgc2V0LnNpemUsIDApO1xuICAgIH1cbiAgICBhZGQoa2V5LCB2YWx1ZSkge1xuICAgICAgICBhZGQodGhpcy52YWx1ZXNCeUtleSwga2V5LCB2YWx1ZSk7XG4gICAgfVxuICAgIGRlbGV0ZShrZXksIHZhbHVlKSB7XG4gICAgICAgIGRlbCh0aGlzLnZhbHVlc0J5S2V5LCBrZXksIHZhbHVlKTtcbiAgICB9XG4gICAgaGFzKGtleSwgdmFsdWUpIHtcbiAgICAgICAgY29uc3QgdmFsdWVzID0gdGhpcy52YWx1ZXNCeUtleS5nZXQoa2V5KTtcbiAgICAgICAgcmV0dXJuIHZhbHVlcyAhPSBudWxsICYmIHZhbHVlcy5oYXModmFsdWUpO1xuICAgIH1cbiAgICBoYXNLZXkoa2V5KSB7XG4gICAgICAgIHJldHVybiB0aGlzLnZhbHVlc0J5S2V5LmhhcyhrZXkpO1xuICAgIH1cbiAgICBoYXNWYWx1ZSh2YWx1ZSkge1xuICAgICAgICBjb25zdCBzZXRzID0gQXJyYXkuZnJvbSh0aGlzLnZhbHVlc0J5S2V5LnZhbHVlcygpKTtcbiAgICAgICAgcmV0dXJuIHNldHMuc29tZSgoc2V0KSA9PiBzZXQuaGFzKHZhbHVlKSk7XG4gICAgfVxuICAgIGdldFZhbHVlc0ZvcktleShrZXkpIHtcbiAgICAgICAgY29uc3QgdmFsdWVzID0gdGhpcy52YWx1ZXNCeUtleS5nZXQoa2V5KTtcbiAgICAgICAgcmV0dXJuIHZhbHVlcyA/IEFycmF5LmZyb20odmFsdWVzKSA6IFtdO1xuICAgIH1cbiAgICBnZXRLZXlzRm9yVmFsdWUodmFsdWUpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy52YWx1ZXNCeUtleSlcbiAgICAgICAgICAgIC5maWx0ZXIoKFtfa2V5LCB2YWx1ZXNdKSA9PiB2YWx1ZXMuaGFzKHZhbHVlKSlcbiAgICAgICAgICAgIC5tYXAoKFtrZXksIF92YWx1ZXNdKSA9PiBrZXkpO1xuICAgIH1cbn1cblxuY2xhc3MgSW5kZXhlZE11bHRpbWFwIGV4dGVuZHMgTXVsdGltYXAge1xuICAgIGNvbnN0cnVjdG9yKCkge1xuICAgICAgICBzdXBlcigpO1xuICAgICAgICB0aGlzLmtleXNCeVZhbHVlID0gbmV3IE1hcCgpO1xuICAgIH1cbiAgICBnZXQgdmFsdWVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmtleXNCeVZhbHVlLmtleXMoKSk7XG4gICAgfVxuICAgIGFkZChrZXksIHZhbHVlKSB7XG4gICAgICAgIHN1cGVyLmFkZChrZXksIHZhbHVlKTtcbiAgICAgICAgYWRkKHRoaXMua2V5c0J5VmFsdWUsIHZhbHVlLCBrZXkpO1xuICAgIH1cbiAgICBkZWxldGUoa2V5LCB2YWx1ZSkge1xuICAgICAgICBzdXBlci5kZWxldGUoa2V5LCB2YWx1ZSk7XG4gICAgICAgIGRlbCh0aGlzLmtleXNCeVZhbHVlLCB2YWx1ZSwga2V5KTtcbiAgICB9XG4gICAgaGFzVmFsdWUodmFsdWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMua2V5c0J5VmFsdWUuaGFzKHZhbHVlKTtcbiAgICB9XG4gICAgZ2V0S2V5c0ZvclZhbHVlKHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHNldCA9IHRoaXMua2V5c0J5VmFsdWUuZ2V0KHZhbHVlKTtcbiAgICAgICAgcmV0dXJuIHNldCA/IEFycmF5LmZyb20oc2V0KSA6IFtdO1xuICAgIH1cbn1cblxuY2xhc3MgU2VsZWN0b3JPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgc2VsZWN0b3IsIGRlbGVnYXRlLCBkZXRhaWxzKSB7XG4gICAgICAgIHRoaXMuX3NlbGVjdG9yID0gc2VsZWN0b3I7XG4gICAgICAgIHRoaXMuZGV0YWlscyA9IGRldGFpbHM7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyID0gbmV3IEVsZW1lbnRPYnNlcnZlcihlbGVtZW50LCB0aGlzKTtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLm1hdGNoZXNCeUVsZW1lbnQgPSBuZXcgTXVsdGltYXAoKTtcbiAgICB9XG4gICAgZ2V0IHN0YXJ0ZWQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnRPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBnZXQgc2VsZWN0b3IoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLl9zZWxlY3RvcjtcbiAgICB9XG4gICAgc2V0IHNlbGVjdG9yKHNlbGVjdG9yKSB7XG4gICAgICAgIHRoaXMuX3NlbGVjdG9yID0gc2VsZWN0b3I7XG4gICAgICAgIHRoaXMucmVmcmVzaCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgcGF1c2UoY2FsbGJhY2spIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIucGF1c2UoY2FsbGJhY2spO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnJlZnJlc2goKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnRPYnNlcnZlci5lbGVtZW50O1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCB7IHNlbGVjdG9yIH0gPSB0aGlzO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoZXMgPSBlbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpO1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuc2VsZWN0b3JNYXRjaEVsZW1lbnQpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gbWF0Y2hlcyAmJiB0aGlzLmRlbGVnYXRlLnNlbGVjdG9yTWF0Y2hFbGVtZW50KGVsZW1lbnQsIHRoaXMuZGV0YWlscyk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICByZXR1cm4gbWF0Y2hlcztcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBtYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUpIHtcbiAgICAgICAgY29uc3QgeyBzZWxlY3RvciB9ID0gdGhpcztcbiAgICAgICAgaWYgKHNlbGVjdG9yKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaCA9IHRoaXMubWF0Y2hFbGVtZW50KHRyZWUpID8gW3RyZWVdIDogW107XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gQXJyYXkuZnJvbSh0cmVlLnF1ZXJ5U2VsZWN0b3JBbGwoc2VsZWN0b3IpKS5maWx0ZXIoKG1hdGNoKSA9PiB0aGlzLm1hdGNoRWxlbWVudChtYXRjaCkpO1xuICAgICAgICAgICAgcmV0dXJuIG1hdGNoLmNvbmNhdChtYXRjaGVzKTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiBbXTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgdGhpcy5zZWxlY3Rvck1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCBzZWxlY3RvcnMgPSB0aGlzLm1hdGNoZXNCeUVsZW1lbnQuZ2V0S2V5c0ZvclZhbHVlKGVsZW1lbnQpO1xuICAgICAgICBmb3IgKGNvbnN0IHNlbGVjdG9yIG9mIHNlbGVjdG9ycykge1xuICAgICAgICAgICAgdGhpcy5zZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudEF0dHJpYnV0ZUNoYW5nZWQoZWxlbWVudCwgX2F0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3QgeyBzZWxlY3RvciB9ID0gdGhpcztcbiAgICAgICAgaWYgKHNlbGVjdG9yKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gdGhpcy5tYXRjaEVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVkQmVmb3JlID0gdGhpcy5tYXRjaGVzQnlFbGVtZW50LmhhcyhzZWxlY3RvciwgZWxlbWVudCk7XG4gICAgICAgICAgICBpZiAobWF0Y2hlcyAmJiAhbWF0Y2hlZEJlZm9yZSkge1xuICAgICAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGVsc2UgaWYgKCFtYXRjaGVzICYmIG1hdGNoZWRCZWZvcmUpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnNlbGVjdG9yVW5tYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBzZWxlY3Rvck1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpIHtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zZWxlY3Rvck1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IsIHRoaXMuZGV0YWlscyk7XG4gICAgICAgIHRoaXMubWF0Y2hlc0J5RWxlbWVudC5hZGQoc2VsZWN0b3IsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBzZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcikge1xuICAgICAgICB0aGlzLmRlbGVnYXRlLnNlbGVjdG9yVW5tYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yLCB0aGlzLmRldGFpbHMpO1xuICAgICAgICB0aGlzLm1hdGNoZXNCeUVsZW1lbnQuZGVsZXRlKHNlbGVjdG9yLCBlbGVtZW50KTtcbiAgICB9XG59XG5cbmNsYXNzIFN0cmluZ01hcE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmVsZW1lbnQgPSBlbGVtZW50O1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICB0aGlzLnN0cmluZ01hcCA9IG5ldyBNYXAoKTtcbiAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyID0gbmV3IE11dGF0aW9uT2JzZXJ2ZXIoKG11dGF0aW9ucykgPT4gdGhpcy5wcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IHRydWU7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIub2JzZXJ2ZSh0aGlzLmVsZW1lbnQsIHsgYXR0cmlidXRlczogdHJ1ZSwgYXR0cmlidXRlT2xkVmFsdWU6IHRydWUgfSk7XG4gICAgICAgICAgICB0aGlzLnJlZnJlc2goKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIudGFrZVJlY29yZHMoKTtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGF0dHJpYnV0ZU5hbWUgb2YgdGhpcy5rbm93bkF0dHJpYnV0ZU5hbWVzKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5yZWZyZXNoQXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUsIG51bGwpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbnMobXV0YXRpb25zKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGZvciAoY29uc3QgbXV0YXRpb24gb2YgbXV0YXRpb25zKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzTXV0YXRpb24obXV0YXRpb24pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbihtdXRhdGlvbikge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gbXV0YXRpb24uYXR0cmlidXRlTmFtZTtcbiAgICAgICAgaWYgKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMucmVmcmVzaEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lLCBtdXRhdGlvbi5vbGRWYWx1ZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVmcmVzaEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSkge1xuICAgICAgICBjb25zdCBrZXkgPSB0aGlzLmRlbGVnYXRlLmdldFN0cmluZ01hcEtleUZvckF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKGtleSAhPSBudWxsKSB7XG4gICAgICAgICAgICBpZiAoIXRoaXMuc3RyaW5nTWFwLmhhcyhhdHRyaWJ1dGVOYW1lKSkge1xuICAgICAgICAgICAgICAgIHRoaXMuc3RyaW5nTWFwS2V5QWRkZWQoa2V5LCBhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gdGhpcy5lbGVtZW50LmdldEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgICAgIGlmICh0aGlzLnN0cmluZ01hcC5nZXQoYXR0cmlidXRlTmFtZSkgIT0gdmFsdWUpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwga2V5LCBvbGRWYWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBpZiAodmFsdWUgPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IG9sZFZhbHVlID0gdGhpcy5zdHJpbmdNYXAuZ2V0KGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICAgICAgICAgIHRoaXMuc3RyaW5nTWFwLmRlbGV0ZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgICAgICAgICBpZiAob2xkVmFsdWUpXG4gICAgICAgICAgICAgICAgICAgIHRoaXMuc3RyaW5nTWFwS2V5UmVtb3ZlZChrZXksIGF0dHJpYnV0ZU5hbWUsIG9sZFZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgIHRoaXMuc3RyaW5nTWFwLnNldChhdHRyaWJ1dGVOYW1lLCB2YWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RyaW5nTWFwS2V5QWRkZWQoa2V5LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLnN0cmluZ01hcEtleUFkZGVkKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLnN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RyaW5nTWFwVmFsdWVDaGFuZ2VkKHZhbHVlLCBrZXksIG9sZFZhbHVlKSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLnN0cmluZ01hcFZhbHVlQ2hhbmdlZCkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBWYWx1ZUNoYW5nZWQodmFsdWUsIGtleSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlSZW1vdmVkKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLnN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGtub3duQXR0cmlidXRlTmFtZXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKG5ldyBTZXQodGhpcy5jdXJyZW50QXR0cmlidXRlTmFtZXMuY29uY2F0KHRoaXMucmVjb3JkZWRBdHRyaWJ1dGVOYW1lcykpKTtcbiAgICB9XG4gICAgZ2V0IGN1cnJlbnRBdHRyaWJ1dGVOYW1lcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5lbGVtZW50LmF0dHJpYnV0ZXMpLm1hcCgoYXR0cmlidXRlKSA9PiBhdHRyaWJ1dGUubmFtZSk7XG4gICAgfVxuICAgIGdldCByZWNvcmRlZEF0dHJpYnV0ZU5hbWVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnN0cmluZ01hcC5rZXlzKCkpO1xuICAgIH1cbn1cblxuY2xhc3MgVG9rZW5MaXN0T2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIgPSBuZXcgQXR0cmlidXRlT2JzZXJ2ZXIoZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgdGhpcyk7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy50b2tlbnNCeUVsZW1lbnQgPSBuZXcgTXVsdGltYXAoKTtcbiAgICB9XG4gICAgZ2V0IHN0YXJ0ZWQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLnN0YXJ0ZWQ7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHBhdXNlKGNhbGxiYWNrKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIucGF1c2UoY2FsbGJhY2spO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgYXR0cmlidXRlTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIuYXR0cmlidXRlTmFtZTtcbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWRBdHRyaWJ1dGUoZWxlbWVudCkge1xuICAgICAgICB0aGlzLnRva2Vuc01hdGNoZWQodGhpcy5yZWFkVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KSk7XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVWYWx1ZUNoYW5nZWQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCBbdW5tYXRjaGVkVG9rZW5zLCBtYXRjaGVkVG9rZW5zXSA9IHRoaXMucmVmcmVzaFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgIHRoaXMudG9rZW5zVW5tYXRjaGVkKHVubWF0Y2hlZFRva2Vucyk7XG4gICAgICAgIHRoaXMudG9rZW5zTWF0Y2hlZChtYXRjaGVkVG9rZW5zKTtcbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZEF0dHJpYnV0ZShlbGVtZW50KSB7XG4gICAgICAgIHRoaXMudG9rZW5zVW5tYXRjaGVkKHRoaXMudG9rZW5zQnlFbGVtZW50LmdldFZhbHVlc0ZvcktleShlbGVtZW50KSk7XG4gICAgfVxuICAgIHRva2Vuc01hdGNoZWQodG9rZW5zKSB7XG4gICAgICAgIHRva2Vucy5mb3JFYWNoKCh0b2tlbikgPT4gdGhpcy50b2tlbk1hdGNoZWQodG9rZW4pKTtcbiAgICB9XG4gICAgdG9rZW5zVW5tYXRjaGVkKHRva2Vucykge1xuICAgICAgICB0b2tlbnMuZm9yRWFjaCgodG9rZW4pID0+IHRoaXMudG9rZW5Vbm1hdGNoZWQodG9rZW4pKTtcbiAgICB9XG4gICAgdG9rZW5NYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUudG9rZW5NYXRjaGVkKHRva2VuKTtcbiAgICAgICAgdGhpcy50b2tlbnNCeUVsZW1lbnQuYWRkKHRva2VuLmVsZW1lbnQsIHRva2VuKTtcbiAgICB9XG4gICAgdG9rZW5Vbm1hdGNoZWQodG9rZW4pIHtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZS50b2tlblVubWF0Y2hlZCh0b2tlbik7XG4gICAgICAgIHRoaXMudG9rZW5zQnlFbGVtZW50LmRlbGV0ZSh0b2tlbi5lbGVtZW50LCB0b2tlbik7XG4gICAgfVxuICAgIHJlZnJlc2hUb2tlbnNGb3JFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgcHJldmlvdXNUb2tlbnMgPSB0aGlzLnRva2Vuc0J5RWxlbWVudC5nZXRWYWx1ZXNGb3JLZXkoZWxlbWVudCk7XG4gICAgICAgIGNvbnN0IGN1cnJlbnRUb2tlbnMgPSB0aGlzLnJlYWRUb2tlbnNGb3JFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICBjb25zdCBmaXJzdERpZmZlcmluZ0luZGV4ID0gemlwKHByZXZpb3VzVG9rZW5zLCBjdXJyZW50VG9rZW5zKS5maW5kSW5kZXgoKFtwcmV2aW91c1Rva2VuLCBjdXJyZW50VG9rZW5dKSA9PiAhdG9rZW5zQXJlRXF1YWwocHJldmlvdXNUb2tlbiwgY3VycmVudFRva2VuKSk7XG4gICAgICAgIGlmIChmaXJzdERpZmZlcmluZ0luZGV4ID09IC0xKSB7XG4gICAgICAgICAgICByZXR1cm4gW1tdLCBbXV07XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gW3ByZXZpb3VzVG9rZW5zLnNsaWNlKGZpcnN0RGlmZmVyaW5nSW5kZXgpLCBjdXJyZW50VG9rZW5zLnNsaWNlKGZpcnN0RGlmZmVyaW5nSW5kZXgpXTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWFkVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLmF0dHJpYnV0ZU5hbWU7XG4gICAgICAgIGNvbnN0IHRva2VuU3RyaW5nID0gZWxlbWVudC5nZXRBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSkgfHwgXCJcIjtcbiAgICAgICAgcmV0dXJuIHBhcnNlVG9rZW5TdHJpbmcodG9rZW5TdHJpbmcsIGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbn1cbmZ1bmN0aW9uIHBhcnNlVG9rZW5TdHJpbmcodG9rZW5TdHJpbmcsIGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICByZXR1cm4gdG9rZW5TdHJpbmdcbiAgICAgICAgLnRyaW0oKVxuICAgICAgICAuc3BsaXQoL1xccysvKVxuICAgICAgICAuZmlsdGVyKChjb250ZW50KSA9PiBjb250ZW50Lmxlbmd0aClcbiAgICAgICAgLm1hcCgoY29udGVudCwgaW5kZXgpID0+ICh7IGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIGNvbnRlbnQsIGluZGV4IH0pKTtcbn1cbmZ1bmN0aW9uIHppcChsZWZ0LCByaWdodCkge1xuICAgIGNvbnN0IGxlbmd0aCA9IE1hdGgubWF4KGxlZnQubGVuZ3RoLCByaWdodC5sZW5ndGgpO1xuICAgIHJldHVybiBBcnJheS5mcm9tKHsgbGVuZ3RoIH0sIChfLCBpbmRleCkgPT4gW2xlZnRbaW5kZXhdLCByaWdodFtpbmRleF1dKTtcbn1cbmZ1bmN0aW9uIHRva2Vuc0FyZUVxdWFsKGxlZnQsIHJpZ2h0KSB7XG4gICAgcmV0dXJuIGxlZnQgJiYgcmlnaHQgJiYgbGVmdC5pbmRleCA9PSByaWdodC5pbmRleCAmJiBsZWZ0LmNvbnRlbnQgPT0gcmlnaHQuY29udGVudDtcbn1cblxuY2xhc3MgVmFsdWVMaXN0T2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIgPSBuZXcgVG9rZW5MaXN0T2JzZXJ2ZXIoZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgdGhpcyk7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5wYXJzZVJlc3VsdHNCeVRva2VuID0gbmV3IFdlYWtNYXAoKTtcbiAgICAgICAgdGhpcy52YWx1ZXNCeVRva2VuQnlFbGVtZW50ID0gbmV3IFdlYWtNYXAoKTtcbiAgICB9XG4gICAgZ2V0IHN0YXJ0ZWQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnN0YXJ0ZWQ7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnJlZnJlc2goKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnRva2VuTGlzdE9ic2VydmVyLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBhdHRyaWJ1dGVOYW1lKCkge1xuICAgICAgICByZXR1cm4gdGhpcy50b2tlbkxpc3RPYnNlcnZlci5hdHRyaWJ1dGVOYW1lO1xuICAgIH1cbiAgICB0b2tlbk1hdGNoZWQodG9rZW4pIHtcbiAgICAgICAgY29uc3QgeyBlbGVtZW50IH0gPSB0b2tlbjtcbiAgICAgICAgY29uc3QgeyB2YWx1ZSB9ID0gdGhpcy5mZXRjaFBhcnNlUmVzdWx0Rm9yVG9rZW4odG9rZW4pO1xuICAgICAgICBpZiAodmFsdWUpIHtcbiAgICAgICAgICAgIHRoaXMuZmV0Y2hWYWx1ZXNCeVRva2VuRm9yRWxlbWVudChlbGVtZW50KS5zZXQodG9rZW4sIHZhbHVlKTtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWRWYWx1ZShlbGVtZW50LCB2YWx1ZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgdG9rZW5Vbm1hdGNoZWQodG9rZW4pIHtcbiAgICAgICAgY29uc3QgeyBlbGVtZW50IH0gPSB0b2tlbjtcbiAgICAgICAgY29uc3QgeyB2YWx1ZSB9ID0gdGhpcy5mZXRjaFBhcnNlUmVzdWx0Rm9yVG9rZW4odG9rZW4pO1xuICAgICAgICBpZiAodmFsdWUpIHtcbiAgICAgICAgICAgIHRoaXMuZmV0Y2hWYWx1ZXNCeVRva2VuRm9yRWxlbWVudChlbGVtZW50KS5kZWxldGUodG9rZW4pO1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50VW5tYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGZldGNoUGFyc2VSZXN1bHRGb3JUb2tlbih0b2tlbikge1xuICAgICAgICBsZXQgcGFyc2VSZXN1bHQgPSB0aGlzLnBhcnNlUmVzdWx0c0J5VG9rZW4uZ2V0KHRva2VuKTtcbiAgICAgICAgaWYgKCFwYXJzZVJlc3VsdCkge1xuICAgICAgICAgICAgcGFyc2VSZXN1bHQgPSB0aGlzLnBhcnNlVG9rZW4odG9rZW4pO1xuICAgICAgICAgICAgdGhpcy5wYXJzZVJlc3VsdHNCeVRva2VuLnNldCh0b2tlbiwgcGFyc2VSZXN1bHQpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBwYXJzZVJlc3VsdDtcbiAgICB9XG4gICAgZmV0Y2hWYWx1ZXNCeVRva2VuRm9yRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGxldCB2YWx1ZXNCeVRva2VuID0gdGhpcy52YWx1ZXNCeVRva2VuQnlFbGVtZW50LmdldChlbGVtZW50KTtcbiAgICAgICAgaWYgKCF2YWx1ZXNCeVRva2VuKSB7XG4gICAgICAgICAgICB2YWx1ZXNCeVRva2VuID0gbmV3IE1hcCgpO1xuICAgICAgICAgICAgdGhpcy52YWx1ZXNCeVRva2VuQnlFbGVtZW50LnNldChlbGVtZW50LCB2YWx1ZXNCeVRva2VuKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gdmFsdWVzQnlUb2tlbjtcbiAgICB9XG4gICAgcGFyc2VUb2tlbih0b2tlbikge1xuICAgICAgICB0cnkge1xuICAgICAgICAgICAgY29uc3QgdmFsdWUgPSB0aGlzLmRlbGVnYXRlLnBhcnNlVmFsdWVGb3JUb2tlbih0b2tlbik7XG4gICAgICAgICAgICByZXR1cm4geyB2YWx1ZSB9O1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgcmV0dXJuIHsgZXJyb3IgfTtcbiAgICAgICAgfVxuICAgIH1cbn1cblxuY2xhc3MgQmluZGluZ09ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3Rvcihjb250ZXh0LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMuYmluZGluZ3NCeUFjdGlvbiA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy52YWx1ZUxpc3RPYnNlcnZlcikge1xuICAgICAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlciA9IG5ldyBWYWx1ZUxpc3RPYnNlcnZlcih0aGlzLmVsZW1lbnQsIHRoaXMuYWN0aW9uQXR0cmlidXRlLCB0aGlzKTtcbiAgICAgICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy52YWx1ZUxpc3RPYnNlcnZlcikge1xuICAgICAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgICAgICBkZWxldGUgdGhpcy52YWx1ZUxpc3RPYnNlcnZlcjtcbiAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdEFsbEFjdGlvbnMoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgYWN0aW9uQXR0cmlidXRlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY2hlbWEuYWN0aW9uQXR0cmlidXRlO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGJpbmRpbmdzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmJpbmRpbmdzQnlBY3Rpb24udmFsdWVzKCkpO1xuICAgIH1cbiAgICBjb25uZWN0QWN0aW9uKGFjdGlvbikge1xuICAgICAgICBjb25zdCBiaW5kaW5nID0gbmV3IEJpbmRpbmcodGhpcy5jb250ZXh0LCBhY3Rpb24pO1xuICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uc2V0KGFjdGlvbiwgYmluZGluZyk7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUuYmluZGluZ0Nvbm5lY3RlZChiaW5kaW5nKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdEFjdGlvbihhY3Rpb24pIHtcbiAgICAgICAgY29uc3QgYmluZGluZyA9IHRoaXMuYmluZGluZ3NCeUFjdGlvbi5nZXQoYWN0aW9uKTtcbiAgICAgICAgaWYgKGJpbmRpbmcpIHtcbiAgICAgICAgICAgIHRoaXMuYmluZGluZ3NCeUFjdGlvbi5kZWxldGUoYWN0aW9uKTtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuYmluZGluZ0Rpc2Nvbm5lY3RlZChiaW5kaW5nKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0QWxsQWN0aW9ucygpIHtcbiAgICAgICAgdGhpcy5iaW5kaW5ncy5mb3JFYWNoKChiaW5kaW5nKSA9PiB0aGlzLmRlbGVnYXRlLmJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZywgdHJ1ZSkpO1xuICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uY2xlYXIoKTtcbiAgICB9XG4gICAgcGFyc2VWYWx1ZUZvclRva2VuKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IGFjdGlvbiA9IEFjdGlvbi5mb3JUb2tlbih0b2tlbiwgdGhpcy5zY2hlbWEpO1xuICAgICAgICBpZiAoYWN0aW9uLmlkZW50aWZpZXIgPT0gdGhpcy5pZGVudGlmaWVyKSB7XG4gICAgICAgICAgICByZXR1cm4gYWN0aW9uO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkVmFsdWUoZWxlbWVudCwgYWN0aW9uKSB7XG4gICAgICAgIHRoaXMuY29ubmVjdEFjdGlvbihhY3Rpb24pO1xuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkVmFsdWUoZWxlbWVudCwgYWN0aW9uKSB7XG4gICAgICAgIHRoaXMuZGlzY29ubmVjdEFjdGlvbihhY3Rpb24pO1xuICAgIH1cbn1cblxuY2xhc3MgVmFsdWVPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgcmVjZWl2ZXIpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICAgICAgdGhpcy5yZWNlaXZlciA9IHJlY2VpdmVyO1xuICAgICAgICB0aGlzLnN0cmluZ01hcE9ic2VydmVyID0gbmV3IFN0cmluZ01hcE9ic2VydmVyKHRoaXMuZWxlbWVudCwgdGhpcyk7XG4gICAgICAgIHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwID0gdGhpcy5jb250cm9sbGVyLnZhbHVlRGVzY3JpcHRvck1hcDtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMuc3RyaW5nTWFwT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2tzRm9yRGVmYXVsdFZhbHVlcygpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnN0cmluZ01hcE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuY29udHJvbGxlcjtcbiAgICB9XG4gICAgZ2V0U3RyaW5nTWFwS2V5Rm9yQXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgaWYgKGF0dHJpYnV0ZU5hbWUgaW4gdGhpcy52YWx1ZURlc2NyaXB0b3JNYXApIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcFthdHRyaWJ1dGVOYW1lXS5uYW1lO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JNYXBbYXR0cmlidXRlTmFtZV07XG4gICAgICAgIGlmICghdGhpcy5oYXNWYWx1ZShrZXkpKSB7XG4gICAgICAgICAgICB0aGlzLmludm9rZUNoYW5nZWRDYWxsYmFjayhrZXksIGRlc2NyaXB0b3Iud3JpdGVyKHRoaXMucmVjZWl2ZXJba2V5XSksIGRlc2NyaXB0b3Iud3JpdGVyKGRlc2NyaXB0b3IuZGVmYXVsdFZhbHVlKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RyaW5nTWFwVmFsdWVDaGFuZ2VkKHZhbHVlLCBuYW1lLCBvbGRWYWx1ZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JOYW1lTWFwW25hbWVdO1xuICAgICAgICBpZiAodmFsdWUgPT09IG51bGwpXG4gICAgICAgICAgICByZXR1cm47XG4gICAgICAgIGlmIChvbGRWYWx1ZSA9PT0gbnVsbCkge1xuICAgICAgICAgICAgb2xkVmFsdWUgPSBkZXNjcmlwdG9yLndyaXRlcihkZXNjcmlwdG9yLmRlZmF1bHRWYWx1ZSk7XG4gICAgICAgIH1cbiAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2sobmFtZSwgdmFsdWUsIG9sZFZhbHVlKTtcbiAgICB9XG4gICAgc3RyaW5nTWFwS2V5UmVtb3ZlZChrZXksIGF0dHJpYnV0ZU5hbWUsIG9sZFZhbHVlKSB7XG4gICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck5hbWVNYXBba2V5XTtcbiAgICAgICAgaWYgKHRoaXMuaGFzVmFsdWUoa2V5KSkge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2soa2V5LCBkZXNjcmlwdG9yLndyaXRlcih0aGlzLnJlY2VpdmVyW2tleV0pLCBvbGRWYWx1ZSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICB0aGlzLmludm9rZUNoYW5nZWRDYWxsYmFjayhrZXksIGRlc2NyaXB0b3Iud3JpdGVyKGRlc2NyaXB0b3IuZGVmYXVsdFZhbHVlKSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGludm9rZUNoYW5nZWRDYWxsYmFja3NGb3JEZWZhdWx0VmFsdWVzKCkge1xuICAgICAgICBmb3IgKGNvbnN0IHsga2V5LCBuYW1lLCBkZWZhdWx0VmFsdWUsIHdyaXRlciB9IG9mIHRoaXMudmFsdWVEZXNjcmlwdG9ycykge1xuICAgICAgICAgICAgaWYgKGRlZmF1bHRWYWx1ZSAhPSB1bmRlZmluZWQgJiYgIXRoaXMuY29udHJvbGxlci5kYXRhLmhhcyhrZXkpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2sobmFtZSwgd3JpdGVyKGRlZmF1bHRWYWx1ZSksIHVuZGVmaW5lZCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgaW52b2tlQ2hhbmdlZENhbGxiYWNrKG5hbWUsIHJhd1ZhbHVlLCByYXdPbGRWYWx1ZSkge1xuICAgICAgICBjb25zdCBjaGFuZ2VkTWV0aG9kTmFtZSA9IGAke25hbWV9Q2hhbmdlZGA7XG4gICAgICAgIGNvbnN0IGNoYW5nZWRNZXRob2QgPSB0aGlzLnJlY2VpdmVyW2NoYW5nZWRNZXRob2ROYW1lXTtcbiAgICAgICAgaWYgKHR5cGVvZiBjaGFuZ2VkTWV0aG9kID09IFwiZnVuY3Rpb25cIikge1xuICAgICAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFtuYW1lXTtcbiAgICAgICAgICAgIHRyeSB7XG4gICAgICAgICAgICAgICAgY29uc3QgdmFsdWUgPSBkZXNjcmlwdG9yLnJlYWRlcihyYXdWYWx1ZSk7XG4gICAgICAgICAgICAgICAgbGV0IG9sZFZhbHVlID0gcmF3T2xkVmFsdWU7XG4gICAgICAgICAgICAgICAgaWYgKHJhd09sZFZhbHVlKSB7XG4gICAgICAgICAgICAgICAgICAgIG9sZFZhbHVlID0gZGVzY3JpcHRvci5yZWFkZXIocmF3T2xkVmFsdWUpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBjaGFuZ2VkTWV0aG9kLmNhbGwodGhpcy5yZWNlaXZlciwgdmFsdWUsIG9sZFZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgICAgIGlmIChlcnJvciBpbnN0YW5jZW9mIFR5cGVFcnJvcikge1xuICAgICAgICAgICAgICAgICAgICBlcnJvci5tZXNzYWdlID0gYFN0aW11bHVzIFZhbHVlIFwiJHt0aGlzLmNvbnRleHQuaWRlbnRpZmllcn0uJHtkZXNjcmlwdG9yLm5hbWV9XCIgLSAke2Vycm9yLm1lc3NhZ2V9YDtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgdGhyb3cgZXJyb3I7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IHZhbHVlRGVzY3JpcHRvcnMoKSB7XG4gICAgICAgIGNvbnN0IHsgdmFsdWVEZXNjcmlwdG9yTWFwIH0gPSB0aGlzO1xuICAgICAgICByZXR1cm4gT2JqZWN0LmtleXModmFsdWVEZXNjcmlwdG9yTWFwKS5tYXAoKGtleSkgPT4gdmFsdWVEZXNjcmlwdG9yTWFwW2tleV0pO1xuICAgIH1cbiAgICBnZXQgdmFsdWVEZXNjcmlwdG9yTmFtZU1hcCgpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvcnMgPSB7fTtcbiAgICAgICAgT2JqZWN0LmtleXModGhpcy52YWx1ZURlc2NyaXB0b3JNYXApLmZvckVhY2goKGtleSkgPT4ge1xuICAgICAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwW2tleV07XG4gICAgICAgICAgICBkZXNjcmlwdG9yc1tkZXNjcmlwdG9yLm5hbWVdID0gZGVzY3JpcHRvcjtcbiAgICAgICAgfSk7XG4gICAgICAgIHJldHVybiBkZXNjcmlwdG9ycztcbiAgICB9XG4gICAgaGFzVmFsdWUoYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JOYW1lTWFwW2F0dHJpYnV0ZU5hbWVdO1xuICAgICAgICBjb25zdCBoYXNNZXRob2ROYW1lID0gYGhhcyR7Y2FwaXRhbGl6ZShkZXNjcmlwdG9yLm5hbWUpfWA7XG4gICAgICAgIHJldHVybiB0aGlzLnJlY2VpdmVyW2hhc01ldGhvZE5hbWVdO1xuICAgIH1cbn1cblxuY2xhc3MgVGFyZ2V0T2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy50YXJnZXRzQnlOYW1lID0gbmV3IE11bHRpbWFwKCk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIgPSBuZXcgVG9rZW5MaXN0T2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLmF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIGlmICh0aGlzLnRva2VuTGlzdE9ic2VydmVyKSB7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBbGxUYXJnZXRzKCk7XG4gICAgICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICAgICAgICAgIGRlbGV0ZSB0aGlzLnRva2VuTGlzdE9ic2VydmVyO1xuICAgICAgICB9XG4gICAgfVxuICAgIHRva2VuTWF0Y2hlZCh7IGVsZW1lbnQsIGNvbnRlbnQ6IG5hbWUgfSkge1xuICAgICAgICBpZiAodGhpcy5zY29wZS5jb250YWluc0VsZW1lbnQoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuY29ubmVjdFRhcmdldChlbGVtZW50LCBuYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICB0b2tlblVubWF0Y2hlZCh7IGVsZW1lbnQsIGNvbnRlbnQ6IG5hbWUgfSkge1xuICAgICAgICB0aGlzLmRpc2Nvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSk7XG4gICAgfVxuICAgIGNvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICghdGhpcy50YXJnZXRzQnlOYW1lLmhhcyhuYW1lLCBlbGVtZW50KSkge1xuICAgICAgICAgICAgdGhpcy50YXJnZXRzQnlOYW1lLmFkZChuYW1lLCBlbGVtZW50KTtcbiAgICAgICAgICAgIChfYSA9IHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIpID09PSBudWxsIHx8IF9hID09PSB2b2lkIDAgPyB2b2lkIDAgOiBfYS5wYXVzZSgoKSA9PiB0aGlzLmRlbGVnYXRlLnRhcmdldENvbm5lY3RlZChlbGVtZW50LCBuYW1lKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZGlzY29ubmVjdFRhcmdldChlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgaWYgKHRoaXMudGFyZ2V0c0J5TmFtZS5oYXMobmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMudGFyZ2V0c0J5TmFtZS5kZWxldGUobmFtZSwgZWxlbWVudCk7XG4gICAgICAgICAgICAoX2EgPSB0aGlzLnRva2VuTGlzdE9ic2VydmVyKSA9PT0gbnVsbCB8fCBfYSA9PT0gdm9pZCAwID8gdm9pZCAwIDogX2EucGF1c2UoKCkgPT4gdGhpcy5kZWxlZ2F0ZS50YXJnZXREaXNjb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RBbGxUYXJnZXRzKCkge1xuICAgICAgICBmb3IgKGNvbnN0IG5hbWUgb2YgdGhpcy50YXJnZXRzQnlOYW1lLmtleXMpIHtcbiAgICAgICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiB0aGlzLnRhcmdldHNCeU5hbWUuZ2V0VmFsdWVzRm9yS2V5KG5hbWUpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBhdHRyaWJ1dGVOYW1lKCkge1xuICAgICAgICByZXR1cm4gYGRhdGEtJHt0aGlzLmNvbnRleHQuaWRlbnRpZmllcn0tdGFyZ2V0YDtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbn1cblxuZnVuY3Rpb24gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIHByb3BlcnR5TmFtZSkge1xuICAgIGNvbnN0IGFuY2VzdG9ycyA9IGdldEFuY2VzdG9yc0ZvckNvbnN0cnVjdG9yKGNvbnN0cnVjdG9yKTtcbiAgICByZXR1cm4gQXJyYXkuZnJvbShhbmNlc3RvcnMucmVkdWNlKCh2YWx1ZXMsIGNvbnN0cnVjdG9yKSA9PiB7XG4gICAgICAgIGdldE93blN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpLmZvckVhY2goKG5hbWUpID0+IHZhbHVlcy5hZGQobmFtZSkpO1xuICAgICAgICByZXR1cm4gdmFsdWVzO1xuICAgIH0sIG5ldyBTZXQoKSkpO1xufVxuZnVuY3Rpb24gcmVhZEluaGVyaXRhYmxlU3RhdGljT2JqZWN0UGFpcnMoY29uc3RydWN0b3IsIHByb3BlcnR5TmFtZSkge1xuICAgIGNvbnN0IGFuY2VzdG9ycyA9IGdldEFuY2VzdG9yc0ZvckNvbnN0cnVjdG9yKGNvbnN0cnVjdG9yKTtcbiAgICByZXR1cm4gYW5jZXN0b3JzLnJlZHVjZSgocGFpcnMsIGNvbnN0cnVjdG9yKSA9PiB7XG4gICAgICAgIHBhaXJzLnB1c2goLi4uZ2V0T3duU3RhdGljT2JqZWN0UGFpcnMoY29uc3RydWN0b3IsIHByb3BlcnR5TmFtZSkpO1xuICAgICAgICByZXR1cm4gcGFpcnM7XG4gICAgfSwgW10pO1xufVxuZnVuY3Rpb24gZ2V0QW5jZXN0b3JzRm9yQ29uc3RydWN0b3IoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBhbmNlc3RvcnMgPSBbXTtcbiAgICB3aGlsZSAoY29uc3RydWN0b3IpIHtcbiAgICAgICAgYW5jZXN0b3JzLnB1c2goY29uc3RydWN0b3IpO1xuICAgICAgICBjb25zdHJ1Y3RvciA9IE9iamVjdC5nZXRQcm90b3R5cGVPZihjb25zdHJ1Y3Rvcik7XG4gICAgfVxuICAgIHJldHVybiBhbmNlc3RvcnMucmV2ZXJzZSgpO1xufVxuZnVuY3Rpb24gZ2V0T3duU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIHByb3BlcnR5TmFtZSkge1xuICAgIGNvbnN0IGRlZmluaXRpb24gPSBjb25zdHJ1Y3Rvcltwcm9wZXJ0eU5hbWVdO1xuICAgIHJldHVybiBBcnJheS5pc0FycmF5KGRlZmluaXRpb24pID8gZGVmaW5pdGlvbiA6IFtdO1xufVxuZnVuY3Rpb24gZ2V0T3duU3RhdGljT2JqZWN0UGFpcnMoY29uc3RydWN0b3IsIHByb3BlcnR5TmFtZSkge1xuICAgIGNvbnN0IGRlZmluaXRpb24gPSBjb25zdHJ1Y3Rvcltwcm9wZXJ0eU5hbWVdO1xuICAgIHJldHVybiBkZWZpbml0aW9uID8gT2JqZWN0LmtleXMoZGVmaW5pdGlvbikubWFwKChrZXkpID0+IFtrZXksIGRlZmluaXRpb25ba2V5XV0pIDogW107XG59XG5cbmNsYXNzIE91dGxldE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3Rvcihjb250ZXh0LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLm91dGxldHNCeU5hbWUgPSBuZXcgTXVsdGltYXAoKTtcbiAgICAgICAgdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZSA9IG5ldyBNdWx0aW1hcCgpO1xuICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAgPSBuZXcgTWFwKCk7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAgPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5vdXRsZXREZWZpbml0aW9ucy5mb3JFYWNoKChvdXRsZXROYW1lKSA9PiB7XG4gICAgICAgICAgICAgICAgdGhpcy5zZXR1cFNlbGVjdG9yT2JzZXJ2ZXJGb3JPdXRsZXQob3V0bGV0TmFtZSk7XG4gICAgICAgICAgICAgICAgdGhpcy5zZXR1cEF0dHJpYnV0ZU9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICAgICAgfSk7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5kZXBlbmRlbnRDb250ZXh0cy5mb3JFYWNoKChjb250ZXh0KSA9PiBjb250ZXh0LnJlZnJlc2goKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmZvckVhY2goKG9ic2VydmVyKSA9PiBvYnNlcnZlci5yZWZyZXNoKCkpO1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwLmZvckVhY2goKG9ic2VydmVyKSA9PiBvYnNlcnZlci5yZWZyZXNoKCkpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdEFsbE91dGxldHMoKTtcbiAgICAgICAgICAgIHRoaXMuc3RvcFNlbGVjdG9yT2JzZXJ2ZXJzKCk7XG4gICAgICAgICAgICB0aGlzLnN0b3BBdHRyaWJ1dGVPYnNlcnZlcnMoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wU2VsZWN0b3JPYnNlcnZlcnMoKSB7XG4gICAgICAgIGlmICh0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuc2l6ZSA+IDApIHtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIuc3RvcCgpKTtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5jbGVhcigpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0b3BBdHRyaWJ1dGVPYnNlcnZlcnMoKSB7XG4gICAgICAgIGlmICh0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwLnNpemUgPiAwKSB7XG4gICAgICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwLmZvckVhY2goKG9ic2VydmVyKSA9PiBvYnNlcnZlci5zdG9wKCkpO1xuICAgICAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5jbGVhcigpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHNlbGVjdG9yTWF0Y2hlZChlbGVtZW50LCBfc2VsZWN0b3IsIHsgb3V0bGV0TmFtZSB9KSB7XG4gICAgICAgIGNvbnN0IG91dGxldCA9IHRoaXMuZ2V0T3V0bGV0KGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgICAgICBpZiAob3V0bGV0KSB7XG4gICAgICAgICAgICB0aGlzLmNvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBfc2VsZWN0b3IsIHsgb3V0bGV0TmFtZSB9KSB7XG4gICAgICAgIGNvbnN0IG91dGxldCA9IHRoaXMuZ2V0T3V0bGV0RnJvbU1hcChlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKG91dGxldCkge1xuICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JNYXRjaEVsZW1lbnQoZWxlbWVudCwgeyBvdXRsZXROYW1lIH0pIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLnNlbGVjdG9yKG91dGxldE5hbWUpO1xuICAgICAgICBjb25zdCBoYXNPdXRsZXQgPSB0aGlzLmhhc091dGxldChlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgY29uc3QgaGFzT3V0bGV0Q29udHJvbGxlciA9IGVsZW1lbnQubWF0Y2hlcyhgWyR7dGhpcy5zY2hlbWEuY29udHJvbGxlckF0dHJpYnV0ZX1+PSR7b3V0bGV0TmFtZX1dYCk7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgcmV0dXJuIGhhc091dGxldCAmJiBoYXNPdXRsZXRDb250cm9sbGVyICYmIGVsZW1lbnQubWF0Y2hlcyhzZWxlY3Rvcik7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWRBdHRyaWJ1dGUoX2VsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3Qgb3V0bGV0TmFtZSA9IHRoaXMuZ2V0T3V0bGV0TmFtZUZyb21PdXRsZXRBdHRyaWJ1dGVOYW1lKGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICBpZiAob3V0bGV0TmFtZSkge1xuICAgICAgICAgICAgdGhpcy51cGRhdGVTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVWYWx1ZUNoYW5nZWQoX2VsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3Qgb3V0bGV0TmFtZSA9IHRoaXMuZ2V0T3V0bGV0TmFtZUZyb21PdXRsZXRBdHRyaWJ1dGVOYW1lKGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICBpZiAob3V0bGV0TmFtZSkge1xuICAgICAgICAgICAgdGhpcy51cGRhdGVTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUoX2VsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3Qgb3V0bGV0TmFtZSA9IHRoaXMuZ2V0T3V0bGV0TmFtZUZyb21PdXRsZXRBdHRyaWJ1dGVOYW1lKGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICBpZiAob3V0bGV0TmFtZSkge1xuICAgICAgICAgICAgdGhpcy51cGRhdGVTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGNvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgaWYgKCF0aGlzLm91dGxldEVsZW1lbnRzQnlOYW1lLmhhcyhvdXRsZXROYW1lLCBlbGVtZW50KSkge1xuICAgICAgICAgICAgdGhpcy5vdXRsZXRzQnlOYW1lLmFkZChvdXRsZXROYW1lLCBvdXRsZXQpO1xuICAgICAgICAgICAgdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5hZGQob3V0bGV0TmFtZSwgZWxlbWVudCk7XG4gICAgICAgICAgICAoX2EgPSB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuZ2V0KG91dGxldE5hbWUpKSA9PT0gbnVsbCB8fCBfYSA9PT0gdm9pZCAwID8gdm9pZCAwIDogX2EucGF1c2UoKCkgPT4gdGhpcy5kZWxlZ2F0ZS5vdXRsZXRDb25uZWN0ZWQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZGlzY29ubmVjdE91dGxldChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpIHtcbiAgICAgICAgdmFyIF9hO1xuICAgICAgICBpZiAodGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5oYXMob3V0bGV0TmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0c0J5TmFtZS5kZWxldGUob3V0bGV0TmFtZSwgb3V0bGV0KTtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuZGVsZXRlKG91dGxldE5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwXG4gICAgICAgICAgICAgICAgLmdldChvdXRsZXROYW1lKSkgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUub3V0bGV0RGlzY29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RBbGxPdXRsZXRzKCkge1xuICAgICAgICBmb3IgKGNvbnN0IG91dGxldE5hbWUgb2YgdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5rZXlzKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5nZXRWYWx1ZXNGb3JLZXkob3V0bGV0TmFtZSkpIHtcbiAgICAgICAgICAgICAgICBmb3IgKGNvbnN0IG91dGxldCBvZiB0aGlzLm91dGxldHNCeU5hbWUuZ2V0VmFsdWVzRm9yS2V5KG91dGxldE5hbWUpKSB7XG4gICAgICAgICAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdE91dGxldChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICB1cGRhdGVTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgb2JzZXJ2ZXIgPSB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuZ2V0KG91dGxldE5hbWUpO1xuICAgICAgICBpZiAob2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIG9ic2VydmVyLnNlbGVjdG9yID0gdGhpcy5zZWxlY3RvcihvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzZXR1cFNlbGVjdG9yT2JzZXJ2ZXJGb3JPdXRsZXQob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuc2VsZWN0b3Iob3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yT2JzZXJ2ZXIgPSBuZXcgU2VsZWN0b3JPYnNlcnZlcihkb2N1bWVudC5ib2R5LCBzZWxlY3RvciwgdGhpcywgeyBvdXRsZXROYW1lIH0pO1xuICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuc2V0KG91dGxldE5hbWUsIHNlbGVjdG9yT2JzZXJ2ZXIpO1xuICAgICAgICBzZWxlY3Rvck9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHNldHVwQXR0cmlidXRlT2JzZXJ2ZXJGb3JPdXRsZXQob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5hdHRyaWJ1dGVOYW1lRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlT2JzZXJ2ZXIgPSBuZXcgQXR0cmlidXRlT2JzZXJ2ZXIodGhpcy5zY29wZS5lbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCB0aGlzKTtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5zZXQob3V0bGV0TmFtZSwgYXR0cmlidXRlT2JzZXJ2ZXIpO1xuICAgICAgICBhdHRyaWJ1dGVPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzZWxlY3RvcihvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLm91dGxldHMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBhdHRyaWJ1dGVOYW1lRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLnNjaGVtYS5vdXRsZXRBdHRyaWJ1dGVGb3JTY29wZSh0aGlzLmlkZW50aWZpZXIsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBnZXRPdXRsZXROYW1lRnJvbU91dGxldEF0dHJpYnV0ZU5hbWUoYXR0cmlidXRlTmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXREZWZpbml0aW9ucy5maW5kKChvdXRsZXROYW1lKSA9PiB0aGlzLmF0dHJpYnV0ZU5hbWVGb3JPdXRsZXROYW1lKG91dGxldE5hbWUpID09PSBhdHRyaWJ1dGVOYW1lKTtcbiAgICB9XG4gICAgZ2V0IG91dGxldERlcGVuZGVuY2llcygpIHtcbiAgICAgICAgY29uc3QgZGVwZW5kZW5jaWVzID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMucm91dGVyLm1vZHVsZXMuZm9yRWFjaCgobW9kdWxlKSA9PiB7XG4gICAgICAgICAgICBjb25zdCBjb25zdHJ1Y3RvciA9IG1vZHVsZS5kZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvcjtcbiAgICAgICAgICAgIGNvbnN0IG91dGxldHMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJvdXRsZXRzXCIpO1xuICAgICAgICAgICAgb3V0bGV0cy5mb3JFYWNoKChvdXRsZXQpID0+IGRlcGVuZGVuY2llcy5hZGQob3V0bGV0LCBtb2R1bGUuaWRlbnRpZmllcikpO1xuICAgICAgICB9KTtcbiAgICAgICAgcmV0dXJuIGRlcGVuZGVuY2llcztcbiAgICB9XG4gICAgZ2V0IG91dGxldERlZmluaXRpb25zKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXREZXBlbmRlbmNpZXMuZ2V0S2V5c0ZvclZhbHVlKHRoaXMuaWRlbnRpZmllcik7XG4gICAgfVxuICAgIGdldCBkZXBlbmRlbnRDb250cm9sbGVySWRlbnRpZmllcnMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm91dGxldERlcGVuZGVuY2llcy5nZXRWYWx1ZXNGb3JLZXkodGhpcy5pZGVudGlmaWVyKTtcbiAgICB9XG4gICAgZ2V0IGRlcGVuZGVudENvbnRleHRzKCkge1xuICAgICAgICBjb25zdCBpZGVudGlmaWVycyA9IHRoaXMuZGVwZW5kZW50Q29udHJvbGxlcklkZW50aWZpZXJzO1xuICAgICAgICByZXR1cm4gdGhpcy5yb3V0ZXIuY29udGV4dHMuZmlsdGVyKChjb250ZXh0KSA9PiBpZGVudGlmaWVycy5pbmNsdWRlcyhjb250ZXh0LmlkZW50aWZpZXIpKTtcbiAgICB9XG4gICAgaGFzT3V0bGV0KGVsZW1lbnQsIG91dGxldE5hbWUpIHtcbiAgICAgICAgcmV0dXJuICEhdGhpcy5nZXRPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSkgfHwgISF0aGlzLmdldE91dGxldEZyb21NYXAoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgfVxuICAgIGdldE91dGxldChlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmdldENvbnRyb2xsZXJGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgZ2V0T3V0bGV0RnJvbU1hcChlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm91dGxldHNCeU5hbWUuZ2V0VmFsdWVzRm9yS2V5KG91dGxldE5hbWUpLmZpbmQoKG91dGxldCkgPT4gb3V0bGV0LmVsZW1lbnQgPT09IGVsZW1lbnQpO1xuICAgIH1cbiAgICBnZXQgc2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NvcGU7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NoZW1hO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgYXBwbGljYXRpb24oKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuYXBwbGljYXRpb247XG4gICAgfVxuICAgIGdldCByb3V0ZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLnJvdXRlcjtcbiAgICB9XG59XG5cbmNsYXNzIENvbnRleHQge1xuICAgIGNvbnN0cnVjdG9yKG1vZHVsZSwgc2NvcGUpIHtcbiAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5ID0gKGZ1bmN0aW9uTmFtZSwgZGV0YWlsID0ge30pID0+IHtcbiAgICAgICAgICAgIGNvbnN0IHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9ID0gdGhpcztcbiAgICAgICAgICAgIGRldGFpbCA9IE9iamVjdC5hc3NpZ24oeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50IH0sIGRldGFpbCk7XG4gICAgICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmxvZ0RlYnVnQWN0aXZpdHkodGhpcy5pZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCk7XG4gICAgICAgIH07XG4gICAgICAgIHRoaXMubW9kdWxlID0gbW9kdWxlO1xuICAgICAgICB0aGlzLnNjb3BlID0gc2NvcGU7XG4gICAgICAgIHRoaXMuY29udHJvbGxlciA9IG5ldyBtb2R1bGUuY29udHJvbGxlckNvbnN0cnVjdG9yKHRoaXMpO1xuICAgICAgICB0aGlzLmJpbmRpbmdPYnNlcnZlciA9IG5ldyBCaW5kaW5nT2JzZXJ2ZXIodGhpcywgdGhpcy5kaXNwYXRjaGVyKTtcbiAgICAgICAgdGhpcy52YWx1ZU9ic2VydmVyID0gbmV3IFZhbHVlT2JzZXJ2ZXIodGhpcywgdGhpcy5jb250cm9sbGVyKTtcbiAgICAgICAgdGhpcy50YXJnZXRPYnNlcnZlciA9IG5ldyBUYXJnZXRPYnNlcnZlcih0aGlzLCB0aGlzKTtcbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlciA9IG5ldyBPdXRsZXRPYnNlcnZlcih0aGlzLCB0aGlzKTtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIHRoaXMuY29udHJvbGxlci5pbml0aWFsaXplKCk7XG4gICAgICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJpbml0aWFsaXplXCIpO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgdGhpcy5oYW5kbGVFcnJvcihlcnJvciwgXCJpbml0aWFsaXppbmcgY29udHJvbGxlclwiKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBjb25uZWN0KCkge1xuICAgICAgICB0aGlzLmJpbmRpbmdPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB0aGlzLnZhbHVlT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy50YXJnZXRPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB0aGlzLm91dGxldE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLmNvbnRyb2xsZXIuY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiY29ubmVjdFwiKTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHRoaXMuaGFuZGxlRXJyb3IoZXJyb3IsIFwiY29ubmVjdGluZyBjb250cm9sbGVyXCIpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMub3V0bGV0T2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBkaXNjb25uZWN0KCkge1xuICAgICAgICB0cnkge1xuICAgICAgICAgICAgdGhpcy5jb250cm9sbGVyLmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImRpc2Nvbm5lY3RcIik7XG4gICAgICAgIH1cbiAgICAgICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICB0aGlzLmhhbmRsZUVycm9yKGVycm9yLCBcImRpc2Nvbm5lY3RpbmcgY29udHJvbGxlclwiKTtcbiAgICAgICAgfVxuICAgICAgICB0aGlzLm91dGxldE9ic2VydmVyLnN0b3AoKTtcbiAgICAgICAgdGhpcy50YXJnZXRPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgIHRoaXMudmFsdWVPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgIHRoaXMuYmluZGluZ09ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgZ2V0IGFwcGxpY2F0aW9uKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5tb2R1bGUuYXBwbGljYXRpb247XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5tb2R1bGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24uc2NoZW1hO1xuICAgIH1cbiAgICBnZXQgZGlzcGF0Y2hlcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24uZGlzcGF0Y2hlcjtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBwYXJlbnRFbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50LnBhcmVudEVsZW1lbnQ7XG4gICAgfVxuICAgIGhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwgPSB7fSkge1xuICAgICAgICBjb25zdCB7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXIsIGVsZW1lbnQgfSA9IHRoaXM7XG4gICAgICAgIGRldGFpbCA9IE9iamVjdC5hc3NpZ24oeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50IH0sIGRldGFpbCk7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24uaGFuZGxlRXJyb3IoZXJyb3IsIGBFcnJvciAke21lc3NhZ2V9YCwgZGV0YWlsKTtcbiAgICB9XG4gICAgdGFyZ2V0Q29ubmVjdGVkKGVsZW1lbnQsIG5hbWUpIHtcbiAgICAgICAgdGhpcy5pbnZva2VDb250cm9sbGVyTWV0aG9kKGAke25hbWV9VGFyZ2V0Q29ubmVjdGVkYCwgZWxlbWVudCk7XG4gICAgfVxuICAgIHRhcmdldERpc2Nvbm5lY3RlZChlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHRoaXMuaW52b2tlQ29udHJvbGxlck1ldGhvZChgJHtuYW1lfVRhcmdldERpc2Nvbm5lY3RlZGAsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBvdXRsZXRDb25uZWN0ZWQob3V0bGV0LCBlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHRoaXMuaW52b2tlQ29udHJvbGxlck1ldGhvZChgJHtuYW1lc3BhY2VDYW1lbGl6ZShuYW1lKX1PdXRsZXRDb25uZWN0ZWRgLCBvdXRsZXQsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBvdXRsZXREaXNjb25uZWN0ZWQob3V0bGV0LCBlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHRoaXMuaW52b2tlQ29udHJvbGxlck1ldGhvZChgJHtuYW1lc3BhY2VDYW1lbGl6ZShuYW1lKX1PdXRsZXREaXNjb25uZWN0ZWRgLCBvdXRsZXQsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBpbnZva2VDb250cm9sbGVyTWV0aG9kKG1ldGhvZE5hbWUsIC4uLmFyZ3MpIHtcbiAgICAgICAgY29uc3QgY29udHJvbGxlciA9IHRoaXMuY29udHJvbGxlcjtcbiAgICAgICAgaWYgKHR5cGVvZiBjb250cm9sbGVyW21ldGhvZE5hbWVdID09IFwiZnVuY3Rpb25cIikge1xuICAgICAgICAgICAgY29udHJvbGxlclttZXRob2ROYW1lXSguLi5hcmdzKTtcbiAgICAgICAgfVxuICAgIH1cbn1cblxuZnVuY3Rpb24gYmxlc3MoY29uc3RydWN0b3IpIHtcbiAgICByZXR1cm4gc2hhZG93KGNvbnN0cnVjdG9yLCBnZXRCbGVzc2VkUHJvcGVydGllcyhjb25zdHJ1Y3RvcikpO1xufVxuZnVuY3Rpb24gc2hhZG93KGNvbnN0cnVjdG9yLCBwcm9wZXJ0aWVzKSB7XG4gICAgY29uc3Qgc2hhZG93Q29uc3RydWN0b3IgPSBleHRlbmQoY29uc3RydWN0b3IpO1xuICAgIGNvbnN0IHNoYWRvd1Byb3BlcnRpZXMgPSBnZXRTaGFkb3dQcm9wZXJ0aWVzKGNvbnN0cnVjdG9yLnByb3RvdHlwZSwgcHJvcGVydGllcyk7XG4gICAgT2JqZWN0LmRlZmluZVByb3BlcnRpZXMoc2hhZG93Q29uc3RydWN0b3IucHJvdG90eXBlLCBzaGFkb3dQcm9wZXJ0aWVzKTtcbiAgICByZXR1cm4gc2hhZG93Q29uc3RydWN0b3I7XG59XG5mdW5jdGlvbiBnZXRCbGVzc2VkUHJvcGVydGllcyhjb25zdHJ1Y3Rvcikge1xuICAgIGNvbnN0IGJsZXNzaW5ncyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBcImJsZXNzaW5nc1wiKTtcbiAgICByZXR1cm4gYmxlc3NpbmdzLnJlZHVjZSgoYmxlc3NlZFByb3BlcnRpZXMsIGJsZXNzaW5nKSA9PiB7XG4gICAgICAgIGNvbnN0IHByb3BlcnRpZXMgPSBibGVzc2luZyhjb25zdHJ1Y3Rvcik7XG4gICAgICAgIGZvciAoY29uc3Qga2V5IGluIHByb3BlcnRpZXMpIHtcbiAgICAgICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSBibGVzc2VkUHJvcGVydGllc1trZXldIHx8IHt9O1xuICAgICAgICAgICAgYmxlc3NlZFByb3BlcnRpZXNba2V5XSA9IE9iamVjdC5hc3NpZ24oZGVzY3JpcHRvciwgcHJvcGVydGllc1trZXldKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gYmxlc3NlZFByb3BlcnRpZXM7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gZ2V0U2hhZG93UHJvcGVydGllcyhwcm90b3R5cGUsIHByb3BlcnRpZXMpIHtcbiAgICByZXR1cm4gZ2V0T3duS2V5cyhwcm9wZXJ0aWVzKS5yZWR1Y2UoKHNoYWRvd1Byb3BlcnRpZXMsIGtleSkgPT4ge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gZ2V0U2hhZG93ZWREZXNjcmlwdG9yKHByb3RvdHlwZSwgcHJvcGVydGllcywga2V5KTtcbiAgICAgICAgaWYgKGRlc2NyaXB0b3IpIHtcbiAgICAgICAgICAgIE9iamVjdC5hc3NpZ24oc2hhZG93UHJvcGVydGllcywgeyBba2V5XTogZGVzY3JpcHRvciB9KTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gc2hhZG93UHJvcGVydGllcztcbiAgICB9LCB7fSk7XG59XG5mdW5jdGlvbiBnZXRTaGFkb3dlZERlc2NyaXB0b3IocHJvdG90eXBlLCBwcm9wZXJ0aWVzLCBrZXkpIHtcbiAgICBjb25zdCBzaGFkb3dpbmdEZXNjcmlwdG9yID0gT2JqZWN0LmdldE93blByb3BlcnR5RGVzY3JpcHRvcihwcm90b3R5cGUsIGtleSk7XG4gICAgY29uc3Qgc2hhZG93ZWRCeVZhbHVlID0gc2hhZG93aW5nRGVzY3JpcHRvciAmJiBcInZhbHVlXCIgaW4gc2hhZG93aW5nRGVzY3JpcHRvcjtcbiAgICBpZiAoIXNoYWRvd2VkQnlWYWx1ZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gT2JqZWN0LmdldE93blByb3BlcnR5RGVzY3JpcHRvcihwcm9wZXJ0aWVzLCBrZXkpLnZhbHVlO1xuICAgICAgICBpZiAoc2hhZG93aW5nRGVzY3JpcHRvcikge1xuICAgICAgICAgICAgZGVzY3JpcHRvci5nZXQgPSBzaGFkb3dpbmdEZXNjcmlwdG9yLmdldCB8fCBkZXNjcmlwdG9yLmdldDtcbiAgICAgICAgICAgIGRlc2NyaXB0b3Iuc2V0ID0gc2hhZG93aW5nRGVzY3JpcHRvci5zZXQgfHwgZGVzY3JpcHRvci5zZXQ7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGRlc2NyaXB0b3I7XG4gICAgfVxufVxuY29uc3QgZ2V0T3duS2V5cyA9ICgoKSA9PiB7XG4gICAgaWYgKHR5cGVvZiBPYmplY3QuZ2V0T3duUHJvcGVydHlTeW1ib2xzID09IFwiZnVuY3Rpb25cIikge1xuICAgICAgICByZXR1cm4gKG9iamVjdCkgPT4gWy4uLk9iamVjdC5nZXRPd25Qcm9wZXJ0eU5hbWVzKG9iamVjdCksIC4uLk9iamVjdC5nZXRPd25Qcm9wZXJ0eVN5bWJvbHMob2JqZWN0KV07XG4gICAgfVxuICAgIGVsc2Uge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmdldE93blByb3BlcnR5TmFtZXM7XG4gICAgfVxufSkoKTtcbmNvbnN0IGV4dGVuZCA9ICgoKSA9PiB7XG4gICAgZnVuY3Rpb24gZXh0ZW5kV2l0aFJlZmxlY3QoY29uc3RydWN0b3IpIHtcbiAgICAgICAgZnVuY3Rpb24gZXh0ZW5kZWQoKSB7XG4gICAgICAgICAgICByZXR1cm4gUmVmbGVjdC5jb25zdHJ1Y3QoY29uc3RydWN0b3IsIGFyZ3VtZW50cywgbmV3LnRhcmdldCk7XG4gICAgICAgIH1cbiAgICAgICAgZXh0ZW5kZWQucHJvdG90eXBlID0gT2JqZWN0LmNyZWF0ZShjb25zdHJ1Y3Rvci5wcm90b3R5cGUsIHtcbiAgICAgICAgICAgIGNvbnN0cnVjdG9yOiB7IHZhbHVlOiBleHRlbmRlZCB9LFxuICAgICAgICB9KTtcbiAgICAgICAgUmVmbGVjdC5zZXRQcm90b3R5cGVPZihleHRlbmRlZCwgY29uc3RydWN0b3IpO1xuICAgICAgICByZXR1cm4gZXh0ZW5kZWQ7XG4gICAgfVxuICAgIGZ1bmN0aW9uIHRlc3RSZWZsZWN0RXh0ZW5zaW9uKCkge1xuICAgICAgICBjb25zdCBhID0gZnVuY3Rpb24gKCkge1xuICAgICAgICAgICAgdGhpcy5hLmNhbGwodGhpcyk7XG4gICAgICAgIH07XG4gICAgICAgIGNvbnN0IGIgPSBleHRlbmRXaXRoUmVmbGVjdChhKTtcbiAgICAgICAgYi5wcm90b3R5cGUuYSA9IGZ1bmN0aW9uICgpIHsgfTtcbiAgICAgICAgcmV0dXJuIG5ldyBiKCk7XG4gICAgfVxuICAgIHRyeSB7XG4gICAgICAgIHRlc3RSZWZsZWN0RXh0ZW5zaW9uKCk7XG4gICAgICAgIHJldHVybiBleHRlbmRXaXRoUmVmbGVjdDtcbiAgICB9XG4gICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgIHJldHVybiAoY29uc3RydWN0b3IpID0+IGNsYXNzIGV4dGVuZGVkIGV4dGVuZHMgY29uc3RydWN0b3Ige1xuICAgICAgICB9O1xuICAgIH1cbn0pKCk7XG5cbmZ1bmN0aW9uIGJsZXNzRGVmaW5pdGlvbihkZWZpbml0aW9uKSB7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgaWRlbnRpZmllcjogZGVmaW5pdGlvbi5pZGVudGlmaWVyLFxuICAgICAgICBjb250cm9sbGVyQ29uc3RydWN0b3I6IGJsZXNzKGRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yKSxcbiAgICB9O1xufVxuXG5jbGFzcyBNb2R1bGUge1xuICAgIGNvbnN0cnVjdG9yKGFwcGxpY2F0aW9uLCBkZWZpbml0aW9uKSB7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24gPSBhcHBsaWNhdGlvbjtcbiAgICAgICAgdGhpcy5kZWZpbml0aW9uID0gYmxlc3NEZWZpbml0aW9uKGRlZmluaXRpb24pO1xuICAgICAgICB0aGlzLmNvbnRleHRzQnlTY29wZSA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMuY29ubmVjdGVkQ29udGV4dHMgPSBuZXcgU2V0KCk7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5kZWZpbml0aW9uLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVyQ29uc3RydWN0b3IoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yO1xuICAgIH1cbiAgICBnZXQgY29udGV4dHMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuY29ubmVjdGVkQ29udGV4dHMpO1xuICAgIH1cbiAgICBjb25uZWN0Q29udGV4dEZvclNjb3BlKHNjb3BlKSB7XG4gICAgICAgIGNvbnN0IGNvbnRleHQgPSB0aGlzLmZldGNoQ29udGV4dEZvclNjb3BlKHNjb3BlKTtcbiAgICAgICAgdGhpcy5jb25uZWN0ZWRDb250ZXh0cy5hZGQoY29udGV4dCk7XG4gICAgICAgIGNvbnRleHQuY29ubmVjdCgpO1xuICAgIH1cbiAgICBkaXNjb25uZWN0Q29udGV4dEZvclNjb3BlKHNjb3BlKSB7XG4gICAgICAgIGNvbnN0IGNvbnRleHQgPSB0aGlzLmNvbnRleHRzQnlTY29wZS5nZXQoc2NvcGUpO1xuICAgICAgICBpZiAoY29udGV4dCkge1xuICAgICAgICAgICAgdGhpcy5jb25uZWN0ZWRDb250ZXh0cy5kZWxldGUoY29udGV4dCk7XG4gICAgICAgICAgICBjb250ZXh0LmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBmZXRjaENvbnRleHRGb3JTY29wZShzY29wZSkge1xuICAgICAgICBsZXQgY29udGV4dCA9IHRoaXMuY29udGV4dHNCeVNjb3BlLmdldChzY29wZSk7XG4gICAgICAgIGlmICghY29udGV4dCkge1xuICAgICAgICAgICAgY29udGV4dCA9IG5ldyBDb250ZXh0KHRoaXMsIHNjb3BlKTtcbiAgICAgICAgICAgIHRoaXMuY29udGV4dHNCeVNjb3BlLnNldChzY29wZSwgY29udGV4dCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGNvbnRleHQ7XG4gICAgfVxufVxuXG5jbGFzcyBDbGFzc01hcCB7XG4gICAgY29uc3RydWN0b3Ioc2NvcGUpIHtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgIH1cbiAgICBoYXMobmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5kYXRhLmhhcyh0aGlzLmdldERhdGFLZXkobmFtZSkpO1xuICAgIH1cbiAgICBnZXQobmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5nZXRBbGwobmFtZSlbMF07XG4gICAgfVxuICAgIGdldEFsbChuYW1lKSB7XG4gICAgICAgIGNvbnN0IHRva2VuU3RyaW5nID0gdGhpcy5kYXRhLmdldCh0aGlzLmdldERhdGFLZXkobmFtZSkpIHx8IFwiXCI7XG4gICAgICAgIHJldHVybiB0b2tlbml6ZSh0b2tlblN0cmluZyk7XG4gICAgfVxuICAgIGdldEF0dHJpYnV0ZU5hbWUobmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5kYXRhLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkodGhpcy5nZXREYXRhS2V5KG5hbWUpKTtcbiAgICB9XG4gICAgZ2V0RGF0YUtleShuYW1lKSB7XG4gICAgICAgIHJldHVybiBgJHtuYW1lfS1jbGFzc2A7XG4gICAgfVxuICAgIGdldCBkYXRhKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5kYXRhO1xuICAgIH1cbn1cblxuY2xhc3MgRGF0YU1hcCB7XG4gICAgY29uc3RydWN0b3Ioc2NvcGUpIHtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldChrZXkpIHtcbiAgICAgICAgY29uc3QgbmFtZSA9IHRoaXMuZ2V0QXR0cmlidXRlTmFtZUZvcktleShrZXkpO1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50LmdldEF0dHJpYnV0ZShuYW1lKTtcbiAgICB9XG4gICAgc2V0KGtleSwgdmFsdWUpIHtcbiAgICAgICAgY29uc3QgbmFtZSA9IHRoaXMuZ2V0QXR0cmlidXRlTmFtZUZvcktleShrZXkpO1xuICAgICAgICB0aGlzLmVsZW1lbnQuc2V0QXR0cmlidXRlKG5hbWUsIHZhbHVlKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZ2V0KGtleSk7XG4gICAgfVxuICAgIGhhcyhrZXkpIHtcbiAgICAgICAgY29uc3QgbmFtZSA9IHRoaXMuZ2V0QXR0cmlidXRlTmFtZUZvcktleShrZXkpO1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50Lmhhc0F0dHJpYnV0ZShuYW1lKTtcbiAgICB9XG4gICAgZGVsZXRlKGtleSkge1xuICAgICAgICBpZiAodGhpcy5oYXMoa2V5KSkge1xuICAgICAgICAgICAgY29uc3QgbmFtZSA9IHRoaXMuZ2V0QXR0cmlidXRlTmFtZUZvcktleShrZXkpO1xuICAgICAgICAgICAgdGhpcy5lbGVtZW50LnJlbW92ZUF0dHJpYnV0ZShuYW1lKTtcbiAgICAgICAgICAgIHJldHVybiB0cnVlO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KSB7XG4gICAgICAgIHJldHVybiBgZGF0YS0ke3RoaXMuaWRlbnRpZmllcn0tJHtkYXNoZXJpemUoa2V5KX1gO1xuICAgIH1cbn1cblxuY2xhc3MgR3VpZGUge1xuICAgIGNvbnN0cnVjdG9yKGxvZ2dlcikge1xuICAgICAgICB0aGlzLndhcm5lZEtleXNCeU9iamVjdCA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMubG9nZ2VyID0gbG9nZ2VyO1xuICAgIH1cbiAgICB3YXJuKG9iamVjdCwga2V5LCBtZXNzYWdlKSB7XG4gICAgICAgIGxldCB3YXJuZWRLZXlzID0gdGhpcy53YXJuZWRLZXlzQnlPYmplY3QuZ2V0KG9iamVjdCk7XG4gICAgICAgIGlmICghd2FybmVkS2V5cykge1xuICAgICAgICAgICAgd2FybmVkS2V5cyA9IG5ldyBTZXQoKTtcbiAgICAgICAgICAgIHRoaXMud2FybmVkS2V5c0J5T2JqZWN0LnNldChvYmplY3QsIHdhcm5lZEtleXMpO1xuICAgICAgICB9XG4gICAgICAgIGlmICghd2FybmVkS2V5cy5oYXMoa2V5KSkge1xuICAgICAgICAgICAgd2FybmVkS2V5cy5hZGQoa2V5KTtcbiAgICAgICAgICAgIHRoaXMubG9nZ2VyLndhcm4obWVzc2FnZSwgb2JqZWN0KTtcbiAgICAgICAgfVxuICAgIH1cbn1cblxuZnVuY3Rpb24gYXR0cmlidXRlVmFsdWVDb250YWluc1Rva2VuKGF0dHJpYnV0ZU5hbWUsIHRva2VuKSB7XG4gICAgcmV0dXJuIGBbJHthdHRyaWJ1dGVOYW1lfX49XCIke3Rva2VufVwiXWA7XG59XG5cbmNsYXNzIFRhcmdldFNldCB7XG4gICAgY29uc3RydWN0b3Ioc2NvcGUpIHtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLnNjaGVtYTtcbiAgICB9XG4gICAgaGFzKHRhcmdldE5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZmluZCh0YXJnZXROYW1lKSAhPSBudWxsO1xuICAgIH1cbiAgICBmaW5kKC4uLnRhcmdldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiB0YXJnZXROYW1lcy5yZWR1Y2UoKHRhcmdldCwgdGFyZ2V0TmFtZSkgPT4gdGFyZ2V0IHx8IHRoaXMuZmluZFRhcmdldCh0YXJnZXROYW1lKSB8fCB0aGlzLmZpbmRMZWdhY3lUYXJnZXQodGFyZ2V0TmFtZSksIHVuZGVmaW5lZCk7XG4gICAgfVxuICAgIGZpbmRBbGwoLi4udGFyZ2V0TmFtZXMpIHtcbiAgICAgICAgcmV0dXJuIHRhcmdldE5hbWVzLnJlZHVjZSgodGFyZ2V0cywgdGFyZ2V0TmFtZSkgPT4gW1xuICAgICAgICAgICAgLi4udGFyZ2V0cyxcbiAgICAgICAgICAgIC4uLnRoaXMuZmluZEFsbFRhcmdldHModGFyZ2V0TmFtZSksXG4gICAgICAgICAgICAuLi50aGlzLmZpbmRBbGxMZWdhY3lUYXJnZXRzKHRhcmdldE5hbWUpLFxuICAgICAgICBdLCBbXSk7XG4gICAgfVxuICAgIGZpbmRUYXJnZXQodGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5maW5kRWxlbWVudChzZWxlY3Rvcik7XG4gICAgfVxuICAgIGZpbmRBbGxUYXJnZXRzKHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yKTtcbiAgICB9XG4gICAgZ2V0U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuc2NoZW1hLnRhcmdldEF0dHJpYnV0ZUZvclNjb3BlKHRoaXMuaWRlbnRpZmllcik7XG4gICAgICAgIHJldHVybiBhdHRyaWJ1dGVWYWx1ZUNvbnRhaW5zVG9rZW4oYXR0cmlidXRlTmFtZSwgdGFyZ2V0TmFtZSk7XG4gICAgfVxuICAgIGZpbmRMZWdhY3lUYXJnZXQodGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0TGVnYWN5U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5kZXByZWNhdGUodGhpcy5zY29wZS5maW5kRWxlbWVudChzZWxlY3RvciksIHRhcmdldE5hbWUpO1xuICAgIH1cbiAgICBmaW5kQWxsTGVnYWN5VGFyZ2V0cyh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRMZWdhY3lTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSk7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmZpbmRBbGxFbGVtZW50cyhzZWxlY3RvcikubWFwKChlbGVtZW50KSA9PiB0aGlzLmRlcHJlY2F0ZShlbGVtZW50LCB0YXJnZXROYW1lKSk7XG4gICAgfVxuICAgIGdldExlZ2FjeVNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHRhcmdldERlc2NyaXB0b3IgPSBgJHt0aGlzLmlkZW50aWZpZXJ9LiR7dGFyZ2V0TmFtZX1gO1xuICAgICAgICByZXR1cm4gYXR0cmlidXRlVmFsdWVDb250YWluc1Rva2VuKHRoaXMuc2NoZW1hLnRhcmdldEF0dHJpYnV0ZSwgdGFyZ2V0RGVzY3JpcHRvcik7XG4gICAgfVxuICAgIGRlcHJlY2F0ZShlbGVtZW50LCB0YXJnZXROYW1lKSB7XG4gICAgICAgIGlmIChlbGVtZW50KSB7XG4gICAgICAgICAgICBjb25zdCB7IGlkZW50aWZpZXIgfSA9IHRoaXM7XG4gICAgICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEudGFyZ2V0QXR0cmlidXRlO1xuICAgICAgICAgICAgY29uc3QgcmV2aXNlZEF0dHJpYnV0ZU5hbWUgPSB0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGVGb3JTY29wZShpZGVudGlmaWVyKTtcbiAgICAgICAgICAgIHRoaXMuZ3VpZGUud2FybihlbGVtZW50LCBgdGFyZ2V0OiR7dGFyZ2V0TmFtZX1gLCBgUGxlYXNlIHJlcGxhY2UgJHthdHRyaWJ1dGVOYW1lfT1cIiR7aWRlbnRpZmllcn0uJHt0YXJnZXROYW1lfVwiIHdpdGggJHtyZXZpc2VkQXR0cmlidXRlTmFtZX09XCIke3RhcmdldE5hbWV9XCIuIGAgK1xuICAgICAgICAgICAgICAgIGBUaGUgJHthdHRyaWJ1dGVOYW1lfSBhdHRyaWJ1dGUgaXMgZGVwcmVjYXRlZCBhbmQgd2lsbCBiZSByZW1vdmVkIGluIGEgZnV0dXJlIHZlcnNpb24gb2YgU3RpbXVsdXMuYCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBndWlkZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZ3VpZGU7XG4gICAgfVxufVxuXG5jbGFzcyBPdXRsZXRTZXQge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlLCBjb250cm9sbGVyRWxlbWVudCkge1xuICAgICAgICB0aGlzLnNjb3BlID0gc2NvcGU7XG4gICAgICAgIHRoaXMuY29udHJvbGxlckVsZW1lbnQgPSBjb250cm9sbGVyRWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5zY2hlbWE7XG4gICAgfVxuICAgIGhhcyhvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmZpbmQob3V0bGV0TmFtZSkgIT0gbnVsbDtcbiAgICB9XG4gICAgZmluZCguLi5vdXRsZXROYW1lcykge1xuICAgICAgICByZXR1cm4gb3V0bGV0TmFtZXMucmVkdWNlKChvdXRsZXQsIG91dGxldE5hbWUpID0+IG91dGxldCB8fCB0aGlzLmZpbmRPdXRsZXQob3V0bGV0TmFtZSksIHVuZGVmaW5lZCk7XG4gICAgfVxuICAgIGZpbmRBbGwoLi4ub3V0bGV0TmFtZXMpIHtcbiAgICAgICAgcmV0dXJuIG91dGxldE5hbWVzLnJlZHVjZSgob3V0bGV0cywgb3V0bGV0TmFtZSkgPT4gWy4uLm91dGxldHMsIC4uLnRoaXMuZmluZEFsbE91dGxldHMob3V0bGV0TmFtZSldLCBbXSk7XG4gICAgfVxuICAgIGdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLnNjaGVtYS5vdXRsZXRBdHRyaWJ1dGVGb3JTY29wZSh0aGlzLmlkZW50aWZpZXIsIG91dGxldE5hbWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5jb250cm9sbGVyRWxlbWVudC5nZXRBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSk7XG4gICAgfVxuICAgIGZpbmRPdXRsZXQob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG91dGxldE5hbWUpO1xuICAgICAgICBpZiAoc2VsZWN0b3IpXG4gICAgICAgICAgICByZXR1cm4gdGhpcy5maW5kRWxlbWVudChzZWxlY3Rvciwgb3V0bGV0TmFtZSk7XG4gICAgfVxuICAgIGZpbmRBbGxPdXRsZXRzKG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHNlbGVjdG9yID8gdGhpcy5maW5kQWxsRWxlbWVudHMoc2VsZWN0b3IsIG91dGxldE5hbWUpIDogW107XG4gICAgfVxuICAgIGZpbmRFbGVtZW50KHNlbGVjdG9yLCBvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGVsZW1lbnRzID0gdGhpcy5zY29wZS5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKTtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnRzLmZpbHRlcigoZWxlbWVudCkgPT4gdGhpcy5tYXRjaGVzRWxlbWVudChlbGVtZW50LCBzZWxlY3Rvciwgb3V0bGV0TmFtZSkpWzBdO1xuICAgIH1cbiAgICBmaW5kQWxsRWxlbWVudHMoc2VsZWN0b3IsIG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3QgZWxlbWVudHMgPSB0aGlzLnNjb3BlLnF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpO1xuICAgICAgICByZXR1cm4gZWxlbWVudHMuZmlsdGVyKChlbGVtZW50KSA9PiB0aGlzLm1hdGNoZXNFbGVtZW50KGVsZW1lbnQsIHNlbGVjdG9yLCBvdXRsZXROYW1lKSk7XG4gICAgfVxuICAgIG1hdGNoZXNFbGVtZW50KGVsZW1lbnQsIHNlbGVjdG9yLCBvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGNvbnRyb2xsZXJBdHRyaWJ1dGUgPSBlbGVtZW50LmdldEF0dHJpYnV0ZSh0aGlzLnNjb3BlLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlKSB8fCBcIlwiO1xuICAgICAgICByZXR1cm4gZWxlbWVudC5tYXRjaGVzKHNlbGVjdG9yKSAmJiBjb250cm9sbGVyQXR0cmlidXRlLnNwbGl0KFwiIFwiKS5pbmNsdWRlcyhvdXRsZXROYW1lKTtcbiAgICB9XG59XG5cbmNsYXNzIFNjb3BlIHtcbiAgICBjb25zdHJ1Y3RvcihzY2hlbWEsIGVsZW1lbnQsIGlkZW50aWZpZXIsIGxvZ2dlcikge1xuICAgICAgICB0aGlzLnRhcmdldHMgPSBuZXcgVGFyZ2V0U2V0KHRoaXMpO1xuICAgICAgICB0aGlzLmNsYXNzZXMgPSBuZXcgQ2xhc3NNYXAodGhpcyk7XG4gICAgICAgIHRoaXMuZGF0YSA9IG5ldyBEYXRhTWFwKHRoaXMpO1xuICAgICAgICB0aGlzLmNvbnRhaW5zRWxlbWVudCA9IChlbGVtZW50KSA9PiB7XG4gICAgICAgICAgICByZXR1cm4gZWxlbWVudC5jbG9zZXN0KHRoaXMuY29udHJvbGxlclNlbGVjdG9yKSA9PT0gdGhpcy5lbGVtZW50O1xuICAgICAgICB9O1xuICAgICAgICB0aGlzLnNjaGVtYSA9IHNjaGVtYTtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5pZGVudGlmaWVyID0gaWRlbnRpZmllcjtcbiAgICAgICAgdGhpcy5ndWlkZSA9IG5ldyBHdWlkZShsb2dnZXIpO1xuICAgICAgICB0aGlzLm91dGxldHMgPSBuZXcgT3V0bGV0U2V0KHRoaXMuZG9jdW1lbnRTY29wZSwgZWxlbWVudCk7XG4gICAgfVxuICAgIGZpbmRFbGVtZW50KHNlbGVjdG9yKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnQubWF0Y2hlcyhzZWxlY3RvcikgPyB0aGlzLmVsZW1lbnQgOiB0aGlzLnF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpLmZpbmQodGhpcy5jb250YWluc0VsZW1lbnQpO1xuICAgIH1cbiAgICBmaW5kQWxsRWxlbWVudHMoc2VsZWN0b3IpIHtcbiAgICAgICAgcmV0dXJuIFtcbiAgICAgICAgICAgIC4uLih0aGlzLmVsZW1lbnQubWF0Y2hlcyhzZWxlY3RvcikgPyBbdGhpcy5lbGVtZW50XSA6IFtdKSxcbiAgICAgICAgICAgIC4uLnRoaXMucXVlcnlFbGVtZW50cyhzZWxlY3RvcikuZmlsdGVyKHRoaXMuY29udGFpbnNFbGVtZW50KSxcbiAgICAgICAgXTtcbiAgICB9XG4gICAgcXVlcnlFbGVtZW50cyhzZWxlY3Rvcikge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnQucXVlcnlTZWxlY3RvckFsbChzZWxlY3RvcikpO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlclNlbGVjdG9yKCkge1xuICAgICAgICByZXR1cm4gYXR0cmlidXRlVmFsdWVDb250YWluc1Rva2VuKHRoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGUsIHRoaXMuaWRlbnRpZmllcik7XG4gICAgfVxuICAgIGdldCBpc0RvY3VtZW50U2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnQgPT09IGRvY3VtZW50LmRvY3VtZW50RWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGRvY3VtZW50U2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmlzRG9jdW1lbnRTY29wZVxuICAgICAgICAgICAgPyB0aGlzXG4gICAgICAgICAgICA6IG5ldyBTY29wZSh0aGlzLnNjaGVtYSwgZG9jdW1lbnQuZG9jdW1lbnRFbGVtZW50LCB0aGlzLmlkZW50aWZpZXIsIHRoaXMuZ3VpZGUubG9nZ2VyKTtcbiAgICB9XG59XG5cbmNsYXNzIFNjb3BlT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIHNjaGVtYSwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5zY2hlbWEgPSBzY2hlbWE7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlciA9IG5ldyBWYWx1ZUxpc3RPYnNlcnZlcih0aGlzLmVsZW1lbnQsIHRoaXMuY29udHJvbGxlckF0dHJpYnV0ZSwgdGhpcyk7XG4gICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyQnlFbGVtZW50ID0gbmV3IFdlYWtNYXAoKTtcbiAgICAgICAgdGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cyA9IG5ldyBXZWFrTWFwKCk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlckF0dHJpYnV0ZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGU7XG4gICAgfVxuICAgIHBhcnNlVmFsdWVGb3JUb2tlbih0b2tlbikge1xuICAgICAgICBjb25zdCB7IGVsZW1lbnQsIGNvbnRlbnQ6IGlkZW50aWZpZXIgfSA9IHRva2VuO1xuICAgICAgICByZXR1cm4gdGhpcy5wYXJzZVZhbHVlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcik7XG4gICAgfVxuICAgIHBhcnNlVmFsdWVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgICAgIGNvbnN0IHNjb3Blc0J5SWRlbnRpZmllciA9IHRoaXMuZmV0Y2hTY29wZXNCeUlkZW50aWZpZXJGb3JFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICBsZXQgc2NvcGUgPSBzY29wZXNCeUlkZW50aWZpZXIuZ2V0KGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAoIXNjb3BlKSB7XG4gICAgICAgICAgICBzY29wZSA9IHRoaXMuZGVsZWdhdGUuY3JlYXRlU2NvcGVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICAgICAgICAgIHNjb3Blc0J5SWRlbnRpZmllci5zZXQoaWRlbnRpZmllciwgc2NvcGUpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBzY29wZTtcbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWRWYWx1ZShlbGVtZW50LCB2YWx1ZSkge1xuICAgICAgICBjb25zdCByZWZlcmVuY2VDb3VudCA9ICh0aGlzLnNjb3BlUmVmZXJlbmNlQ291bnRzLmdldCh2YWx1ZSkgfHwgMCkgKyAxO1xuICAgICAgICB0aGlzLnNjb3BlUmVmZXJlbmNlQ291bnRzLnNldCh2YWx1ZSwgcmVmZXJlbmNlQ291bnQpO1xuICAgICAgICBpZiAocmVmZXJlbmNlQ291bnQgPT0gMSkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zY29wZUNvbm5lY3RlZCh2YWx1ZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZFZhbHVlKGVsZW1lbnQsIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHJlZmVyZW5jZUNvdW50ID0gdGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5nZXQodmFsdWUpO1xuICAgICAgICBpZiAocmVmZXJlbmNlQ291bnQpIHtcbiAgICAgICAgICAgIHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMuc2V0KHZhbHVlLCByZWZlcmVuY2VDb3VudCAtIDEpO1xuICAgICAgICAgICAgaWYgKHJlZmVyZW5jZUNvdW50ID09IDEpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmRlbGVnYXRlLnNjb3BlRGlzY29ubmVjdGVkKHZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBmZXRjaFNjb3Blc0J5SWRlbnRpZmllckZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBsZXQgc2NvcGVzQnlJZGVudGlmaWVyID0gdGhpcy5zY29wZXNCeUlkZW50aWZpZXJCeUVsZW1lbnQuZ2V0KGVsZW1lbnQpO1xuICAgICAgICBpZiAoIXNjb3Blc0J5SWRlbnRpZmllcikge1xuICAgICAgICAgICAgc2NvcGVzQnlJZGVudGlmaWVyID0gbmV3IE1hcCgpO1xuICAgICAgICAgICAgdGhpcy5zY29wZXNCeUlkZW50aWZpZXJCeUVsZW1lbnQuc2V0KGVsZW1lbnQsIHNjb3Blc0J5SWRlbnRpZmllcik7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHNjb3Blc0J5SWRlbnRpZmllcjtcbiAgICB9XG59XG5cbmNsYXNzIFJvdXRlciB7XG4gICAgY29uc3RydWN0b3IoYXBwbGljYXRpb24pIHtcbiAgICAgICAgdGhpcy5hcHBsaWNhdGlvbiA9IGFwcGxpY2F0aW9uO1xuICAgICAgICB0aGlzLnNjb3BlT2JzZXJ2ZXIgPSBuZXcgU2NvcGVPYnNlcnZlcih0aGlzLmVsZW1lbnQsIHRoaXMuc2NoZW1hLCB0aGlzKTtcbiAgICAgICAgdGhpcy5zY29wZXNCeUlkZW50aWZpZXIgPSBuZXcgTXVsdGltYXAoKTtcbiAgICAgICAgdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyID0gbmV3IE1hcCgpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24uZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24uc2NoZW1hO1xuICAgIH1cbiAgICBnZXQgbG9nZ2VyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5sb2dnZXI7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVyQXR0cmlidXRlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY2hlbWEuY29udHJvbGxlckF0dHJpYnV0ZTtcbiAgICB9XG4gICAgZ2V0IG1vZHVsZXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci52YWx1ZXMoKSk7XG4gICAgfVxuICAgIGdldCBjb250ZXh0cygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMubW9kdWxlcy5yZWR1Y2UoKGNvbnRleHRzLCBtb2R1bGUpID0+IGNvbnRleHRzLmNvbmNhdChtb2R1bGUuY29udGV4dHMpLCBbXSk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLnNjb3BlT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgbG9hZERlZmluaXRpb24oZGVmaW5pdGlvbikge1xuICAgICAgICB0aGlzLnVubG9hZElkZW50aWZpZXIoZGVmaW5pdGlvbi5pZGVudGlmaWVyKTtcbiAgICAgICAgY29uc3QgbW9kdWxlID0gbmV3IE1vZHVsZSh0aGlzLmFwcGxpY2F0aW9uLCBkZWZpbml0aW9uKTtcbiAgICAgICAgdGhpcy5jb25uZWN0TW9kdWxlKG1vZHVsZSk7XG4gICAgICAgIGNvbnN0IGFmdGVyTG9hZCA9IGRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yLmFmdGVyTG9hZDtcbiAgICAgICAgaWYgKGFmdGVyTG9hZCkge1xuICAgICAgICAgICAgYWZ0ZXJMb2FkLmNhbGwoZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3IsIGRlZmluaXRpb24uaWRlbnRpZmllciwgdGhpcy5hcHBsaWNhdGlvbik7XG4gICAgICAgIH1cbiAgICB9XG4gICAgdW5sb2FkSWRlbnRpZmllcihpZGVudGlmaWVyKSB7XG4gICAgICAgIGNvbnN0IG1vZHVsZSA9IHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5nZXQoaWRlbnRpZmllcik7XG4gICAgICAgIGlmIChtb2R1bGUpIHtcbiAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdE1vZHVsZShtb2R1bGUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldENvbnRleHRGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgICAgIGNvbnN0IG1vZHVsZSA9IHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5nZXQoaWRlbnRpZmllcik7XG4gICAgICAgIGlmIChtb2R1bGUpIHtcbiAgICAgICAgICAgIHJldHVybiBtb2R1bGUuY29udGV4dHMuZmluZCgoY29udGV4dCkgPT4gY29udGV4dC5lbGVtZW50ID09IGVsZW1lbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHByb3Bvc2VUb0Nvbm5lY3RTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgY29uc3Qgc2NvcGUgPSB0aGlzLnNjb3BlT2JzZXJ2ZXIucGFyc2VWYWx1ZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAoc2NvcGUpIHtcbiAgICAgICAgICAgIHRoaXMuc2NvcGVPYnNlcnZlci5lbGVtZW50TWF0Y2hlZFZhbHVlKHNjb3BlLmVsZW1lbnQsIHNjb3BlKTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIGNvbnNvbGUuZXJyb3IoYENvdWxkbid0IGZpbmQgb3IgY3JlYXRlIHNjb3BlIGZvciBpZGVudGlmaWVyOiBcIiR7aWRlbnRpZmllcn1cIiBhbmQgZWxlbWVudDpgLCBlbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsKSB7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24uaGFuZGxlRXJyb3IoZXJyb3IsIG1lc3NhZ2UsIGRldGFpbCk7XG4gICAgfVxuICAgIGNyZWF0ZVNjb3BlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICByZXR1cm4gbmV3IFNjb3BlKHRoaXMuc2NoZW1hLCBlbGVtZW50LCBpZGVudGlmaWVyLCB0aGlzLmxvZ2dlcik7XG4gICAgfVxuICAgIHNjb3BlQ29ubmVjdGVkKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmFkZChzY29wZS5pZGVudGlmaWVyLCBzY29wZSk7XG4gICAgICAgIGNvbnN0IG1vZHVsZSA9IHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5nZXQoc2NvcGUuaWRlbnRpZmllcik7XG4gICAgICAgIGlmIChtb2R1bGUpIHtcbiAgICAgICAgICAgIG1vZHVsZS5jb25uZWN0Q29udGV4dEZvclNjb3BlKHNjb3BlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzY29wZURpc2Nvbm5lY3RlZChzY29wZSkge1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllci5kZWxldGUoc2NvcGUuaWRlbnRpZmllciwgc2NvcGUpO1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KHNjb3BlLmlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICBtb2R1bGUuZGlzY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgY29ubmVjdE1vZHVsZShtb2R1bGUpIHtcbiAgICAgICAgdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLnNldChtb2R1bGUuaWRlbnRpZmllciwgbW9kdWxlKTtcbiAgICAgICAgY29uc3Qgc2NvcGVzID0gdGhpcy5zY29wZXNCeUlkZW50aWZpZXIuZ2V0VmFsdWVzRm9yS2V5KG1vZHVsZS5pZGVudGlmaWVyKTtcbiAgICAgICAgc2NvcGVzLmZvckVhY2goKHNjb3BlKSA9PiBtb2R1bGUuY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkpO1xuICAgIH1cbiAgICBkaXNjb25uZWN0TW9kdWxlKG1vZHVsZSkge1xuICAgICAgICB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZGVsZXRlKG1vZHVsZS5pZGVudGlmaWVyKTtcbiAgICAgICAgY29uc3Qgc2NvcGVzID0gdGhpcy5zY29wZXNCeUlkZW50aWZpZXIuZ2V0VmFsdWVzRm9yS2V5KG1vZHVsZS5pZGVudGlmaWVyKTtcbiAgICAgICAgc2NvcGVzLmZvckVhY2goKHNjb3BlKSA9PiBtb2R1bGUuZGlzY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkpO1xuICAgIH1cbn1cblxuY29uc3QgZGVmYXVsdFNjaGVtYSA9IHtcbiAgICBjb250cm9sbGVyQXR0cmlidXRlOiBcImRhdGEtY29udHJvbGxlclwiLFxuICAgIGFjdGlvbkF0dHJpYnV0ZTogXCJkYXRhLWFjdGlvblwiLFxuICAgIHRhcmdldEF0dHJpYnV0ZTogXCJkYXRhLXRhcmdldFwiLFxuICAgIHRhcmdldEF0dHJpYnV0ZUZvclNjb3BlOiAoaWRlbnRpZmllcikgPT4gYGRhdGEtJHtpZGVudGlmaWVyfS10YXJnZXRgLFxuICAgIG91dGxldEF0dHJpYnV0ZUZvclNjb3BlOiAoaWRlbnRpZmllciwgb3V0bGV0KSA9PiBgZGF0YS0ke2lkZW50aWZpZXJ9LSR7b3V0bGV0fS1vdXRsZXRgLFxuICAgIGtleU1hcHBpbmdzOiBPYmplY3QuYXNzaWduKE9iamVjdC5hc3NpZ24oeyBlbnRlcjogXCJFbnRlclwiLCB0YWI6IFwiVGFiXCIsIGVzYzogXCJFc2NhcGVcIiwgc3BhY2U6IFwiIFwiLCB1cDogXCJBcnJvd1VwXCIsIGRvd246IFwiQXJyb3dEb3duXCIsIGxlZnQ6IFwiQXJyb3dMZWZ0XCIsIHJpZ2h0OiBcIkFycm93UmlnaHRcIiwgaG9tZTogXCJIb21lXCIsIGVuZDogXCJFbmRcIiwgcGFnZV91cDogXCJQYWdlVXBcIiwgcGFnZV9kb3duOiBcIlBhZ2VEb3duXCIgfSwgb2JqZWN0RnJvbUVudHJpZXMoXCJhYmNkZWZnaGlqa2xtbm9wcXJzdHV2d3h5elwiLnNwbGl0KFwiXCIpLm1hcCgoYykgPT4gW2MsIGNdKSkpLCBvYmplY3RGcm9tRW50cmllcyhcIjAxMjM0NTY3ODlcIi5zcGxpdChcIlwiKS5tYXAoKG4pID0+IFtuLCBuXSkpKSxcbn07XG5mdW5jdGlvbiBvYmplY3RGcm9tRW50cmllcyhhcnJheSkge1xuICAgIHJldHVybiBhcnJheS5yZWR1Y2UoKG1lbW8sIFtrLCB2XSkgPT4gKE9iamVjdC5hc3NpZ24oT2JqZWN0LmFzc2lnbih7fSwgbWVtbyksIHsgW2tdOiB2IH0pKSwge30pO1xufVxuXG5jbGFzcyBBcHBsaWNhdGlvbiB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCA9IGRvY3VtZW50LmRvY3VtZW50RWxlbWVudCwgc2NoZW1hID0gZGVmYXVsdFNjaGVtYSkge1xuICAgICAgICB0aGlzLmxvZ2dlciA9IGNvbnNvbGU7XG4gICAgICAgIHRoaXMuZGVidWcgPSBmYWxzZTtcbiAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5ID0gKGlkZW50aWZpZXIsIGZ1bmN0aW9uTmFtZSwgZGV0YWlsID0ge30pID0+IHtcbiAgICAgICAgICAgIGlmICh0aGlzLmRlYnVnKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5sb2dGb3JtYXR0ZWRNZXNzYWdlKGlkZW50aWZpZXIsIGZ1bmN0aW9uTmFtZSwgZGV0YWlsKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfTtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5zY2hlbWEgPSBzY2hlbWE7XG4gICAgICAgIHRoaXMuZGlzcGF0Y2hlciA9IG5ldyBEaXNwYXRjaGVyKHRoaXMpO1xuICAgICAgICB0aGlzLnJvdXRlciA9IG5ldyBSb3V0ZXIodGhpcyk7XG4gICAgICAgIHRoaXMuYWN0aW9uRGVzY3JpcHRvckZpbHRlcnMgPSBPYmplY3QuYXNzaWduKHt9LCBkZWZhdWx0QWN0aW9uRGVzY3JpcHRvckZpbHRlcnMpO1xuICAgIH1cbiAgICBzdGF0aWMgc3RhcnQoZWxlbWVudCwgc2NoZW1hKSB7XG4gICAgICAgIGNvbnN0IGFwcGxpY2F0aW9uID0gbmV3IHRoaXMoZWxlbWVudCwgc2NoZW1hKTtcbiAgICAgICAgYXBwbGljYXRpb24uc3RhcnQoKTtcbiAgICAgICAgcmV0dXJuIGFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBhc3luYyBzdGFydCgpIHtcbiAgICAgICAgYXdhaXQgZG9tUmVhZHkoKTtcbiAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiYXBwbGljYXRpb25cIiwgXCJzdGFydGluZ1wiKTtcbiAgICAgICAgdGhpcy5kaXNwYXRjaGVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMucm91dGVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RhcnRcIik7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RvcHBpbmdcIik7XG4gICAgICAgIHRoaXMuZGlzcGF0Y2hlci5zdG9wKCk7XG4gICAgICAgIHRoaXMucm91dGVyLnN0b3AoKTtcbiAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiYXBwbGljYXRpb25cIiwgXCJzdG9wXCIpO1xuICAgIH1cbiAgICByZWdpc3RlcihpZGVudGlmaWVyLCBjb250cm9sbGVyQ29uc3RydWN0b3IpIHtcbiAgICAgICAgdGhpcy5sb2FkKHsgaWRlbnRpZmllciwgY29udHJvbGxlckNvbnN0cnVjdG9yIH0pO1xuICAgIH1cbiAgICByZWdpc3RlckFjdGlvbk9wdGlvbihuYW1lLCBmaWx0ZXIpIHtcbiAgICAgICAgdGhpcy5hY3Rpb25EZXNjcmlwdG9yRmlsdGVyc1tuYW1lXSA9IGZpbHRlcjtcbiAgICB9XG4gICAgbG9hZChoZWFkLCAuLi5yZXN0KSB7XG4gICAgICAgIGNvbnN0IGRlZmluaXRpb25zID0gQXJyYXkuaXNBcnJheShoZWFkKSA/IGhlYWQgOiBbaGVhZCwgLi4ucmVzdF07XG4gICAgICAgIGRlZmluaXRpb25zLmZvckVhY2goKGRlZmluaXRpb24pID0+IHtcbiAgICAgICAgICAgIGlmIChkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3Rvci5zaG91bGRMb2FkKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5yb3V0ZXIubG9hZERlZmluaXRpb24oZGVmaW5pdGlvbik7XG4gICAgICAgICAgICB9XG4gICAgICAgIH0pO1xuICAgIH1cbiAgICB1bmxvYWQoaGVhZCwgLi4ucmVzdCkge1xuICAgICAgICBjb25zdCBpZGVudGlmaWVycyA9IEFycmF5LmlzQXJyYXkoaGVhZCkgPyBoZWFkIDogW2hlYWQsIC4uLnJlc3RdO1xuICAgICAgICBpZGVudGlmaWVycy5mb3JFYWNoKChpZGVudGlmaWVyKSA9PiB0aGlzLnJvdXRlci51bmxvYWRJZGVudGlmaWVyKGlkZW50aWZpZXIpKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5yb3V0ZXIuY29udGV4dHMubWFwKChjb250ZXh0KSA9PiBjb250ZXh0LmNvbnRyb2xsZXIpO1xuICAgIH1cbiAgICBnZXRDb250cm9sbGVyRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBjb250ZXh0ID0gdGhpcy5yb3V0ZXIuZ2V0Q29udGV4dEZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xuICAgICAgICByZXR1cm4gY29udGV4dCA/IGNvbnRleHQuY29udHJvbGxlciA6IG51bGw7XG4gICAgfVxuICAgIGhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwpIHtcbiAgICAgICAgdmFyIF9hO1xuICAgICAgICB0aGlzLmxvZ2dlci5lcnJvcihgJXNcXG5cXG4lb1xcblxcbiVvYCwgbWVzc2FnZSwgZXJyb3IsIGRldGFpbCk7XG4gICAgICAgIChfYSA9IHdpbmRvdy5vbmVycm9yKSA9PT0gbnVsbCB8fCBfYSA9PT0gdm9pZCAwID8gdm9pZCAwIDogX2EuY2FsbCh3aW5kb3csIG1lc3NhZ2UsIFwiXCIsIDAsIDAsIGVycm9yKTtcbiAgICB9XG4gICAgbG9nRm9ybWF0dGVkTWVzc2FnZShpZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCA9IHt9KSB7XG4gICAgICAgIGRldGFpbCA9IE9iamVjdC5hc3NpZ24oeyBhcHBsaWNhdGlvbjogdGhpcyB9LCBkZXRhaWwpO1xuICAgICAgICB0aGlzLmxvZ2dlci5ncm91cENvbGxhcHNlZChgJHtpZGVudGlmaWVyfSAjJHtmdW5jdGlvbk5hbWV9YCk7XG4gICAgICAgIHRoaXMubG9nZ2VyLmxvZyhcImRldGFpbHM6XCIsIE9iamVjdC5hc3NpZ24oe30sIGRldGFpbCkpO1xuICAgICAgICB0aGlzLmxvZ2dlci5ncm91cEVuZCgpO1xuICAgIH1cbn1cbmZ1bmN0aW9uIGRvbVJlYWR5KCkge1xuICAgIHJldHVybiBuZXcgUHJvbWlzZSgocmVzb2x2ZSkgPT4ge1xuICAgICAgICBpZiAoZG9jdW1lbnQucmVhZHlTdGF0ZSA9PSBcImxvYWRpbmdcIikge1xuICAgICAgICAgICAgZG9jdW1lbnQuYWRkRXZlbnRMaXN0ZW5lcihcIkRPTUNvbnRlbnRMb2FkZWRcIiwgKCkgPT4gcmVzb2x2ZSgpKTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJlc29sdmUoKTtcbiAgICAgICAgfVxuICAgIH0pO1xufVxuXG5mdW5jdGlvbiBDbGFzc1Byb3BlcnRpZXNCbGVzc2luZyhjb25zdHJ1Y3Rvcikge1xuICAgIGNvbnN0IGNsYXNzZXMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJjbGFzc2VzXCIpO1xuICAgIHJldHVybiBjbGFzc2VzLnJlZHVjZSgocHJvcGVydGllcywgY2xhc3NEZWZpbml0aW9uKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JDbGFzc0RlZmluaXRpb24oY2xhc3NEZWZpbml0aW9uKSk7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gcHJvcGVydGllc0ZvckNsYXNzRGVmaW5pdGlvbihrZXkpIHtcbiAgICByZXR1cm4ge1xuICAgICAgICBbYCR7a2V5fUNsYXNzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCB7IGNsYXNzZXMgfSA9IHRoaXM7XG4gICAgICAgICAgICAgICAgaWYgKGNsYXNzZXMuaGFzKGtleSkpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGNsYXNzZXMuZ2V0KGtleSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICBjb25zdCBhdHRyaWJ1dGUgPSBjbGFzc2VzLmdldEF0dHJpYnV0ZU5hbWUoa2V5KTtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBNaXNzaW5nIGF0dHJpYnV0ZSBcIiR7YXR0cmlidXRlfVwiYCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2Ake2tleX1DbGFzc2VzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy5jbGFzc2VzLmdldEFsbChrZXkpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2BoYXMke2NhcGl0YWxpemUoa2V5KX1DbGFzc2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMuY2xhc3Nlcy5oYXMoa2V5KTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbn1cblxuZnVuY3Rpb24gT3V0bGV0UHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3Qgb3V0bGV0cyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBcIm91dGxldHNcIik7XG4gICAgcmV0dXJuIG91dGxldHMucmVkdWNlKChwcm9wZXJ0aWVzLCBvdXRsZXREZWZpbml0aW9uKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JPdXRsZXREZWZpbml0aW9uKG91dGxldERlZmluaXRpb24pKTtcbiAgICB9LCB7fSk7XG59XG5mdW5jdGlvbiBnZXRPdXRsZXRDb250cm9sbGVyKGNvbnRyb2xsZXIsIGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICByZXR1cm4gY29udHJvbGxlci5hcHBsaWNhdGlvbi5nZXRDb250cm9sbGVyRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcik7XG59XG5mdW5jdGlvbiBnZXRDb250cm9sbGVyQW5kRW5zdXJlQ29ubmVjdGVkU2NvcGUoY29udHJvbGxlciwgZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgIGxldCBvdXRsZXRDb250cm9sbGVyID0gZ2V0T3V0bGV0Q29udHJvbGxlcihjb250cm9sbGVyLCBlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICBpZiAob3V0bGV0Q29udHJvbGxlcilcbiAgICAgICAgcmV0dXJuIG91dGxldENvbnRyb2xsZXI7XG4gICAgY29udHJvbGxlci5hcHBsaWNhdGlvbi5yb3V0ZXIucHJvcG9zZVRvQ29ubmVjdFNjb3BlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgb3V0bGV0Q29udHJvbGxlciA9IGdldE91dGxldENvbnRyb2xsZXIoY29udHJvbGxlciwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgaWYgKG91dGxldENvbnRyb2xsZXIpXG4gICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xufVxuZnVuY3Rpb24gcHJvcGVydGllc0Zvck91dGxldERlZmluaXRpb24obmFtZSkge1xuICAgIGNvbnN0IGNhbWVsaXplZE5hbWUgPSBuYW1lc3BhY2VDYW1lbGl6ZShuYW1lKTtcbiAgICByZXR1cm4ge1xuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IG91dGxldEVsZW1lbnQgPSB0aGlzLm91dGxldHMuZmluZChuYW1lKTtcbiAgICAgICAgICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMub3V0bGV0cy5nZXRTZWxlY3RvckZvck91dGxldE5hbWUobmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG91dGxldEVsZW1lbnQpIHtcbiAgICAgICAgICAgICAgICAgICAgY29uc3Qgb3V0bGV0Q29udHJvbGxlciA9IGdldENvbnRyb2xsZXJBbmRFbnN1cmVDb25uZWN0ZWRTY29wZSh0aGlzLCBvdXRsZXRFbGVtZW50LCBuYW1lKTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKG91dGxldENvbnRyb2xsZXIpXG4gICAgICAgICAgICAgICAgICAgICAgICByZXR1cm4gb3V0bGV0Q29udHJvbGxlcjtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBUaGUgcHJvdmlkZWQgb3V0bGV0IGVsZW1lbnQgaXMgbWlzc2luZyBhbiBvdXRsZXQgY29udHJvbGxlciBcIiR7bmFtZX1cIiBpbnN0YW5jZSBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCJgKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBNaXNzaW5nIG91dGxldCBlbGVtZW50IFwiJHtuYW1lfVwiIGZvciBob3N0IGNvbnRyb2xsZXIgXCIke3RoaXMuaWRlbnRpZmllcn1cIi4gU3RpbXVsdXMgY291bGRuJ3QgZmluZCBhIG1hdGNoaW5nIG91dGxldCBlbGVtZW50IHVzaW5nIHNlbGVjdG9yIFwiJHtzZWxlY3Rvcn1cIi5gKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtjYW1lbGl6ZWROYW1lfU91dGxldHNgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IG91dGxldHMgPSB0aGlzLm91dGxldHMuZmluZEFsbChuYW1lKTtcbiAgICAgICAgICAgICAgICBpZiAob3V0bGV0cy5sZW5ndGggPiAwKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRzXG4gICAgICAgICAgICAgICAgICAgICAgICAubWFwKChvdXRsZXRFbGVtZW50KSA9PiB7XG4gICAgICAgICAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRDb250cm9sbGVyID0gZ2V0Q29udHJvbGxlckFuZEVuc3VyZUNvbm5lY3RlZFNjb3BlKHRoaXMsIG91dGxldEVsZW1lbnQsIG5hbWUpO1xuICAgICAgICAgICAgICAgICAgICAgICAgaWYgKG91dGxldENvbnRyb2xsZXIpXG4gICAgICAgICAgICAgICAgICAgICAgICAgICAgcmV0dXJuIG91dGxldENvbnRyb2xsZXI7XG4gICAgICAgICAgICAgICAgICAgICAgICBjb25zb2xlLndhcm4oYFRoZSBwcm92aWRlZCBvdXRsZXQgZWxlbWVudCBpcyBtaXNzaW5nIGFuIG91dGxldCBjb250cm9sbGVyIFwiJHtuYW1lfVwiIGluc3RhbmNlIGZvciBob3N0IGNvbnRyb2xsZXIgXCIke3RoaXMuaWRlbnRpZmllcn1cImAsIG91dGxldEVsZW1lbnQpO1xuICAgICAgICAgICAgICAgICAgICB9KVxuICAgICAgICAgICAgICAgICAgICAgICAgLmZpbHRlcigoY29udHJvbGxlcikgPT4gY29udHJvbGxlcik7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHJldHVybiBbXTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtjYW1lbGl6ZWROYW1lfU91dGxldEVsZW1lbnRgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IG91dGxldEVsZW1lbnQgPSB0aGlzLm91dGxldHMuZmluZChuYW1lKTtcbiAgICAgICAgICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMub3V0bGV0cy5nZXRTZWxlY3RvckZvck91dGxldE5hbWUobmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG91dGxldEVsZW1lbnQpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIG91dGxldEVsZW1lbnQ7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICB0aHJvdyBuZXcgRXJyb3IoYE1pc3Npbmcgb3V0bGV0IGVsZW1lbnQgXCIke25hbWV9XCIgZm9yIGhvc3QgY29udHJvbGxlciBcIiR7dGhpcy5pZGVudGlmaWVyfVwiLiBTdGltdWx1cyBjb3VsZG4ndCBmaW5kIGEgbWF0Y2hpbmcgb3V0bGV0IGVsZW1lbnQgdXNpbmcgc2VsZWN0b3IgXCIke3NlbGVjdG9yfVwiLmApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtjYW1lbGl6ZWROYW1lfU91dGxldEVsZW1lbnRzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXRzLmZpbmRBbGwobmFtZSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYGhhcyR7Y2FwaXRhbGl6ZShjYW1lbGl6ZWROYW1lKX1PdXRsZXRgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLm91dGxldHMuaGFzKG5hbWUpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICB9O1xufVxuXG5mdW5jdGlvbiBUYXJnZXRQcm9wZXJ0aWVzQmxlc3NpbmcoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCB0YXJnZXRzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwidGFyZ2V0c1wiKTtcbiAgICByZXR1cm4gdGFyZ2V0cy5yZWR1Y2UoKHByb3BlcnRpZXMsIHRhcmdldERlZmluaXRpb24pID0+IHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24ocHJvcGVydGllcywgcHJvcGVydGllc0ZvclRhcmdldERlZmluaXRpb24odGFyZ2V0RGVmaW5pdGlvbikpO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JUYXJnZXREZWZpbml0aW9uKG5hbWUpIHtcbiAgICByZXR1cm4ge1xuICAgICAgICBbYCR7bmFtZX1UYXJnZXRgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IHRhcmdldCA9IHRoaXMudGFyZ2V0cy5maW5kKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmICh0YXJnZXQpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIHRhcmdldDtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyB0YXJnZXQgZWxlbWVudCBcIiR7bmFtZX1cIiBmb3IgXCIke3RoaXMuaWRlbnRpZmllcn1cIiBjb250cm9sbGVyYCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2Ake25hbWV9VGFyZ2V0c2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMudGFyZ2V0cy5maW5kQWxsKG5hbWUpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2BoYXMke2NhcGl0YWxpemUobmFtZSl9VGFyZ2V0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy50YXJnZXRzLmhhcyhuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbn1cblxuZnVuY3Rpb24gVmFsdWVQcm9wZXJ0aWVzQmxlc3NpbmcoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCB2YWx1ZURlZmluaXRpb25QYWlycyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBcInZhbHVlc1wiKTtcbiAgICBjb25zdCBwcm9wZXJ0eURlc2NyaXB0b3JNYXAgPSB7XG4gICAgICAgIHZhbHVlRGVzY3JpcHRvck1hcDoge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB2YWx1ZURlZmluaXRpb25QYWlycy5yZWR1Y2UoKHJlc3VsdCwgdmFsdWVEZWZpbml0aW9uUGFpcikgPT4ge1xuICAgICAgICAgICAgICAgICAgICBjb25zdCB2YWx1ZURlc2NyaXB0b3IgPSBwYXJzZVZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpciwgdGhpcy5pZGVudGlmaWVyKTtcbiAgICAgICAgICAgICAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuZGF0YS5nZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KHZhbHVlRGVzY3JpcHRvci5rZXkpO1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihyZXN1bHQsIHsgW2F0dHJpYnV0ZU5hbWVdOiB2YWx1ZURlc2NyaXB0b3IgfSk7XG4gICAgICAgICAgICAgICAgfSwge30pO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICB9O1xuICAgIHJldHVybiB2YWx1ZURlZmluaXRpb25QYWlycy5yZWR1Y2UoKHByb3BlcnRpZXMsIHZhbHVlRGVmaW5pdGlvblBhaXIpID0+IHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24ocHJvcGVydGllcywgcHJvcGVydGllc0ZvclZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpcikpO1xuICAgIH0sIHByb3BlcnR5RGVzY3JpcHRvck1hcCk7XG59XG5mdW5jdGlvbiBwcm9wZXJ0aWVzRm9yVmFsdWVEZWZpbml0aW9uUGFpcih2YWx1ZURlZmluaXRpb25QYWlyLCBjb250cm9sbGVyKSB7XG4gICAgY29uc3QgZGVmaW5pdGlvbiA9IHBhcnNlVmFsdWVEZWZpbml0aW9uUGFpcih2YWx1ZURlZmluaXRpb25QYWlyLCBjb250cm9sbGVyKTtcbiAgICBjb25zdCB7IGtleSwgbmFtZSwgcmVhZGVyOiByZWFkLCB3cml0ZXI6IHdyaXRlIH0gPSBkZWZpbml0aW9uO1xuICAgIHJldHVybiB7XG4gICAgICAgIFtuYW1lXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gdGhpcy5kYXRhLmdldChrZXkpO1xuICAgICAgICAgICAgICAgIGlmICh2YWx1ZSAhPT0gbnVsbCkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gcmVhZCh2YWx1ZSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gZGVmaW5pdGlvbi5kZWZhdWx0VmFsdWU7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSxcbiAgICAgICAgICAgIHNldCh2YWx1ZSkge1xuICAgICAgICAgICAgICAgIGlmICh2YWx1ZSA9PT0gdW5kZWZpbmVkKSB7XG4gICAgICAgICAgICAgICAgICAgIHRoaXMuZGF0YS5kZWxldGUoa2V5KTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgICAgIHRoaXMuZGF0YS5zZXQoa2V5LCB3cml0ZSh2YWx1ZSkpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKG5hbWUpfWBdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMuZGF0YS5oYXMoa2V5KSB8fCBkZWZpbml0aW9uLmhhc0N1c3RvbURlZmF1bHRWYWx1ZTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVEZWZpbml0aW9uUGFpcihbdG9rZW4sIHR5cGVEZWZpbml0aW9uXSwgY29udHJvbGxlcikge1xuICAgIHJldHVybiB2YWx1ZURlc2NyaXB0b3JGb3JUb2tlbkFuZFR5cGVEZWZpbml0aW9uKHtcbiAgICAgICAgY29udHJvbGxlcixcbiAgICAgICAgdG9rZW4sXG4gICAgICAgIHR5cGVEZWZpbml0aW9uLFxuICAgIH0pO1xufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVDb25zdGFudChjb25zdGFudCkge1xuICAgIHN3aXRjaCAoY29uc3RhbnQpIHtcbiAgICAgICAgY2FzZSBBcnJheTpcbiAgICAgICAgICAgIHJldHVybiBcImFycmF5XCI7XG4gICAgICAgIGNhc2UgQm9vbGVhbjpcbiAgICAgICAgICAgIHJldHVybiBcImJvb2xlYW5cIjtcbiAgICAgICAgY2FzZSBOdW1iZXI6XG4gICAgICAgICAgICByZXR1cm4gXCJudW1iZXJcIjtcbiAgICAgICAgY2FzZSBPYmplY3Q6XG4gICAgICAgICAgICByZXR1cm4gXCJvYmplY3RcIjtcbiAgICAgICAgY2FzZSBTdHJpbmc6XG4gICAgICAgICAgICByZXR1cm4gXCJzdHJpbmdcIjtcbiAgICB9XG59XG5mdW5jdGlvbiBwYXJzZVZhbHVlVHlwZURlZmF1bHQoZGVmYXVsdFZhbHVlKSB7XG4gICAgc3dpdGNoICh0eXBlb2YgZGVmYXVsdFZhbHVlKSB7XG4gICAgICAgIGNhc2UgXCJib29sZWFuXCI6XG4gICAgICAgICAgICByZXR1cm4gXCJib29sZWFuXCI7XG4gICAgICAgIGNhc2UgXCJudW1iZXJcIjpcbiAgICAgICAgICAgIHJldHVybiBcIm51bWJlclwiO1xuICAgICAgICBjYXNlIFwic3RyaW5nXCI6XG4gICAgICAgICAgICByZXR1cm4gXCJzdHJpbmdcIjtcbiAgICB9XG4gICAgaWYgKEFycmF5LmlzQXJyYXkoZGVmYXVsdFZhbHVlKSlcbiAgICAgICAgcmV0dXJuIFwiYXJyYXlcIjtcbiAgICBpZiAoT2JqZWN0LnByb3RvdHlwZS50b1N0cmluZy5jYWxsKGRlZmF1bHRWYWx1ZSkgPT09IFwiW29iamVjdCBPYmplY3RdXCIpXG4gICAgICAgIHJldHVybiBcIm9iamVjdFwiO1xufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVPYmplY3QocGF5bG9hZCkge1xuICAgIGNvbnN0IHsgY29udHJvbGxlciwgdG9rZW4sIHR5cGVPYmplY3QgfSA9IHBheWxvYWQ7XG4gICAgY29uc3QgaGFzVHlwZSA9IGlzU29tZXRoaW5nKHR5cGVPYmplY3QudHlwZSk7XG4gICAgY29uc3QgaGFzRGVmYXVsdCA9IGlzU29tZXRoaW5nKHR5cGVPYmplY3QuZGVmYXVsdCk7XG4gICAgY29uc3QgZnVsbE9iamVjdCA9IGhhc1R5cGUgJiYgaGFzRGVmYXVsdDtcbiAgICBjb25zdCBvbmx5VHlwZSA9IGhhc1R5cGUgJiYgIWhhc0RlZmF1bHQ7XG4gICAgY29uc3Qgb25seURlZmF1bHQgPSAhaGFzVHlwZSAmJiBoYXNEZWZhdWx0O1xuICAgIGNvbnN0IHR5cGVGcm9tT2JqZWN0ID0gcGFyc2VWYWx1ZVR5cGVDb25zdGFudCh0eXBlT2JqZWN0LnR5cGUpO1xuICAgIGNvbnN0IHR5cGVGcm9tRGVmYXVsdFZhbHVlID0gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KHBheWxvYWQudHlwZU9iamVjdC5kZWZhdWx0KTtcbiAgICBpZiAob25seVR5cGUpXG4gICAgICAgIHJldHVybiB0eXBlRnJvbU9iamVjdDtcbiAgICBpZiAob25seURlZmF1bHQpXG4gICAgICAgIHJldHVybiB0eXBlRnJvbURlZmF1bHRWYWx1ZTtcbiAgICBpZiAodHlwZUZyb21PYmplY3QgIT09IHR5cGVGcm9tRGVmYXVsdFZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHByb3BlcnR5UGF0aCA9IGNvbnRyb2xsZXIgPyBgJHtjb250cm9sbGVyfS4ke3Rva2VufWAgOiB0b2tlbjtcbiAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBUaGUgc3BlY2lmaWVkIGRlZmF1bHQgdmFsdWUgZm9yIHRoZSBTdGltdWx1cyBWYWx1ZSBcIiR7cHJvcGVydHlQYXRofVwiIG11c3QgbWF0Y2ggdGhlIGRlZmluZWQgdHlwZSBcIiR7dHlwZUZyb21PYmplY3R9XCIuIFRoZSBwcm92aWRlZCBkZWZhdWx0IHZhbHVlIG9mIFwiJHt0eXBlT2JqZWN0LmRlZmF1bHR9XCIgaXMgb2YgdHlwZSBcIiR7dHlwZUZyb21EZWZhdWx0VmFsdWV9XCIuYCk7XG4gICAgfVxuICAgIGlmIChmdWxsT2JqZWN0KVxuICAgICAgICByZXR1cm4gdHlwZUZyb21PYmplY3Q7XG59XG5mdW5jdGlvbiBwYXJzZVZhbHVlVHlwZURlZmluaXRpb24ocGF5bG9hZCkge1xuICAgIGNvbnN0IHsgY29udHJvbGxlciwgdG9rZW4sIHR5cGVEZWZpbml0aW9uIH0gPSBwYXlsb2FkO1xuICAgIGNvbnN0IHR5cGVPYmplY3QgPSB7IGNvbnRyb2xsZXIsIHRva2VuLCB0eXBlT2JqZWN0OiB0eXBlRGVmaW5pdGlvbiB9O1xuICAgIGNvbnN0IHR5cGVGcm9tT2JqZWN0ID0gcGFyc2VWYWx1ZVR5cGVPYmplY3QodHlwZU9iamVjdCk7XG4gICAgY29uc3QgdHlwZUZyb21EZWZhdWx0VmFsdWUgPSBwYXJzZVZhbHVlVHlwZURlZmF1bHQodHlwZURlZmluaXRpb24pO1xuICAgIGNvbnN0IHR5cGVGcm9tQ29uc3RhbnQgPSBwYXJzZVZhbHVlVHlwZUNvbnN0YW50KHR5cGVEZWZpbml0aW9uKTtcbiAgICBjb25zdCB0eXBlID0gdHlwZUZyb21PYmplY3QgfHwgdHlwZUZyb21EZWZhdWx0VmFsdWUgfHwgdHlwZUZyb21Db25zdGFudDtcbiAgICBpZiAodHlwZSlcbiAgICAgICAgcmV0dXJuIHR5cGU7XG4gICAgY29uc3QgcHJvcGVydHlQYXRoID0gY29udHJvbGxlciA/IGAke2NvbnRyb2xsZXJ9LiR7dHlwZURlZmluaXRpb259YCA6IHRva2VuO1xuICAgIHRocm93IG5ldyBFcnJvcihgVW5rbm93biB2YWx1ZSB0eXBlIFwiJHtwcm9wZXJ0eVBhdGh9XCIgZm9yIFwiJHt0b2tlbn1cIiB2YWx1ZWApO1xufVxuZnVuY3Rpb24gZGVmYXVsdFZhbHVlRm9yRGVmaW5pdGlvbih0eXBlRGVmaW5pdGlvbikge1xuICAgIGNvbnN0IGNvbnN0YW50ID0gcGFyc2VWYWx1ZVR5cGVDb25zdGFudCh0eXBlRGVmaW5pdGlvbik7XG4gICAgaWYgKGNvbnN0YW50KVxuICAgICAgICByZXR1cm4gZGVmYXVsdFZhbHVlc0J5VHlwZVtjb25zdGFudF07XG4gICAgY29uc3QgaGFzRGVmYXVsdCA9IGhhc1Byb3BlcnR5KHR5cGVEZWZpbml0aW9uLCBcImRlZmF1bHRcIik7XG4gICAgY29uc3QgaGFzVHlwZSA9IGhhc1Byb3BlcnR5KHR5cGVEZWZpbml0aW9uLCBcInR5cGVcIik7XG4gICAgY29uc3QgdHlwZU9iamVjdCA9IHR5cGVEZWZpbml0aW9uO1xuICAgIGlmIChoYXNEZWZhdWx0KVxuICAgICAgICByZXR1cm4gdHlwZU9iamVjdC5kZWZhdWx0O1xuICAgIGlmIChoYXNUeXBlKSB7XG4gICAgICAgIGNvbnN0IHsgdHlwZSB9ID0gdHlwZU9iamVjdDtcbiAgICAgICAgY29uc3QgY29uc3RhbnRGcm9tVHlwZSA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZSk7XG4gICAgICAgIGlmIChjb25zdGFudEZyb21UeXBlKVxuICAgICAgICAgICAgcmV0dXJuIGRlZmF1bHRWYWx1ZXNCeVR5cGVbY29uc3RhbnRGcm9tVHlwZV07XG4gICAgfVxuICAgIHJldHVybiB0eXBlRGVmaW5pdGlvbjtcbn1cbmZ1bmN0aW9uIHZhbHVlRGVzY3JpcHRvckZvclRva2VuQW5kVHlwZURlZmluaXRpb24ocGF5bG9hZCkge1xuICAgIGNvbnN0IHsgdG9rZW4sIHR5cGVEZWZpbml0aW9uIH0gPSBwYXlsb2FkO1xuICAgIGNvbnN0IGtleSA9IGAke2Rhc2hlcml6ZSh0b2tlbil9LXZhbHVlYDtcbiAgICBjb25zdCB0eXBlID0gcGFyc2VWYWx1ZVR5cGVEZWZpbml0aW9uKHBheWxvYWQpO1xuICAgIHJldHVybiB7XG4gICAgICAgIHR5cGUsXG4gICAgICAgIGtleSxcbiAgICAgICAgbmFtZTogY2FtZWxpemUoa2V5KSxcbiAgICAgICAgZ2V0IGRlZmF1bHRWYWx1ZSgpIHtcbiAgICAgICAgICAgIHJldHVybiBkZWZhdWx0VmFsdWVGb3JEZWZpbml0aW9uKHR5cGVEZWZpbml0aW9uKTtcbiAgICAgICAgfSxcbiAgICAgICAgZ2V0IGhhc0N1c3RvbURlZmF1bHRWYWx1ZSgpIHtcbiAgICAgICAgICAgIHJldHVybiBwYXJzZVZhbHVlVHlwZURlZmF1bHQodHlwZURlZmluaXRpb24pICE9PSB1bmRlZmluZWQ7XG4gICAgICAgIH0sXG4gICAgICAgIHJlYWRlcjogcmVhZGVyc1t0eXBlXSxcbiAgICAgICAgd3JpdGVyOiB3cml0ZXJzW3R5cGVdIHx8IHdyaXRlcnMuZGVmYXVsdCxcbiAgICB9O1xufVxuY29uc3QgZGVmYXVsdFZhbHVlc0J5VHlwZSA9IHtcbiAgICBnZXQgYXJyYXkoKSB7XG4gICAgICAgIHJldHVybiBbXTtcbiAgICB9LFxuICAgIGJvb2xlYW46IGZhbHNlLFxuICAgIG51bWJlcjogMCxcbiAgICBnZXQgb2JqZWN0KCkge1xuICAgICAgICByZXR1cm4ge307XG4gICAgfSxcbiAgICBzdHJpbmc6IFwiXCIsXG59O1xuY29uc3QgcmVhZGVycyA9IHtcbiAgICBhcnJheSh2YWx1ZSkge1xuICAgICAgICBjb25zdCBhcnJheSA9IEpTT04ucGFyc2UodmFsdWUpO1xuICAgICAgICBpZiAoIUFycmF5LmlzQXJyYXkoYXJyYXkpKSB7XG4gICAgICAgICAgICB0aHJvdyBuZXcgVHlwZUVycm9yKGBleHBlY3RlZCB2YWx1ZSBvZiB0eXBlIFwiYXJyYXlcIiBidXQgaW5zdGVhZCBnb3QgdmFsdWUgXCIke3ZhbHVlfVwiIG9mIHR5cGUgXCIke3BhcnNlVmFsdWVUeXBlRGVmYXVsdChhcnJheSl9XCJgKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gYXJyYXk7XG4gICAgfSxcbiAgICBib29sZWFuKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiAhKHZhbHVlID09IFwiMFwiIHx8IFN0cmluZyh2YWx1ZSkudG9Mb3dlckNhc2UoKSA9PSBcImZhbHNlXCIpO1xuICAgIH0sXG4gICAgbnVtYmVyKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiBOdW1iZXIodmFsdWUucmVwbGFjZSgvXy9nLCBcIlwiKSk7XG4gICAgfSxcbiAgICBvYmplY3QodmFsdWUpIHtcbiAgICAgICAgY29uc3Qgb2JqZWN0ID0gSlNPTi5wYXJzZSh2YWx1ZSk7XG4gICAgICAgIGlmIChvYmplY3QgPT09IG51bGwgfHwgdHlwZW9mIG9iamVjdCAhPSBcIm9iamVjdFwiIHx8IEFycmF5LmlzQXJyYXkob2JqZWN0KSkge1xuICAgICAgICAgICAgdGhyb3cgbmV3IFR5cGVFcnJvcihgZXhwZWN0ZWQgdmFsdWUgb2YgdHlwZSBcIm9iamVjdFwiIGJ1dCBpbnN0ZWFkIGdvdCB2YWx1ZSBcIiR7dmFsdWV9XCIgb2YgdHlwZSBcIiR7cGFyc2VWYWx1ZVR5cGVEZWZhdWx0KG9iamVjdCl9XCJgKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gb2JqZWN0O1xuICAgIH0sXG4gICAgc3RyaW5nKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiB2YWx1ZTtcbiAgICB9LFxufTtcbmNvbnN0IHdyaXRlcnMgPSB7XG4gICAgZGVmYXVsdDogd3JpdGVTdHJpbmcsXG4gICAgYXJyYXk6IHdyaXRlSlNPTixcbiAgICBvYmplY3Q6IHdyaXRlSlNPTixcbn07XG5mdW5jdGlvbiB3cml0ZUpTT04odmFsdWUpIHtcbiAgICByZXR1cm4gSlNPTi5zdHJpbmdpZnkodmFsdWUpO1xufVxuZnVuY3Rpb24gd3JpdGVTdHJpbmcodmFsdWUpIHtcbiAgICByZXR1cm4gYCR7dmFsdWV9YDtcbn1cblxuY2xhc3MgQ29udHJvbGxlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCkge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgIH1cbiAgICBzdGF0aWMgZ2V0IHNob3VsZExvYWQoKSB7XG4gICAgICAgIHJldHVybiB0cnVlO1xuICAgIH1cbiAgICBzdGF0aWMgYWZ0ZXJMb2FkKF9pZGVudGlmaWVyLCBfYXBwbGljYXRpb24pIHtcbiAgICAgICAgcmV0dXJuO1xuICAgIH1cbiAgICBnZXQgYXBwbGljYXRpb24oKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuYXBwbGljYXRpb247XG4gICAgfVxuICAgIGdldCBzY29wZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY29wZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgdGFyZ2V0cygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUudGFyZ2V0cztcbiAgICB9XG4gICAgZ2V0IG91dGxldHMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLm91dGxldHM7XG4gICAgfVxuICAgIGdldCBjbGFzc2VzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5jbGFzc2VzO1xuICAgIH1cbiAgICBnZXQgZGF0YSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZGF0YTtcbiAgICB9XG4gICAgaW5pdGlhbGl6ZSgpIHtcbiAgICB9XG4gICAgY29ubmVjdCgpIHtcbiAgICB9XG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICB9XG4gICAgZGlzcGF0Y2goZXZlbnROYW1lLCB7IHRhcmdldCA9IHRoaXMuZWxlbWVudCwgZGV0YWlsID0ge30sIHByZWZpeCA9IHRoaXMuaWRlbnRpZmllciwgYnViYmxlcyA9IHRydWUsIGNhbmNlbGFibGUgPSB0cnVlLCB9ID0ge30pIHtcbiAgICAgICAgY29uc3QgdHlwZSA9IHByZWZpeCA/IGAke3ByZWZpeH06JHtldmVudE5hbWV9YCA6IGV2ZW50TmFtZTtcbiAgICAgICAgY29uc3QgZXZlbnQgPSBuZXcgQ3VzdG9tRXZlbnQodHlwZSwgeyBkZXRhaWwsIGJ1YmJsZXMsIGNhbmNlbGFibGUgfSk7XG4gICAgICAgIHRhcmdldC5kaXNwYXRjaEV2ZW50KGV2ZW50KTtcbiAgICAgICAgcmV0dXJuIGV2ZW50O1xuICAgIH1cbn1cbkNvbnRyb2xsZXIuYmxlc3NpbmdzID0gW1xuICAgIENsYXNzUHJvcGVydGllc0JsZXNzaW5nLFxuICAgIFRhcmdldFByb3BlcnRpZXNCbGVzc2luZyxcbiAgICBWYWx1ZVByb3BlcnRpZXNCbGVzc2luZyxcbiAgICBPdXRsZXRQcm9wZXJ0aWVzQmxlc3NpbmcsXG5dO1xuQ29udHJvbGxlci50YXJnZXRzID0gW107XG5Db250cm9sbGVyLm91dGxldHMgPSBbXTtcbkNvbnRyb2xsZXIudmFsdWVzID0ge307XG5cbmV4cG9ydCB7IEFwcGxpY2F0aW9uLCBBdHRyaWJ1dGVPYnNlcnZlciwgQ29udGV4dCwgQ29udHJvbGxlciwgRWxlbWVudE9ic2VydmVyLCBJbmRleGVkTXVsdGltYXAsIE11bHRpbWFwLCBTZWxlY3Rvck9ic2VydmVyLCBTdHJpbmdNYXBPYnNlcnZlciwgVG9rZW5MaXN0T2JzZXJ2ZXIsIFZhbHVlTGlzdE9ic2VydmVyLCBhZGQsIGRlZmF1bHRTY2hlbWEsIGRlbCwgZmV0Y2gsIHBydW5lIH07XG4iLCAiaW1wb3J0IHsgQ29udHJvbGxlciB9IGZyb20gXCJAaG90d2lyZWQvc3RpbXVsdXNcIlxuXG4vKipcbiAqIFN0aW11bHVzIENvbnRyb2xsZXIgZm9yIFN0cmlwZSBQYXltZW50IEVsZW1lbnQgb24gT3JkZXIgUGFnZVxuICpcbiAqIEhhbmRsZXMgU3RyaXBlIHBheW1lbnQgZm9ybSBpbml0aWFsaXphdGlvbiBhbmQgc3VibWlzc2lvbiBvbiB0aGUgb3JkZXIgY29uZmlybWF0aW9uIHBhZ2VcbiAqXG4gKiBVc2FnZSBpbiBUd2lnOlxuICogPGRpdiBkYXRhLWNvbnRyb2xsZXI9XCJzdHJpcGUtb3JkZXJcIlxuICogICAgICBkYXRhLXN0cmlwZS1vcmRlci1wdWJsaXNoYWJsZS1rZXktdmFsdWU9XCJwa18uLi5cIlxuICogICAgICBkYXRhLXN0cmlwZS1vcmRlci1jbGllbnQtc2VjcmV0LXZhbHVlPVwicGlfLi4uX3NlY3JldF8uLi5cIj5cbiAqICAgPGRpdiBpZD1cInBheW1lbnQtZWxlbWVudFwiPjwvZGl2PlxuICogICA8ZGl2IGlkPVwicGF5bWVudC1lcnJvcnNcIiBzdHlsZT1cImRpc3BsYXk6bm9uZVwiPlxuICogICAgIDxzcGFuIGRhdGEtc3RyaXBlLW9yZGVyLXRhcmdldD1cImVycm9yTWVzc2FnZVwiPjwvc3Bhbj5cbiAqICAgPC9kaXY+XG4gKiA8L2Rpdj5cbiAqL1xuZXhwb3J0IGRlZmF1bHQgY2xhc3MgZXh0ZW5kcyBDb250cm9sbGVyIHtcbiAgc3RhdGljIHZhbHVlcyA9IHtcbiAgICBwdWJsaXNoYWJsZUtleTogU3RyaW5nLFxuICAgIGNsaWVudFNlY3JldDogU3RyaW5nXG4gIH1cblxuICBzdGF0aWMgdGFyZ2V0cyA9IFtcImVycm9yTWVzc2FnZVwiLCBcImxvYWRpbmdcIl1cblxuICBjb25uZWN0KCkge1xuICAgIGNvbnNvbGUubG9nKCdTdHJpcGUgT3JkZXIgY29udHJvbGxlciBjb25uZWN0ZWQnLCB7XG4gICAgICBoYXNQdWJsaXNoYWJsZUtleTogISF0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUsXG4gICAgICBwdWJsaXNoYWJsZUtleTogdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlID8gdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlLnN1YnN0cmluZygwLCAxMCkgKyAnLi4uJyA6ICdtaXNzaW5nJyxcbiAgICB9KVxuXG4gICAgLy8gR2V0IGRlYnVnIGluZm8gZnJvbSBlbGVtZW50XG4gICAgY29uc3QgZGVidWdJbmZvID0gdGhpcy5lbGVtZW50LmdldEF0dHJpYnV0ZSgnZGF0YS1kZWJ1Zy1pbmZvJylcbiAgICBpZiAoZGVidWdJbmZvKSB7XG4gICAgICBjb25zb2xlLmxvZygnRGVidWcgaW5mbzonLCBkZWJ1Z0luZm8pXG4gICAgfVxuXG4gICAgLy8gVmFsaWRhdGUgcmVxdWlyZWQgY29uZmlndXJhdGlvblxuICAgIGlmICghdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdTdHJpcGUgcHVibGlzaGFibGUga2V5IG5vdCBjb25maWd1cmVkJylcbiAgICAgIHRoaXMuc2hvd0Vycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5DT05GSUdfRVJST1IgfHwgJ1N0cmlwZSBjb25maWd1cmF0aW9uIGVycm9yLiBQbGVhc2UgY29udGFjdCBzdXBwb3J0LicpXG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICAvLyBXYWl0IGZvciBTdHJpcGUuanMgdG8gbG9hZFxuICAgIHRoaXMuaW5pdGlhbGl6ZVN0cmlwZSgpXG4gIH1cblxuICBkaXNjb25uZWN0KCkge1xuICAgIC8vIENsZWFudXAgaWYgbmVlZGVkXG4gICAgaWYgKHRoaXMucGF5bWVudEVsZW1lbnQpIHtcbiAgICAgIHRoaXMucGF5bWVudEVsZW1lbnQudW5tb3VudCgpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEluaXRpYWxpemUgU3RyaXBlIGFuZCBtb3VudCBQYXltZW50IEVsZW1lbnRcbiAgICovXG4gIGFzeW5jIGluaXRpYWxpemVTdHJpcGUoKSB7XG4gICAgLy8gV2FpdCBmb3IgU3RyaXBlLmpzIHRvIGJlIGF2YWlsYWJsZVxuICAgIGlmICh0eXBlb2YgU3RyaXBlID09PSAndW5kZWZpbmVkJykge1xuICAgICAgY29uc29sZS5sb2coJ1dhaXRpbmcgZm9yIFN0cmlwZS5qcyB0byBsb2FkLi4uJylcbiAgICAgIGF3YWl0IHRoaXMud2FpdEZvclN0cmlwZSgpXG4gICAgfVxuXG4gICAgdHJ5IHtcbiAgICAgIC8vIEluaXRpYWxpemUgU3RyaXBlXG4gICAgICB0aGlzLnN0cmlwZSA9IFN0cmlwZSh0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUpXG5cbiAgICAgIC8vIENyZWF0ZSBFbGVtZW50cyB3aXRoIHN0eWxpbmdcbiAgICAgIGNvbnN0IGFwcGVhcmFuY2UgPSB7XG4gICAgICAgIHRoZW1lOiAnc3RyaXBlJyxcbiAgICAgICAgdmFyaWFibGVzOiB7XG4gICAgICAgICAgY29sb3JQcmltYXJ5OiAnIzA1NzBkZScsXG4gICAgICAgICAgY29sb3JCYWNrZ3JvdW5kOiAnI2ZmZmZmZicsXG4gICAgICAgICAgY29sb3JUZXh0OiAnIzMwMzEzZCcsXG4gICAgICAgICAgZm9udEZhbWlseTogJ3N5c3RlbS11aSwgc2Fucy1zZXJpZicsXG4gICAgICAgICAgYm9yZGVyUmFkaXVzOiAnNHB4J1xuICAgICAgICB9XG4gICAgICB9XG5cbiAgICAgIHRoaXMuZWxlbWVudHMgPSB0aGlzLnN0cmlwZS5lbGVtZW50cyh7XG4gICAgICAgIGFwcGVhcmFuY2U6IGFwcGVhcmFuY2VcbiAgICAgIH0pXG5cbiAgICAgIHRoaXMuY2FyZCA9IHRoaXMuZWxlbWVudHMuY3JlYXRlKCdjYXJkJyk7XG4gICAgICB0aGlzLmNhcmQubW91bnQoJyNjYXJkLWVsZW1lbnQnKTtcblxuICAgICAgY29uc29sZS5sb2coJ1N0cmlwZSBQYXltZW50IEVsZW1lbnQgaW5pdGlhbGl6ZWQgc3VjY2Vzc2Z1bGx5JylcblxuICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdGYWlsZWQgdG8gaW5pdGlhbGl6ZSBTdHJpcGU6JywgZXJyb3IpXG4gICAgICB0aGlzLnNob3dFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uSU5JVF9GQUlMRUQgfHwgJ0ZhaWxlZCB0byBpbml0aWFsaXplIHBheW1lbnQgZm9ybS4gUGxlYXNlIHJlZnJlc2ggdGhlIHBhZ2UuJylcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogV2FpdCBmb3IgU3RyaXBlLmpzIGxpYnJhcnkgdG8gbG9hZFxuICAgKiBAcmV0dXJucyB7UHJvbWlzZX1cbiAgICovXG4gIHdhaXRGb3JTdHJpcGUoKSB7XG4gICAgcmV0dXJuIG5ldyBQcm9taXNlKChyZXNvbHZlKSA9PiB7XG4gICAgICBjb25zdCBjaGVja1N0cmlwZSA9ICgpID0+IHtcbiAgICAgICAgaWYgKHR5cGVvZiBTdHJpcGUgIT09ICd1bmRlZmluZWQnKSB7XG4gICAgICAgICAgcmVzb2x2ZSgpXG4gICAgICAgIH0gZWxzZSB7XG4gICAgICAgICAgc2V0VGltZW91dChjaGVja1N0cmlwZSwgMTAwKVxuICAgICAgICB9XG4gICAgICB9XG4gICAgICBjaGVja1N0cmlwZSgpXG4gICAgfSlcbiAgfVxuXG4gIC8qKlxuICAgKiBTaG93IGxvYWRpbmcgaW5kaWNhdG9yXG4gICAqL1xuICBzaG93TG9hZGluZygpIHtcbiAgICBpZiAodGhpcy5oYXNMb2FkaW5nVGFyZ2V0KSB7XG4gICAgICB0aGlzLmxvYWRpbmdUYXJnZXQuc3R5bGUuZGlzcGxheSA9ICdibG9jaydcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogU2hvdyBlcnJvciBtZXNzYWdlXG4gICAqIEBwYXJhbSB7U3RyaW5nfSBtZXNzYWdlXG4gICAqL1xuICBzaG93RXJyb3IobWVzc2FnZSkge1xuICAgIGNvbnN0IGVycm9yRGl2ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ3BheW1lbnQtZXJyb3JzJylcbiAgICBpZiAoZXJyb3JEaXYgJiYgdGhpcy5oYXNFcnJvck1lc3NhZ2VUYXJnZXQpIHtcbiAgICAgIGVycm9yRGl2LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG4gICAgICB0aGlzLmVycm9yTWVzc2FnZVRhcmdldC50ZXh0Q29udGVudCA9IG1lc3NhZ2VcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSGlkZSBlcnJvciBtZXNzYWdlXG4gICAqL1xuICBoaWRlRXJyb3IoKSB7XG4gICAgY29uc3QgZXJyb3JEaXYgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgncGF5bWVudC1lcnJvcnMnKVxuICAgIGlmIChlcnJvckRpdikge1xuICAgICAgZXJyb3JEaXYuc3R5bGUuZGlzcGxheSA9ICdub25lJ1xuICAgICAgaWYgKHRoaXMuaGFzRXJyb3JNZXNzYWdlVGFyZ2V0KSB7XG4gICAgICAgIHRoaXMuZXJyb3JNZXNzYWdlVGFyZ2V0LnRleHRDb250ZW50ID0gJydcbiAgICAgIH1cbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSGlkZSBsb2FkaW5nIGluZGljYXRvclxuICAgKi9cbiAgaGlkZUxvYWRpbmcoKSB7XG4gICAgaWYgKHRoaXMuaGFzTG9hZGluZ1RhcmdldCkge1xuICAgICAgdGhpcy5sb2FkaW5nVGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnbm9uZSdcbiAgICB9XG4gIH1cblxufVxuIiwgImltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tIFwiQGhvdHdpcmVkL3N0aW11bHVzXCJcblxuLyoqXG4gKiBTdGltdWx1cyBDb250cm9sbGVyIGZvciBPcmRlciBTdWJtaXQgQnV0dG9uXG4gKlxuICogSGFuZGxlcyBvcmRlciBzdWJtaXNzaW9uIG9uIHRoZSBjaGVja291dCBvcmRlciBwYWdlLlxuICogU3VwcG9ydHMgdHdvIHBheW1lbnQgZmxvd3M6XG4gKiAxLiBTdHJpcGUgQ2hlY2tvdXQgKGhvc3RlZCBwYWdlKSAtIGZvciB3YWxsZXQgcGF5bWVudHNcbiAqIDIuIFBheW1lbnQgSW50ZW50IChjYXJkIGVsZW1lbnQpIC0gZm9yIGNhcmQgcGF5bWVudHNcbiAqXG4gKiBVc2FnZSBpbiBUd2lnOlxuICogPGJ1dHRvbiBkYXRhLWNvbnRyb2xsZXI9XCJvcmRlci1zdWJtaXRcIlxuICogICAgICAgICBkYXRhLWFjdGlvbj1cImNsaWNrLT5vcmRlci1zdWJtaXQjaGFuZGxlU3VibWl0XCJcbiAqICAgICAgICAgZGF0YS1vcmRlci1zdWJtaXQtdXJsLXZhbHVlPVwiLi4uXCJcbiAqICAgICAgICAgZGF0YS1vcmRlci1zdWJtaXQtcGF5bWVudC10eXBlLXZhbHVlPVwid2FsbGV0fGNhcmRcIlxuICogICAgICAgICB0eXBlPVwiYnV0dG9uXCI+XG4gKiAgIFN1Ym1pdCBPcmRlclxuICogPC9idXR0b24+XG4gKi9cbmV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgQ29udHJvbGxlciB7XG4gIHN0YXRpYyB0YXJnZXRzID0gW1wic3RhdHVzXCJdXG4gIHN0YXRpYyB2YWx1ZXMgPSB7XG4gICAgdXJsOiBTdHJpbmcsXG4gICAgcGF5bWVudFR5cGU6IFN0cmluZyxcbiAgICBwdWJsaXNoYWJsZUtleTogU3RyaW5nXG4gIH1cblxuICAvKipcbiAgICogQ2FsbGVkIHdoZW4gY29udHJvbGxlciBpcyBjb25uZWN0ZWQgdG8gRE9NXG4gICAqL1xuICBjb25uZWN0KCkge1xuICAgIGNvbnNvbGUubG9nKCdPcmRlciBTdWJtaXQgY29udHJvbGxlciBjb25uZWN0ZWQnKVxuICAgIGNvbnNvbGUubG9nKCdCdXR0b24gZWxlbWVudDonLCB0aGlzLmVsZW1lbnQpXG4gIH1cblxuICAvKipcbiAgICogQ2FsbGVkIHdoZW4gY29udHJvbGxlciBpcyBkaXNjb25uZWN0ZWQgZnJvbSBET01cbiAgICovXG4gIGRpc2Nvbm5lY3QoKSB7XG4gICAgY29uc29sZS5sb2coJ09yZGVyIFN1Ym1pdCBjb250cm9sbGVyIGRpc2Nvbm5lY3RlZCcpXG4gIH1cblxuICAvKipcbiAgICogR2V0IHRoZSBzdHJpcGUtb3JkZXIgY29udHJvbGxlciBpbnN0YW5jZVxuICAgKiBAcmV0dXJucyB7Q29udHJvbGxlcnxudWxsfVxuICAgKi9cbiAgZ2V0U3RyaXBlT3JkZXJDb250cm9sbGVyKCkge1xuICAgIGNvbnN0IGNhcmRFbGVtZW50ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ2NhcmQtZWxlbWVudCcpXG4gICAgaWYgKCFjYXJkRWxlbWVudCkge1xuICAgICAgY29uc29sZS5lcnJvcignQ2FyZCBlbGVtZW50IG5vdCBmb3VuZCcpXG4gICAgICByZXR1cm4gbnVsbFxuICAgIH1cblxuICAgIGNvbnN0IGNvbnRyb2xsZXIgPSB0aGlzLmFwcGxpY2F0aW9uLmdldENvbnRyb2xsZXJGb3JFbGVtZW50QW5kSWRlbnRpZmllcihcbiAgICAgIGNhcmRFbGVtZW50LFxuICAgICAgJ3N0cmlwZS1vcmRlcidcbiAgICApXG5cbiAgICBpZiAoIWNvbnRyb2xsZXIpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ1N0cmlwZSBvcmRlciBjb250cm9sbGVyIG5vdCBmb3VuZCBvbiBjYXJkIGVsZW1lbnQnKVxuICAgICAgcmV0dXJuIG51bGxcbiAgICB9XG5cbiAgICBjb25zb2xlLmxvZygnRm91bmQgc3RyaXBlLW9yZGVyIGNvbnRyb2xsZXI6JywgY29udHJvbGxlcilcbiAgICByZXR1cm4gY29udHJvbGxlclxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBvcmRlciBzdWJtaXQgYnV0dG9uIGNsaWNrXG4gICAqIFJvdXRlcyB0byBhcHByb3ByaWF0ZSBwYXltZW50IGZsb3cgYmFzZWQgb24gcGF5bWVudCB0eXBlXG4gICAqIEBwYXJhbSB7RXZlbnR9IGV2ZW50IC0gVGhlIGNsaWNrIGV2ZW50XG4gICAqL1xuICBhc3luYyBoYW5kbGVTdWJtaXQoZXZlbnQpIHtcbiAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpXG5cbiAgICBjb25zb2xlLmxvZygnT3JkZXIgc3VibWl0IGJ1dHRvbiBjbGlja2VkJywge1xuICAgICAgYnV0dG9uSWQ6IHRoaXMuZWxlbWVudC5pZCxcbiAgICAgIHBheW1lbnRUeXBlOiB0aGlzLnBheW1lbnRUeXBlVmFsdWUsXG4gICAgICB0aW1lc3RhbXA6IG5ldyBEYXRlKCkudG9JU09TdHJpbmcoKVxuICAgIH0pXG5cbiAgICB0aGlzLnNob3dMb2FkaW5nKClcblxuICAgIHRyeSB7XG4gICAgICAvLyBSb3V0ZSB0byBhcHByb3ByaWF0ZSBwYXltZW50IGZsb3dcbiAgICAgIGlmICh0aGlzLnBheW1lbnRUeXBlVmFsdWUgPT09ICd3YWxsZXQnKSB7XG4gICAgICAgIGF3YWl0IHRoaXMuaGFuZGxlU3RyaXBlQ2hlY2tvdXQoKVxuICAgICAgfSBlbHNlIHtcbiAgICAgICAgYXdhaXQgdGhpcy5oYW5kbGVQYXltZW50SW50ZW50KClcbiAgICAgIH1cbiAgICB9IGNhdGNoIChlcnJvcikge1xuICAgICAgY29uc29sZS5lcnJvcignT3JkZXIgc3VibWlzc2lvbiBmYWlsZWQnLCBlcnJvcilcbiAgICAgIHRoaXMuc2hvd0Vycm9yKGVycm9yLm1lc3NhZ2UgfHwgd2luZG93Lm9TdHJpcGU/LmkxOG4/LlBBWU1FTlRfRkFJTEVEIHx8ICdQYXltZW50IHByb2Nlc3NpbmcgZmFpbGVkJylcbiAgICB9IGZpbmFsbHkge1xuICAgICAgdGhpcy5oaWRlTG9hZGluZygpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBTdHJpcGUgQ2hlY2tvdXQgZmxvdyAoaG9zdGVkIHBheW1lbnQgcGFnZSlcbiAgICogVXNlZCBmb3Igd2FsbGV0IHBheW1lbnRzIChBcHBsZSBQYXksIEdvb2dsZSBQYXkpXG4gICAqL1xuICBhc3luYyBoYW5kbGVTdHJpcGVDaGVja291dCgpIHtcbiAgICBpZiAoIXdpbmRvdy5TdHJpcGUpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uSlNfTk9UX0xPQURFRCB8fCAnU3RyaXBlLmpzIG5vdCBsb2FkZWQnKVxuICAgIH1cblxuICAgIC8vIEdldCBTdHJpcGUgcHVibGlzaGFibGUga2V5IGZyb20gU3RpbXVsdXMgdmFsdWVcbiAgICBpZiAoIXRoaXMuaGFzUHVibGlzaGFibGVLZXlWYWx1ZSB8fCAhdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LktFWV9OT1RfQ09ORklHVVJFRCB8fCAnU3RyaXBlIHB1Ymxpc2hhYmxlIGtleSBub3QgY29uZmlndXJlZCcpXG4gICAgfVxuXG4gICAgY29uc3Qgc3RyaXBlID0gU3RyaXBlKHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSlcblxuICAgIHRoaXMuc2V0U3RhdHVzKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5DUkVBVElOR19TRVNTSU9OIHx8ICdDcmVhdGluZyBjaGVja291dCBzZXNzaW9uLi4uJylcblxuICAgIC8vIENyZWF0ZSBDaGVja291dCBTZXNzaW9uIChpbmNsdWRlIHN0b2tlbiBmb3IgQ1NSRiBwcm90ZWN0aW9uKVxuICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2godGhpcy5hcHBlbmRBZ2JTdGF0ZSh0aGlzLmJ1aWxkVXJsV2l0aENzcmZUb2tlbih0aGlzLnVybFZhbHVlKSksIHtcbiAgICAgIG1ldGhvZDogJ1BPU1QnLFxuICAgICAgaGVhZGVyczoge1xuICAgICAgICAnQ29udGVudC1UeXBlJzogJ2FwcGxpY2F0aW9uL2pzb24nXG4gICAgICB9LFxuICAgICAgYm9keTogSlNPTi5zdHJpbmdpZnkoe1xuICAgICAgICBjYXB0dXJlOiAnYXV0b21hdGljJyAvLyBDYW4gYmUgbWFkZSBjb25maWd1cmFibGVcbiAgICAgIH0pLFxuICAgICAgY3JlZGVudGlhbHM6ICdzYW1lLW9yaWdpbidcbiAgICB9KVxuXG4gICAgaWYgKCFyZXNwb25zZS5vaykge1xuICAgICAgY29uc3QgZXJyb3JEYXRhID0gYXdhaXQgcmVzcG9uc2UuanNvbigpLmNhdGNoKCgpID0+ICh7fSkpXG4gICAgICB0aHJvdyBuZXcgRXJyb3IoZXJyb3JEYXRhLmVycm9yIHx8IHdpbmRvdy5vU3RyaXBlPy5pMThuPy5TRVNTSU9OX0ZBSUxFRCB8fCAnRmFpbGVkIHRvIGNyZWF0ZSBjaGVja291dCBzZXNzaW9uJylcbiAgICB9XG5cbiAgICBjb25zdCBkYXRhID0gYXdhaXQgcmVzcG9uc2UuanNvbigpXG5cbiAgICBpZiAoIWRhdGEuaWQpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uU0VTU0lPTl9JTlZBTElEIHx8ICdJbnZhbGlkIGNoZWNrb3V0IHNlc3Npb24gcmVzcG9uc2UnKVxuICAgIH1cblxuICAgIGNvbnNvbGUubG9nKCdDaGVja291dCBTZXNzaW9uIGNyZWF0ZWQ6JywgZGF0YS5pZCwgJ1VSTDonLCBkYXRhLnVybClcbiAgICBjb25zb2xlLmxvZygnRGVidWcgaW5mbzonLCBkYXRhLl9kZWJ1ZylcblxuICAgIC8vIFJlZGlyZWN0IHRvIFN0cmlwZSBDaGVja291dCB1c2luZyBkaXJlY3QgVVJMIChtb3JlIHJlbGlhYmxlKVxuICAgIGlmIChkYXRhLnVybCkge1xuICAgICAgd2luZG93LmxvY2F0aW9uLmhyZWYgPSBkYXRhLnVybFxuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgLy8gRmFsbGJhY2sgdG8gcmVkaXJlY3RUb0NoZWNrb3V0IGlmIFVSTCBub3QgYXZhaWxhYmxlXG4gICAgY29uc3QgeyBlcnJvciB9ID0gYXdhaXQgc3RyaXBlLnJlZGlyZWN0VG9DaGVja291dCh7XG4gICAgICBzZXNzaW9uSWQ6IGRhdGEuaWRcbiAgICB9KVxuXG4gICAgaWYgKGVycm9yKSB7XG4gICAgICB0aHJvdyBlcnJvclxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBIYW5kbGUgUGF5bWVudCBJbnRlbnQgZmxvdyAoY2FyZCBlbGVtZW50KVxuICAgKiBVc2VkIGZvciBjYXJkIHBheW1lbnRzXG4gICAqL1xuICBhc3luYyBoYW5kbGVQYXltZW50SW50ZW50KCkge1xuICAgIC8vIEdldCBzdHJpcGUtb3JkZXIgY29udHJvbGxlciBpbnN0YW5jZVxuICAgIGNvbnN0IHN0cmlwZU9yZGVyQ29udHJvbGxlciA9IHRoaXMuZ2V0U3RyaXBlT3JkZXJDb250cm9sbGVyKClcblxuICAgIGlmICghc3RyaXBlT3JkZXJDb250cm9sbGVyKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LkNPTlRST0xMRVJfTk9UX0ZPVU5EIHx8ICdTdHJpcGUgcGF5bWVudCBjb250cm9sbGVyIG5vdCBmb3VuZC4gUGxlYXNlIHJlZnJlc2ggdGhlIHBhZ2UuJylcbiAgICB9XG5cbiAgICAvLyBWZXJpZnkgY2FyZCBlbGVtZW50IGFuZCBzdHJpcGUgYXJlIGF2YWlsYWJsZVxuICAgIGlmICghc3RyaXBlT3JkZXJDb250cm9sbGVyLmNhcmQgfHwgIXN0cmlwZU9yZGVyQ29udHJvbGxlci5zdHJpcGUpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ1BheW1lbnQgZm9ybSBub3QgcmVhZHk6Jywge1xuICAgICAgICBoYXNDYXJkOiAhIXN0cmlwZU9yZGVyQ29udHJvbGxlci5jYXJkLFxuICAgICAgICBoYXNTdHJpcGU6ICEhc3RyaXBlT3JkZXJDb250cm9sbGVyLnN0cmlwZVxuICAgICAgfSlcbiAgICAgIHRocm93IG5ldyBFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uRk9STV9OT1RfUkVBRFkgfHwgJ1BheW1lbnQgZm9ybSBub3QgaW5pdGlhbGl6ZWQuIFBsZWFzZSByZWZyZXNoIHRoZSBwYWdlLicpXG4gICAgfVxuXG4gICAgY29uc29sZS5sb2coJ1N0cmlwZSBjb250cm9sbGVyIHJlYWR5OicsIHtcbiAgICAgIGhhc0NhcmQ6ICEhc3RyaXBlT3JkZXJDb250cm9sbGVyLmNhcmQsXG4gICAgICBoYXNTdHJpcGU6ICEhc3RyaXBlT3JkZXJDb250cm9sbGVyLnN0cmlwZVxuICAgIH0pXG5cbiAgICBjb25zdCBwYXltZW50SW50ZW50UmVzcG9uc2UgPSBhd2FpdCB0aGlzLmhhbmRsZVBheW1lbnQoKVxuICAgIGNvbnN0IGNsaWVudFNlY3JldCA9IHBheW1lbnRJbnRlbnRSZXNwb25zZS5jbGllbnRTZWNyZXRcblxuICAgIGNvbnN0IGNvbmZpcm1QYXltZW50UmVzcG9uc2UgPSBhd2FpdCBzdHJpcGVPcmRlckNvbnRyb2xsZXIuc3RyaXBlLmNvbmZpcm1DYXJkUGF5bWVudChjbGllbnRTZWNyZXQsIHtcbiAgICAgIHBheW1lbnRfbWV0aG9kOiB7XG4gICAgICAgIGNhcmQ6IHN0cmlwZU9yZGVyQ29udHJvbGxlci5jYXJkXG4gICAgICB9XG4gICAgfSk7XG5cbiAgICBpZiAoY29uZmlybVBheW1lbnRSZXNwb25zZS5lcnJvcikge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKGNvbmZpcm1QYXltZW50UmVzcG9uc2UuZXJyb3IubWVzc2FnZSlcbiAgICB9IGVsc2UgaWYgKGNvbmZpcm1QYXltZW50UmVzcG9uc2UucGF5bWVudEludGVudCAmJiBjb25maXJtUGF5bWVudFJlc3BvbnNlLnBheW1lbnRJbnRlbnQuc3RhdHVzID09PSAnc3VjY2VlZGVkJykge1xuICAgICAgY29uc29sZS5sb2coJ1BheW1lbnQgc3VjY2VlZGVkJywgY29uZmlybVBheW1lbnRSZXNwb25zZS5wYXltZW50SW50ZW50KVxuICAgICAgLy8gVE9ETzogU3VibWl0IGZpbmFsIG9yZGVyIHRvIGJhY2tlbmRcbiAgICB9IGVsc2Uge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5QQVlNRU5UX05PVF9DT01QTEVURUQgfHwgJ1BheW1lbnQgbm90IGNvbXBsZXRlZCcpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEZldGNoIHBheW1lbnQgaW50ZW50IGNyZWF0aW9uIFVSTCBhbmQgcmV0dXJuIHJlc3BvbnNlXG4gICAqIEByZXR1cm5zIHtQcm9taXNlPE9iamVjdD59IFBheW1lbnQgaW50ZW50IHJlc3BvbnNlIHdpdGggY2xpZW50U2VjcmV0LCBhbW91bnQsIGN1cnJlbmN5XG4gICAqIEB0aHJvd3Mge0Vycm9yfSBJZiBmZXRjaCBmYWlscyBvciByZXNwb25zZSBpcyBub3Qgb2tcbiAgICovXG4gIGFzeW5jIGhhbmRsZVBheW1lbnQoKSB7XG4gICAgaWYgKCF0aGlzLmhhc1VybFZhbHVlKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LlVSTF9OT1RfQ09ORklHVVJFRCB8fCAnUGF5bWVudCBVUkwgaXMgbm90IGNvbmZpZ3VyZWQnKVxuICAgIH1cblxuICAgIGNvbnNvbGUubG9nKCdDcmVhdGluZyBwYXltZW50IGludGVudCB2aWEgVVJMOicsIHRoaXMudXJsVmFsdWUpXG5cbiAgICBjb25zdCByZXNwb25zZSA9IGF3YWl0IGZldGNoKHRoaXMuYXBwZW5kQWdiU3RhdGUodGhpcy5idWlsZFVybFdpdGhDc3JmVG9rZW4odGhpcy51cmxWYWx1ZSkpLCB7XG4gICAgICBtZXRob2Q6ICdQT1NUJyxcbiAgICAgIGhlYWRlcnM6IHtcbiAgICAgICAgJ0NvbnRlbnQtVHlwZSc6ICdhcHBsaWNhdGlvbi9qc29uJ1xuICAgICAgfSxcbiAgICAgIGNyZWRlbnRpYWxzOiAnc2FtZS1vcmlnaW4nXG4gICAgfSlcblxuICAgIGlmICghcmVzcG9uc2Uub2spIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcihgSFRUUCBlcnJvciEgc3RhdHVzOiAke3Jlc3BvbnNlLnN0YXR1c31gKVxuICAgIH1cblxuICAgIGNvbnN0IHJlc3BvbnNlRGF0YSA9IGF3YWl0IHJlc3BvbnNlLmpzb24oKVxuXG4gICAgaWYgKHJlc3BvbnNlRGF0YS5lcnJvcikge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHJlc3BvbnNlRGF0YS5lcnJvcilcbiAgICB9XG5cbiAgICBpZiAoIXJlc3BvbnNlRGF0YS5zdWNjZXNzIHx8ICFyZXNwb25zZURhdGEuY2xpZW50U2VjcmV0KSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LklOVEVOVF9JTlZBTElEIHx8ICdJbnZhbGlkIHBheW1lbnQgaW50ZW50IHJlc3BvbnNlJylcbiAgICB9XG5cbiAgICByZXR1cm4gcmVzcG9uc2VEYXRhXG4gIH1cblxuICAvKipcbiAgICogQXBwZW5kIHN0b2tlbiAoQ1NSRiB0b2tlbikgdG8gVVJMIGZvciBzZXNzaW9uIGNoYWxsZW5nZSB2YWxpZGF0aW9uLlxuICAgKiBPWElEIGluY2x1ZGVzIHN0b2tlbiBpbiBmb3JtcyB2aWEgb1ZpZXdDb25mLmdldFNlc3Npb25DaGFsbGVuZ2VUb2tlbigpLlxuICAgKiBAcGFyYW0ge3N0cmluZ30gdXJsIC0gVGhlIGJhc2UgVVJMXG4gICAqIEByZXR1cm5zIHtzdHJpbmd9IFVSTCB3aXRoIHN0b2tlbiBwYXJhbWV0ZXIgYXBwZW5kZWRcbiAgICovXG4gIGJ1aWxkVXJsV2l0aENzcmZUb2tlbih1cmwpIHtcbiAgICBjb25zdCBzdG9rZW4gPSBkb2N1bWVudC5xdWVyeVNlbGVjdG9yKCdpbnB1dFtuYW1lPVwic3Rva2VuXCJdJyk/LnZhbHVlIHx8ICcnXG4gICAgaWYgKCFzdG9rZW4pIHtcbiAgICAgIGNvbnNvbGUud2FybignQ1NSRiB0b2tlbiAoc3Rva2VuKSBub3QgZm91bmQgaW4gZm9ybScpXG4gICAgICByZXR1cm4gdXJsXG4gICAgfVxuICAgIGNvbnN0IHNlcGFyYXRvciA9IHVybC5pbmNsdWRlcygnPycpID8gJyYnIDogJz8nXG4gICAgcmV0dXJuIHVybCArIHNlcGFyYXRvciArICdzdG9rZW49JyArIGVuY29kZVVSSUNvbXBvbmVudChzdG9rZW4pXG4gIH1cblxuICAvKipcbiAgICogQXBwZW5kIHRoZSBBR0IgYWNjZXB0YW5jZSBmbGFnIChvcmRfYWdiPTEpIHdoZW4gdGhlIGN1c3RvbWVyIGhhcyB0aWNrZWRcbiAgICogdGhlIGFwZXggVGVybXMtYW5kLUNvbmRpdGlvbnMgY2hlY2tib3ggKCNjaGVja0FnYlRvcCkuIFRoZSBTdHJpcGUgb3JkZXJcbiAgICogZmV0Y2ggcG9zdHMgYSBKU09OIGJvZHksIHdoaWNoIE9YSUQncyBSZWdpc3RyeTo6Z2V0UmVxdWVzdCgpIGRvZXMgbm90XG4gICAqIHBhcnNlIFx1MjAxNCBwbGFjaW5nIG9yZF9hZ2IgaW4gdGhlIHF1ZXJ5IHN0cmluZyBpcyB0aGUgc2ltcGxlc3Qgd2F5IHRvIG1ha2VcbiAgICogU3RyaXBlT3JkZXJDb250cm9sbGVyOjpjcmVhdGVDaGVja291dFNlc3Npb24oKSBzZWUgaXQuXG4gICAqXG4gICAqIEBwYXJhbSB7c3RyaW5nfSB1cmxcbiAgICogQHJldHVybnMge3N0cmluZ31cbiAgICovXG4gIGFwcGVuZEFnYlN0YXRlKHVybCkge1xuICAgIGNvbnN0IGNoZWNrYm94ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ2NoZWNrQWdiVG9wJylcbiAgICBpZiAoIWNoZWNrYm94IHx8ICFjaGVja2JveC5jaGVja2VkKSB7XG4gICAgICByZXR1cm4gdXJsXG4gICAgfVxuICAgIGNvbnN0IHNlcGFyYXRvciA9IHVybC5pbmNsdWRlcygnPycpID8gJyYnIDogJz8nXG4gICAgcmV0dXJuIHVybCArIHNlcGFyYXRvciArICdvcmRfYWdiPTEnXG4gIH1cblxuICAvKipcbiAgICogU2hvdyBsb2FkaW5nIHN0YXRlIG9uIGJ1dHRvblxuICAgKi9cbiAgc2hvd0xvYWRpbmcoKSB7XG4gICAgdGhpcy5lbGVtZW50LmRpc2FibGVkID0gdHJ1ZVxuICAgIHRoaXMub3JpZ2luYWxUZXh0ID0gdGhpcy5lbGVtZW50LnRleHRDb250ZW50XG4gICAgdGhpcy5lbGVtZW50LnRleHRDb250ZW50ID0gd2luZG93Lm9TdHJpcGU/LmkxOG4/LlBST0NFU1NJTkcgfHwgJ1Byb2Nlc3NpbmcuLi4nXG4gIH1cblxuICAvKipcbiAgICogSGlkZSBsb2FkaW5nIHN0YXRlIG9uIGJ1dHRvblxuICAgKi9cbiAgaGlkZUxvYWRpbmcoKSB7XG4gICAgdGhpcy5lbGVtZW50LmRpc2FibGVkID0gZmFsc2VcbiAgICBpZiAodGhpcy5vcmlnaW5hbFRleHQpIHtcbiAgICAgIHRoaXMuZWxlbWVudC50ZXh0Q29udGVudCA9IHRoaXMub3JpZ2luYWxUZXh0XG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFNldCBzdGF0dXMgbWVzc2FnZVxuICAgKiBAcGFyYW0ge3N0cmluZ30gbWVzc2FnZSAtIFN0YXR1cyBtZXNzYWdlIHRvIGRpc3BsYXlcbiAgICovXG4gIHNldFN0YXR1cyhtZXNzYWdlKSB7XG4gICAgaWYgKHRoaXMuaGFzU3RhdHVzVGFyZ2V0KSB7XG4gICAgICB0aGlzLnN0YXR1c1RhcmdldC50ZXh0Q29udGVudCA9IG1lc3NhZ2VcbiAgICAgIHRoaXMuc3RhdHVzVGFyZ2V0LmNsYXNzTmFtZSA9ICdtdC0yIHRleHQtY2VudGVyIHRleHQtbXV0ZWQnXG4gICAgfVxuICAgIGNvbnNvbGUubG9nKCdTdGF0dXM6JywgbWVzc2FnZSlcbiAgfVxuXG4gIC8qKlxuICAgKiBTaG93IGVycm9yIG1lc3NhZ2VcbiAgICogQHBhcmFtIHtzdHJpbmd9IG1lc3NhZ2UgLSBFcnJvciBtZXNzYWdlIHRvIGRpc3BsYXlcbiAgICovXG4gIHNob3dFcnJvcihtZXNzYWdlKSB7XG4gICAgaWYgKHRoaXMuaGFzU3RhdHVzVGFyZ2V0KSB7XG4gICAgICB0aGlzLnN0YXR1c1RhcmdldC50ZXh0Q29udGVudCA9IG1lc3NhZ2VcbiAgICAgIHRoaXMuc3RhdHVzVGFyZ2V0LmNsYXNzTmFtZSA9ICdtdC0yIHRleHQtY2VudGVyIHRleHQtZGFuZ2VyJ1xuICAgIH0gZWxzZSB7XG4gICAgICBhbGVydCgnRXJyb3I6ICcgKyBtZXNzYWdlKVxuICAgIH1cbiAgfVxufVxuIiwgImltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tICdAaG90d2lyZWQvc3RpbXVsdXMnXG5cbi8qKlxuICogU3RpbXVsdXMgY29udHJvbGxlciBmb3IgQUdCIChUZXJtcyBhbmQgQ29uZGl0aW9ucykgY2hlY2tib3ggdmFsaWRhdGlvbi5cbiAqXG4gKiBXaGVuIGJsQ29uZmlybUFHQiBpcyBlbmFibGVkLCBkaXNhYmxlcyBzdWJtaXQgYnV0dG9ucyB1bnRpbCB0aGUgY3VzdG9tZXJcbiAqIHRpY2tzIHRoZSBBR0IgY2hlY2tib3ggYW5kIHJlLWVuYWJsZXMgdGhlbSBvbiBjaGFuZ2UuXG4gKlxuICogVGhlIEFHQiBjaGVja2JveCAoI2NoZWNrQWdiVG9wKSBsaXZlcyBpbiB0aGUgYXBleCB0aGVtZSBwYXJ0aWFsIGFuZFxuICogY2Fubm90IGNhcnJ5IGEgU3RpbXVsdXMgdGFyZ2V0IGF0dHJpYnV0ZSAoZWRpdCBib3VuZGFyeSkuIFRoaXMgY29udHJvbGxlclxuICogcmVzb2x2ZXMgaXQgYnkgaXRzIHN0YWJsZSBhcGV4IERPTSBJRCBpbiBjb25uZWN0KCkgaW5zdGVhZC5cbiAqXG4gKiBVc2FnZSBpbiB0ZW1wbGF0ZTpcbiAqIDxkaXYgZGF0YS1jb250cm9sbGVyPVwiYWdiLXZhbGlkYXRpb25cIiBkYXRhLWFnYi12YWxpZGF0aW9uLWVuYWJsZWQtdmFsdWU9XCJ0cnVlXCI+XG4gKiAgIDxidXR0b24gZGF0YS1hZ2ItdmFsaWRhdGlvbi10YXJnZXQ9XCJzdWJtaXRCdXR0b25cIj5PcmRlcjwvYnV0dG9uPlxuICogPC9kaXY+XG4gKi9cbmV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgQ29udHJvbGxlciB7XG4gIHN0YXRpYyB0YXJnZXRzID0gWydzdWJtaXRCdXR0b24nXVxuICBzdGF0aWMgdmFsdWVzID0ge1xuICAgIGVuYWJsZWQ6IEJvb2xlYW5cbiAgfVxuXG4gIC8qKlxuICAgKiBJbml0aWFsaXplIHRoZSBjb250cm9sbGVyIHdoZW4gY29ubmVjdGVkIHRvIHRoZSBET00uXG4gICAqXG4gICAqIFNwcmludCAxMDE6IFRoZSBBR0IgY2hlY2tib3ggKCNjaGVja0FnYlRvcCkgbGl2ZXMgaW4gdGhlIGFwZXggdGhlbWVcbiAgICogcGFydGlhbCB3aGljaCB0aGUgU3RyaXBlIG1vZHVsZSBtYXkgbm90IG1vZGlmeSAoZWRpdCBib3VuZGFyeSBcdTAwQTcwIG9mXG4gICAqIHNwcmludCBwbGFuKS4gV2UgcmVzb2x2ZSBpdCBieSBpdHMgc3RhYmxlIGFwZXggRE9NIElEIFx1MjAxNCB0aGUgc2FtZSBJRFxuICAgKiBPWElEJ3Mgb3duIGFnYi5qcyBjb25zdW1lcyBcdTIwMTQgYW5kIGF0dGFjaCBhIGNoYW5nZSBsaXN0ZW5lci5cbiAgICpcbiAgICogSWYgdGhlIGNoZWNrYm94IGlzIGFic2VudCBmcm9tIHRoZSBET00gKGJsQ29uZmlybUFHQiBpcyBvZmYgYW5kIGFwZXhcbiAgICogZGlkIG5vdCByZW5kZXIgaXQpLCB0aGUgbnVsbCBndWFyZCBsZWF2ZXMgYWxsIGJ1dHRvbnMgZW5hYmxlZCwgd2hpY2hcbiAgICogaXMgdGhlIGNvcnJlY3Qgb3V0Y29tZSBmb3IgdGhhdCBjb25maWd1cmF0aW9uIHBhdGguXG4gICAqL1xuICBjb25uZWN0KCkge1xuICAgIC8vIFJlc29sdmUgdGhlIGFwZXggQUdCIGNoZWNrYm94IGJ5IGl0cyBzdGFibGUgSURcbiAgICB0aGlzLl9jb3JlQ2hlY2tib3ggPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgnY2hlY2tBZ2JUb3AnKVxuICAgIGlmICh0aGlzLl9jb3JlQ2hlY2tib3gpIHtcbiAgICAgIHRoaXMuX2NvcmVDaGVja2JveC5hZGRFdmVudExpc3RlbmVyKCdjaGFuZ2UnLCAoKSA9PiB0aGlzLmNoZWNrYm94Q2hhbmdlZCgpKVxuICAgIH1cblxuICAgIGlmICh0aGlzLmVuYWJsZWRWYWx1ZSkge1xuICAgICAgdGhpcy51cGRhdGVCdXR0b25TdGF0ZXMoKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBIYW5kbGUgY2hlY2tib3ggc3RhdGUgY2hhbmdlc1xuICAgKi9cbiAgY2hlY2tib3hDaGFuZ2VkKCkge1xuICAgIGlmICh0aGlzLmVuYWJsZWRWYWx1ZSkge1xuICAgICAgdGhpcy51cGRhdGVCdXR0b25TdGF0ZXMoKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBVcGRhdGUgdGhlIGRpc2FibGVkIHN0YXRlIG9mIGFsbCBzdWJtaXQgYnV0dG9ucyBiYXNlZCBvbiBjaGVja2JveCBzdGF0ZS5cbiAgICpcbiAgICogUmVhZHMgZnJvbSB0aGlzLl9jb3JlQ2hlY2tib3ggKHRoZSBhcGV4ICNjaGVja0FnYlRvcCBlbGVtZW50IHJlc29sdmVkXG4gICAqIGluIGNvbm5lY3QoKSkuIElmIHRoZSBjaGVja2JveCBpcyBub3QgcHJlc2VudCwgbGVhdmVzIGJ1dHRvbnMgZW5hYmxlZC5cbiAgICovXG4gIHVwZGF0ZUJ1dHRvblN0YXRlcygpIHtcbiAgICBpZiAoIXRoaXMuaGFzU3VibWl0QnV0dG9uVGFyZ2V0KSB7XG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICAvLyBJZiB0aGUgQUdCIGNoZWNrYm94IGlzIG5vdCByZW5kZXJlZCAoYmxDb25maXJtQUdCIG9mZiksIGxlYXZlIGJ1dHRvbnMgZW5hYmxlZFxuICAgIGlmICghdGhpcy5fY29yZUNoZWNrYm94KSB7XG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICBjb25zdCBpc0NoZWNrZWQgPSB0aGlzLl9jb3JlQ2hlY2tib3guY2hlY2tlZFxuXG4gICAgLy8gVXBkYXRlIGFsbCBzdWJtaXQgYnV0dG9uc1xuICAgIHRoaXMuc3VibWl0QnV0dG9uVGFyZ2V0cy5mb3JFYWNoKGJ1dHRvbiA9PiB7XG4gICAgICBidXR0b24uZGlzYWJsZWQgPSAhaXNDaGVja2VkXG5cbiAgICAgIC8vIEFkZCB2aXN1YWwgZmVlZGJhY2tcbiAgICAgIGlmIChpc0NoZWNrZWQpIHtcbiAgICAgICAgYnV0dG9uLmNsYXNzTGlzdC5yZW1vdmUoJ2Rpc2FibGVkJylcbiAgICAgICAgYnV0dG9uLnJlbW92ZUF0dHJpYnV0ZSgndGl0bGUnKVxuICAgICAgfSBlbHNlIHtcbiAgICAgICAgYnV0dG9uLmNsYXNzTGlzdC5hZGQoJ2Rpc2FibGVkJylcbiAgICAgICAgYnV0dG9uLnNldEF0dHJpYnV0ZSgndGl0bGUnLCB3aW5kb3cub1N0cmlwZT8uaTE4bj8uQUdCX1JFUVVJUkVEIHx8ICdQbGVhc2UgYWNjZXB0IHRoZSB0ZXJtcyBhbmQgY29uZGl0aW9ucycpXG4gICAgICB9XG4gICAgfSlcbiAgfVxuXG4gIC8qKlxuICAgKiBIYW5kbGUgZm9ybSBzdWJtaXNzaW9uIGF0dGVtcHRzXG4gICAqIEBwYXJhbSB7RXZlbnR9IGV2ZW50IC0gVGhlIHN1Ym1pdCBldmVudFxuICAgKi9cbiAgaGFuZGxlU3VibWl0KGV2ZW50KSB7XG4gICAgaWYgKCF0aGlzLmVuYWJsZWRWYWx1ZSkge1xuICAgICAgcmV0dXJuIHRydWVcbiAgICB9XG5cbiAgICBpZiAoIXRoaXMuX2NvcmVDaGVja2JveCB8fCAhdGhpcy5fY29yZUNoZWNrYm94LmNoZWNrZWQpIHtcbiAgICAgIGV2ZW50LnByZXZlbnREZWZhdWx0KClcbiAgICAgIGV2ZW50LnN0b3BQcm9wYWdhdGlvbigpXG5cbiAgICAgIC8vIFNob3cgdmlzdWFsIGZlZWRiYWNrIG9uIHRoZSBjaGVja2JveCB3cmFwcGVyXG4gICAgICBpZiAodGhpcy5fY29yZUNoZWNrYm94KSB7XG4gICAgICAgIGNvbnN0IGNoZWNrYm94V3JhcHBlciA9IHRoaXMuX2NvcmVDaGVja2JveC5jbG9zZXN0KCcuZm9ybS1jaGVjaycpXG4gICAgICAgIGlmIChjaGVja2JveFdyYXBwZXIpIHtcbiAgICAgICAgICBjaGVja2JveFdyYXBwZXIuY2xhc3NMaXN0LmFkZCgnYm9yZGVyJywgJ2JvcmRlci1kYW5nZXInLCAncC0yJywgJ3JvdW5kZWQnKVxuXG4gICAgICAgICAgLy8gUmVtb3ZlIHRoZSBoaWdobGlnaHQgYWZ0ZXIgMyBzZWNvbmRzXG4gICAgICAgICAgc2V0VGltZW91dCgoKSA9PiB7XG4gICAgICAgICAgICBjaGVja2JveFdyYXBwZXIuY2xhc3NMaXN0LnJlbW92ZSgnYm9yZGVyJywgJ2JvcmRlci1kYW5nZXInLCAncC0yJywgJ3JvdW5kZWQnKVxuICAgICAgICAgIH0sIDMwMDApXG4gICAgICAgIH1cbiAgICAgIH1cblxuICAgICAgcmV0dXJuIGZhbHNlXG4gICAgfVxuXG4gICAgcmV0dXJuIHRydWVcbiAgfVxufVxuIiwgIi8qKlxuICogU3RyaXBlIE1vZHVsZSAtIEphdmFTY3JpcHQgRW50cnkgUG9pbnRcbiAqXG4gKiBJbml0aWFsaXplcyBTdGltdWx1cy5qcyBhbmQgcmVnaXN0ZXJzIGFsbCBjb250cm9sbGVyc1xuICovXG5cbmltcG9ydCB7IEFwcGxpY2F0aW9uIH0gZnJvbSBcIkBob3R3aXJlZC9zdGltdWx1c1wiXG5cbi8vIEltcG9ydCBjb250cm9sbGVyc1xuaW1wb3J0IFN0cmlwZU9yZGVyQ29udHJvbGxlciBmcm9tIFwiLi9jb250cm9sbGVycy9zdHJpcGVfb3JkZXJfY29udHJvbGxlclwiXG5pbXBvcnQgT3JkZXJTdWJtaXRDb250cm9sbGVyIGZyb20gXCIuL2NvbnRyb2xsZXJzL29yZGVyX3N1Ym1pdF9jb250cm9sbGVyXCJcbmltcG9ydCBBZ2JWYWxpZGF0aW9uQ29udHJvbGxlciBmcm9tIFwiLi9jb250cm9sbGVycy9hZ2JfdmFsaWRhdGlvbl9jb250cm9sbGVyXCJcblxuLy8gU3RhcnQgU3RpbXVsdXMgYXBwbGljYXRpb25cbndpbmRvdy5TdGltdWx1cyA9IEFwcGxpY2F0aW9uLnN0YXJ0KClcblxuLy8gUmVnaXN0ZXIgY29udHJvbGxlcnNcblN0aW11bHVzLnJlZ2lzdGVyKFwic3RyaXBlLW9yZGVyXCIsIFN0cmlwZU9yZGVyQ29udHJvbGxlcilcblN0aW11bHVzLnJlZ2lzdGVyKFwib3JkZXItc3VibWl0XCIsIE9yZGVyU3VibWl0Q29udHJvbGxlcilcblN0aW11bHVzLnJlZ2lzdGVyKFwiYWdiLXZhbGlkYXRpb25cIiwgQWdiVmFsaWRhdGlvbkNvbnRyb2xsZXIpXG5cbi8vIERlYnVnIG1vZGUgaW4gZGV2ZWxvcG1lbnRcbmlmIChwcm9jZXNzLmVudi5OT0RFX0VOViA9PT0gJ2RldmVsb3BtZW50Jykge1xuICBTdGltdWx1cy5kZWJ1ZyA9IHRydWVcbiAgY29uc29sZS5sb2coJ1N0cmlwZSBNb2R1bGU6IFN0aW11bHVzIGluaXRpYWxpemVkIHdpdGggY29udHJvbGxlcnM6JywgU3RpbXVsdXMucm91dGVyLm1vZHVsZXNCeUlkZW50aWZpZXIpXG59XG5cbmNvbnNvbGUubG9nKCdTdHJpcGUgTW9kdWxl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SSxDQUFDLEtBQUssY0FBYztBQUN0QixlQUFPO0FBQUEsTUFDVDtBQUVBLFVBQUksQ0FBQyxLQUFLLGlCQUFpQixDQUFDLEtBQUssY0FBYyxTQUFTO0FBQ3RELGNBQU0sZUFBZTtBQUNyQixjQUFNLGdCQUFnQjtBQUd0QixZQUFJLEtBQUssZUFBZTtBQUN0QixnQkFBTSxrQkFBa0IsS0FBSyxjQUFjLFFBQVEsYUFBYTtBQUNoRSxjQUFJLGlCQUFpQjtBQUNuQiw0QkFBZ0IsVUFBVSxJQUFJLFVBQVUsaUJBQWlCLE9BQU8sU0FBUztBQUd6RSx1QkFBVyxNQUFNO0FBQ2YsOEJBQWdCLFVBQVUsT0FBTyxVQUFVLGlCQUFpQixPQUFPLFNBQVM7QUFBQSxZQUM5RSxHQUFHLEdBQUk7QUFBQSxVQUNUO0FBQUEsUUFDRjtBQUVBLGVBQU87QUFBQSxNQUNUO0FBRUEsYUFBTztBQUFBLElBQ1Q7QUFBQSxFQUNGO0FBdEdFLGdCQURLLG1DQUNFLFdBQVUsQ0FBQyxjQUFjO0FBQ2hDLGdCQUZLLG1DQUVFLFVBQVM7QUFBQSxJQUNkLFNBQVM7QUFBQSxFQUNYOzs7QUNQRixTQUFPLFdBQVcsWUFBWSxNQUFNO0FBR3BDLFdBQVMsU0FBUyxnQkFBZ0IsK0JBQXFCO0FBQ3ZELFdBQVMsU0FBUyxnQkFBZ0IsK0JBQXFCO0FBQ3ZELFdBQVMsU0FBUyxrQkFBa0IsaUNBQXVCO0FBRzNELE1BQUksTUFBd0M7QUFDMUMsYUFBUyxRQUFRO0FBQ2pCLFlBQVEsSUFBSSx5REFBeUQsU0FBUyxPQUFPLG1CQUFtQjtBQUFBLEVBQzFHO0FBRUEsVUFBUSxJQUFJLDRDQUE0QzsiLAogICJuYW1lcyI6IFsiZXJyb3IiLCAiZmV0Y2giLCAibWF0Y2giLCAib2xkVmFsdWUiLCAiZXJyb3IiLCAiY29uc3RydWN0b3IiLCAiZWxlbWVudCIsICJlcnJvciIsICJlcnJvciJdCn0K
