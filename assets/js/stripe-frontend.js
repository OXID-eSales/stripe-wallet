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
      const response = await fetch(this.buildUrlWithCsrfToken(this.urlValue), {
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
      const response = await fetch(this.buildUrlWithCsrfToken(this.urlValue), {
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
      var _a2;
      const stoken = ((_a2 = document.querySelector('input[name="stoken"]')) == null ? void 0 : _a2.value) || "";
      if (!stoken) {
        console.warn("CSRF token (stoken) not found in form");
        return url;
      }
      const separator = url.includes("?") ? "&" : "?";
      return url + separator + "stoken=" + encodeURIComponent(stoken);
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
     * Event Data:
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
    async handleMethodSelected(data) {
      const { paymentMethodId } = data;
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
     * Event Data:
     * {
     *   paymentMethod: string,
     *   basketId: string,
     *   totalPrice: number,
     *   currency: string,
     *   confirmed: boolean
     * }
     *
     * Responsibility:
     * - Process full payment flow: create contract → confirm payment → place order
     */
    async handleFooterSubmit(data) {
      var _a2, _b;
      const { paymentMethod, basketId, totalPrice, currency } = data;
      console.log("[OnePageStripeController] Footer submit clicked:", {
        paymentMethod,
        basketId,
        totalPrice,
        currency
      });
      if (!this.isStripeMethod(paymentMethod)) {
        return;
      }
      this.showStripeUI();
      this.showLoader();
      this.hideError();
      this.broadcast("oe:payment:processing", {
        paymentMethod
      });
      try {
        console.log("[OnePageStripeController] Step 1: Creating payment contract...");
        const contractResult = await this.createContract(paymentMethod);
        if (!contractResult.success) {
          throw new Error(contractResult.errorMessage || "Failed to create payment contract");
        }
        console.log("[OnePageStripeController] Contract created:", {
          contractId: contractResult.contractId,
          metadata: contractResult.metadata
        });
        const redirectUrl = ((_a2 = contractResult.metadata) == null ? void 0 : _a2.redirectUrl) || ((_b = contractResult.metadata) == null ? void 0 : _b.checkoutUrl);
        if (!redirectUrl) {
          throw new Error("No redirect URL provided by payment handler");
        }
        console.log("[OnePageStripeController] Redirecting to Stripe Checkout:", redirectUrl);
        this.broadcast("oe:payment:redirect", {
          provider: "stripe",
          contractId: contractResult.contractId,
          redirectUrl
        });
        window.location.href = redirectUrl;
      } catch (error2) {
        console.error("[OnePageStripeController] Payment processing failed:", error2);
        this.showError(error2.message || "Payment processing failed");
        this.broadcast("oe:payment:error", {
          error: error2.message,
          paymentMethod
        });
      } finally {
        this.hideLoader();
      }
    }
    /**
     * Handle oe:payment:confirm-requested event
     *
     * Event Data:
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
    async handleConfirmRequest(data) {
      const { paymentMethodId, clientSecret, contractId, orderId } = data;
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
        "oxidstripe_wallet",
        "oe_payments_stripe_wallet",
        // Module ID
        "stripe"
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
     * Initialize Stripe Payment Element with client secret
     *
     * @param {string} clientSecret - Stripe PaymentIntent client secret
     */
    async initializePaymentElement(clientSecret = null) {
      if (!this.stripe) {
        console.error("[OnePageStripeController] Stripe SDK not loaded");
        return;
      }
      if (this.paymentElement) {
        console.log("[OnePageStripeController] Destroying existing Payment Element...");
        this.paymentElement.destroy();
        this.paymentElement = null;
        this.elements = null;
      }
      console.log("[OnePageStripeController] Initializing Payment Element...", {
        hasClientSecret: !!clientSecret
      });
      if (this.hasElementTarget) {
        this.elementTarget.style.display = "block";
      }
      const elementsOptions = {
        appearance: {
          theme: "stripe"
        }
      };
      if (clientSecret) {
        elementsOptions.clientSecret = clientSecret;
      } else {
        elementsOptions.mode = "payment";
        elementsOptions.amount = 1e3;
        elementsOptions.currency = "eur";
      }
      this.elements = this.stripe.elements(elementsOptions);
      this.paymentElement = this.elements.create("payment");
      if (!this.hasElementTarget) {
        throw new Error("Payment Element target not found");
      }
      try {
        this.paymentElement.mount(this.elementTarget);
        console.log("[OnePageStripeController] Payment Element mounted successfully");
      } catch (error2) {
        console.error("[OnePageStripeController] Failed to mount Payment Element:", error2);
        throw error2;
      }
      console.log("[OnePageStripeController] Payment Element initialized");
    }
    /**
     * Confirm payment with Stripe SDK
     *
     * @param {string} clientSecret - Stripe PaymentIntent client secret (not used - Elements already has it)
     * @returns {Promise<Object>} - Payment result
     */
    async confirmPayment(clientSecret) {
      var _a2, _b;
      if (!this.stripe || !this.elements) {
        throw new Error("Stripe SDK not initialized");
      }
      console.log("[OnePageStripeController] Confirming payment with Stripe...", {
        hasClientSecret: !!clientSecret
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
    /**
     * API: Create payment contract
     *
     * @param {string} paymentMethodId - Payment method ID
     * @returns {Promise<Object>} - Contract result with clientSecret
     */
    async createContract(paymentMethodId) {
      const apiUrl = this.apiUrlValue || "/index.php?cl=OeCheckoutApi";
      console.log("[OnePageStripeController] Creating contract via API:", apiUrl);
      const response = await fetch(`${apiUrl}&fnc=processCheckout`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          paymentMethodId,
          returnUrl: this.returnUrlValue,
          cancelUrl: window.location.href
        })
      });
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      const data = await response.json();
      console.log("[OnePageStripeController] Contract API response:", data);
      return data;
    }
    /**
     * API: Place order
     *
     * @param {string} contractId - Contract ID
     * @returns {Promise<Object>} - Order result
     */
    async placeOrder(contractId) {
      const apiUrl = this.apiUrlValue || "/index.php?cl=OeCheckoutApi";
      console.log("[OnePageStripeController] Placing order via API:", apiUrl);
      const response = await fetch(`${apiUrl}&fnc=placeOrder`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          contractId,
          confirmTermsAndConditions: true,
          // Already confirmed by footer
          remark: ""
        })
      });
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      const data = await response.json();
      console.log("[OnePageStripeController] Order API response:", data);
      return data;
    }
  };
  __publicField(onepage_stripe_controller_default, "values", {
    publishableKey: String,
    mode: String,
    returnUrl: String,
    apiUrl: String
    // API base URL (e.g., /index.php?cl=OeCheckoutApi)
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
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsiLi4vLi4vbm9kZV9tb2R1bGVzL0Bob3R3aXJlZC9zdGltdWx1cy9kaXN0L3N0aW11bHVzLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9jb250cm9sbGVycy9zdHJpcGVfb3JkZXJfY29udHJvbGxlci5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvY29udHJvbGxlcnMvb3JkZXJfc3VibWl0X2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL2NvbnRyb2xsZXJzL2FnYl92YWxpZGF0aW9uX2NvbnRyb2xsZXIuanMiLCAiLi4vLi4vcmVzb3VyY2VzL2J1aWxkL2pzL3V0aWxzL2V2ZW50X2J1cy5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvbWl4aW5zL2V2ZW50X2J1c19taXhpbi5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvY29udHJvbGxlcnMvb25lcGFnZV9zdHJpcGVfY29udHJvbGxlci5qcyIsICIuLi8uLi9yZXNvdXJjZXMvYnVpbGQvanMvY29udHJvbGxlcnMvc3RyaXBlX2NoZWNrb3V0X2Zvb3Rlcl9jb250cm9sbGVyLmpzIiwgIi4uLy4uL3Jlc291cmNlcy9idWlsZC9qcy9hcHAuanMiXSwKICAic291cmNlc0NvbnRlbnQiOiBbIi8qXG5TdGltdWx1cyAzLjIuMVxuQ29weXJpZ2h0IFx1MDBBOSAyMDIzIEJhc2VjYW1wLCBMTENcbiAqL1xuY2xhc3MgRXZlbnRMaXN0ZW5lciB7XG4gICAgY29uc3RydWN0b3IoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKSB7XG4gICAgICAgIHRoaXMuZXZlbnRUYXJnZXQgPSBldmVudFRhcmdldDtcbiAgICAgICAgdGhpcy5ldmVudE5hbWUgPSBldmVudE5hbWU7XG4gICAgICAgIHRoaXMuZXZlbnRPcHRpb25zID0gZXZlbnRPcHRpb25zO1xuICAgICAgICB0aGlzLnVub3JkZXJlZEJpbmRpbmdzID0gbmV3IFNldCgpO1xuICAgIH1cbiAgICBjb25uZWN0KCkge1xuICAgICAgICB0aGlzLmV2ZW50VGFyZ2V0LmFkZEV2ZW50TGlzdGVuZXIodGhpcy5ldmVudE5hbWUsIHRoaXMsIHRoaXMuZXZlbnRPcHRpb25zKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldC5yZW1vdmVFdmVudExpc3RlbmVyKHRoaXMuZXZlbnROYW1lLCB0aGlzLCB0aGlzLmV2ZW50T3B0aW9ucyk7XG4gICAgfVxuICAgIGJpbmRpbmdDb25uZWN0ZWQoYmluZGluZykge1xuICAgICAgICB0aGlzLnVub3JkZXJlZEJpbmRpbmdzLmFkZChiaW5kaW5nKTtcbiAgICB9XG4gICAgYmluZGluZ0Rpc2Nvbm5lY3RlZChiaW5kaW5nKSB7XG4gICAgICAgIHRoaXMudW5vcmRlcmVkQmluZGluZ3MuZGVsZXRlKGJpbmRpbmcpO1xuICAgIH1cbiAgICBoYW5kbGVFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBleHRlbmRlZEV2ZW50ID0gZXh0ZW5kRXZlbnQoZXZlbnQpO1xuICAgICAgICBmb3IgKGNvbnN0IGJpbmRpbmcgb2YgdGhpcy5iaW5kaW5ncykge1xuICAgICAgICAgICAgaWYgKGV4dGVuZGVkRXZlbnQuaW1tZWRpYXRlUHJvcGFnYXRpb25TdG9wcGVkKSB7XG4gICAgICAgICAgICAgICAgYnJlYWs7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICBiaW5kaW5nLmhhbmRsZUV2ZW50KGV4dGVuZGVkRXZlbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGhhc0JpbmRpbmdzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy51bm9yZGVyZWRCaW5kaW5ncy5zaXplID4gMDtcbiAgICB9XG4gICAgZ2V0IGJpbmRpbmdzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnVub3JkZXJlZEJpbmRpbmdzKS5zb3J0KChsZWZ0LCByaWdodCkgPT4ge1xuICAgICAgICAgICAgY29uc3QgbGVmdEluZGV4ID0gbGVmdC5pbmRleCwgcmlnaHRJbmRleCA9IHJpZ2h0LmluZGV4O1xuICAgICAgICAgICAgcmV0dXJuIGxlZnRJbmRleCA8IHJpZ2h0SW5kZXggPyAtMSA6IGxlZnRJbmRleCA+IHJpZ2h0SW5kZXggPyAxIDogMDtcbiAgICAgICAgfSk7XG4gICAgfVxufVxuZnVuY3Rpb24gZXh0ZW5kRXZlbnQoZXZlbnQpIHtcbiAgICBpZiAoXCJpbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWRcIiBpbiBldmVudCkge1xuICAgICAgICByZXR1cm4gZXZlbnQ7XG4gICAgfVxuICAgIGVsc2Uge1xuICAgICAgICBjb25zdCB7IHN0b3BJbW1lZGlhdGVQcm9wYWdhdGlvbiB9ID0gZXZlbnQ7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKGV2ZW50LCB7XG4gICAgICAgICAgICBpbW1lZGlhdGVQcm9wYWdhdGlvblN0b3BwZWQ6IGZhbHNlLFxuICAgICAgICAgICAgc3RvcEltbWVkaWF0ZVByb3BhZ2F0aW9uKCkge1xuICAgICAgICAgICAgICAgIHRoaXMuaW1tZWRpYXRlUHJvcGFnYXRpb25TdG9wcGVkID0gdHJ1ZTtcbiAgICAgICAgICAgICAgICBzdG9wSW1tZWRpYXRlUHJvcGFnYXRpb24uY2FsbCh0aGlzKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0pO1xuICAgIH1cbn1cblxuY2xhc3MgRGlzcGF0Y2hlciB7XG4gICAgY29uc3RydWN0b3IoYXBwbGljYXRpb24pIHtcbiAgICAgICAgdGhpcy5hcHBsaWNhdGlvbiA9IGFwcGxpY2F0aW9uO1xuICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJNYXBzID0gbmV3IE1hcCgpO1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVycy5mb3JFYWNoKChldmVudExpc3RlbmVyKSA9PiBldmVudExpc3RlbmVyLmNvbm5lY3QoKSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgICAgICB0aGlzLmV2ZW50TGlzdGVuZXJzLmZvckVhY2goKGV2ZW50TGlzdGVuZXIpID0+IGV2ZW50TGlzdGVuZXIuZGlzY29ubmVjdCgpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgZXZlbnRMaXN0ZW5lcnMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMudmFsdWVzKCkpLnJlZHVjZSgobGlzdGVuZXJzLCBtYXApID0+IGxpc3RlbmVycy5jb25jYXQoQXJyYXkuZnJvbShtYXAudmFsdWVzKCkpKSwgW10pO1xuICAgIH1cbiAgICBiaW5kaW5nQ29ubmVjdGVkKGJpbmRpbmcpIHtcbiAgICAgICAgdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJGb3JCaW5kaW5nKGJpbmRpbmcpLmJpbmRpbmdDb25uZWN0ZWQoYmluZGluZyk7XG4gICAgfVxuICAgIGJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZywgY2xlYXJFdmVudExpc3RlbmVycyA9IGZhbHNlKSB7XG4gICAgICAgIHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKS5iaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcpO1xuICAgICAgICBpZiAoY2xlYXJFdmVudExpc3RlbmVycylcbiAgICAgICAgICAgIHRoaXMuY2xlYXJFdmVudExpc3RlbmVyc0ZvckJpbmRpbmcoYmluZGluZyk7XG4gICAgfVxuICAgIGhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwgPSB7fSkge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBgRXJyb3IgJHttZXNzYWdlfWAsIGRldGFpbCk7XG4gICAgfVxuICAgIGNsZWFyRXZlbnRMaXN0ZW5lcnNGb3JCaW5kaW5nKGJpbmRpbmcpIHtcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lciA9IHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKTtcbiAgICAgICAgaWYgKCFldmVudExpc3RlbmVyLmhhc0JpbmRpbmdzKCkpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5yZW1vdmVNYXBwZWRFdmVudExpc3RlbmVyRm9yKGJpbmRpbmcpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlbW92ZU1hcHBlZEV2ZW50TGlzdGVuZXJGb3IoYmluZGluZykge1xuICAgICAgICBjb25zdCB7IGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyB9ID0gYmluZGluZztcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lck1hcCA9IHRoaXMuZmV0Y2hFdmVudExpc3RlbmVyTWFwRm9yRXZlbnRUYXJnZXQoZXZlbnRUYXJnZXQpO1xuICAgICAgICBjb25zdCBjYWNoZUtleSA9IHRoaXMuY2FjaGVLZXkoZXZlbnROYW1lLCBldmVudE9wdGlvbnMpO1xuICAgICAgICBldmVudExpc3RlbmVyTWFwLmRlbGV0ZShjYWNoZUtleSk7XG4gICAgICAgIGlmIChldmVudExpc3RlbmVyTWFwLnNpemUgPT0gMClcbiAgICAgICAgICAgIHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMuZGVsZXRlKGV2ZW50VGFyZ2V0KTtcbiAgICB9XG4gICAgZmV0Y2hFdmVudExpc3RlbmVyRm9yQmluZGluZyhiaW5kaW5nKSB7XG4gICAgICAgIGNvbnN0IHsgZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zIH0gPSBiaW5kaW5nO1xuICAgICAgICByZXR1cm4gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICB9XG4gICAgZmV0Y2hFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICBjb25zdCBldmVudExpc3RlbmVyTWFwID0gdGhpcy5mZXRjaEV2ZW50TGlzdGVuZXJNYXBGb3JFdmVudFRhcmdldChldmVudFRhcmdldCk7XG4gICAgICAgIGNvbnN0IGNhY2hlS2V5ID0gdGhpcy5jYWNoZUtleShldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgIGxldCBldmVudExpc3RlbmVyID0gZXZlbnRMaXN0ZW5lck1hcC5nZXQoY2FjaGVLZXkpO1xuICAgICAgICBpZiAoIWV2ZW50TGlzdGVuZXIpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIgPSB0aGlzLmNyZWF0ZUV2ZW50TGlzdGVuZXIoZXZlbnRUYXJnZXQsIGV2ZW50TmFtZSwgZXZlbnRPcHRpb25zKTtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXJNYXAuc2V0KGNhY2hlS2V5LCBldmVudExpc3RlbmVyKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gZXZlbnRMaXN0ZW5lcjtcbiAgICB9XG4gICAgY3JlYXRlRXZlbnRMaXN0ZW5lcihldmVudFRhcmdldCwgZXZlbnROYW1lLCBldmVudE9wdGlvbnMpIHtcbiAgICAgICAgY29uc3QgZXZlbnRMaXN0ZW5lciA9IG5ldyBFdmVudExpc3RlbmVyKGV2ZW50VGFyZ2V0LCBldmVudE5hbWUsIGV2ZW50T3B0aW9ucyk7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGV2ZW50TGlzdGVuZXIuY29ubmVjdCgpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudExpc3RlbmVyO1xuICAgIH1cbiAgICBmZXRjaEV2ZW50TGlzdGVuZXJNYXBGb3JFdmVudFRhcmdldChldmVudFRhcmdldCkge1xuICAgICAgICBsZXQgZXZlbnRMaXN0ZW5lck1hcCA9IHRoaXMuZXZlbnRMaXN0ZW5lck1hcHMuZ2V0KGV2ZW50VGFyZ2V0KTtcbiAgICAgICAgaWYgKCFldmVudExpc3RlbmVyTWFwKSB7XG4gICAgICAgICAgICBldmVudExpc3RlbmVyTWFwID0gbmV3IE1hcCgpO1xuICAgICAgICAgICAgdGhpcy5ldmVudExpc3RlbmVyTWFwcy5zZXQoZXZlbnRUYXJnZXQsIGV2ZW50TGlzdGVuZXJNYXApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBldmVudExpc3RlbmVyTWFwO1xuICAgIH1cbiAgICBjYWNoZUtleShldmVudE5hbWUsIGV2ZW50T3B0aW9ucykge1xuICAgICAgICBjb25zdCBwYXJ0cyA9IFtldmVudE5hbWVdO1xuICAgICAgICBPYmplY3Qua2V5cyhldmVudE9wdGlvbnMpXG4gICAgICAgICAgICAuc29ydCgpXG4gICAgICAgICAgICAuZm9yRWFjaCgoa2V5KSA9PiB7XG4gICAgICAgICAgICBwYXJ0cy5wdXNoKGAke2V2ZW50T3B0aW9uc1trZXldID8gXCJcIiA6IFwiIVwifSR7a2V5fWApO1xuICAgICAgICB9KTtcbiAgICAgICAgcmV0dXJuIHBhcnRzLmpvaW4oXCI6XCIpO1xuICAgIH1cbn1cblxuY29uc3QgZGVmYXVsdEFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzID0ge1xuICAgIHN0b3AoeyBldmVudCwgdmFsdWUgfSkge1xuICAgICAgICBpZiAodmFsdWUpXG4gICAgICAgICAgICBldmVudC5zdG9wUHJvcGFnYXRpb24oKTtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfSxcbiAgICBwcmV2ZW50KHsgZXZlbnQsIHZhbHVlIH0pIHtcbiAgICAgICAgaWYgKHZhbHVlKVxuICAgICAgICAgICAgZXZlbnQucHJldmVudERlZmF1bHQoKTtcbiAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgfSxcbiAgICBzZWxmKHsgZXZlbnQsIHZhbHVlLCBlbGVtZW50IH0pIHtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICByZXR1cm4gZWxlbWVudCA9PT0gZXZlbnQudGFyZ2V0O1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICB9LFxufTtcbmNvbnN0IGRlc2NyaXB0b3JQYXR0ZXJuID0gL14oPzooPzooW14uXSs/KVxcKyk/KC4rPykoPzpcXC4oLis/KSk/KD86QCh3aW5kb3d8ZG9jdW1lbnQpKT8tPik/KC4rPykoPzojKFteOl0rPykpKD86OiguKykpPyQvO1xuZnVuY3Rpb24gcGFyc2VBY3Rpb25EZXNjcmlwdG9yU3RyaW5nKGRlc2NyaXB0b3JTdHJpbmcpIHtcbiAgICBjb25zdCBzb3VyY2UgPSBkZXNjcmlwdG9yU3RyaW5nLnRyaW0oKTtcbiAgICBjb25zdCBtYXRjaGVzID0gc291cmNlLm1hdGNoKGRlc2NyaXB0b3JQYXR0ZXJuKSB8fCBbXTtcbiAgICBsZXQgZXZlbnROYW1lID0gbWF0Y2hlc1syXTtcbiAgICBsZXQga2V5RmlsdGVyID0gbWF0Y2hlc1szXTtcbiAgICBpZiAoa2V5RmlsdGVyICYmICFbXCJrZXlkb3duXCIsIFwia2V5dXBcIiwgXCJrZXlwcmVzc1wiXS5pbmNsdWRlcyhldmVudE5hbWUpKSB7XG4gICAgICAgIGV2ZW50TmFtZSArPSBgLiR7a2V5RmlsdGVyfWA7XG4gICAgICAgIGtleUZpbHRlciA9IFwiXCI7XG4gICAgfVxuICAgIHJldHVybiB7XG4gICAgICAgIGV2ZW50VGFyZ2V0OiBwYXJzZUV2ZW50VGFyZ2V0KG1hdGNoZXNbNF0pLFxuICAgICAgICBldmVudE5hbWUsXG4gICAgICAgIGV2ZW50T3B0aW9uczogbWF0Y2hlc1s3XSA/IHBhcnNlRXZlbnRPcHRpb25zKG1hdGNoZXNbN10pIDoge30sXG4gICAgICAgIGlkZW50aWZpZXI6IG1hdGNoZXNbNV0sXG4gICAgICAgIG1ldGhvZE5hbWU6IG1hdGNoZXNbNl0sXG4gICAgICAgIGtleUZpbHRlcjogbWF0Y2hlc1sxXSB8fCBrZXlGaWx0ZXIsXG4gICAgfTtcbn1cbmZ1bmN0aW9uIHBhcnNlRXZlbnRUYXJnZXQoZXZlbnRUYXJnZXROYW1lKSB7XG4gICAgaWYgKGV2ZW50VGFyZ2V0TmFtZSA9PSBcIndpbmRvd1wiKSB7XG4gICAgICAgIHJldHVybiB3aW5kb3c7XG4gICAgfVxuICAgIGVsc2UgaWYgKGV2ZW50VGFyZ2V0TmFtZSA9PSBcImRvY3VtZW50XCIpIHtcbiAgICAgICAgcmV0dXJuIGRvY3VtZW50O1xuICAgIH1cbn1cbmZ1bmN0aW9uIHBhcnNlRXZlbnRPcHRpb25zKGV2ZW50T3B0aW9ucykge1xuICAgIHJldHVybiBldmVudE9wdGlvbnNcbiAgICAgICAgLnNwbGl0KFwiOlwiKVxuICAgICAgICAucmVkdWNlKChvcHRpb25zLCB0b2tlbikgPT4gT2JqZWN0LmFzc2lnbihvcHRpb25zLCB7IFt0b2tlbi5yZXBsYWNlKC9eIS8sIFwiXCIpXTogIS9eIS8udGVzdCh0b2tlbikgfSksIHt9KTtcbn1cbmZ1bmN0aW9uIHN0cmluZ2lmeUV2ZW50VGFyZ2V0KGV2ZW50VGFyZ2V0KSB7XG4gICAgaWYgKGV2ZW50VGFyZ2V0ID09IHdpbmRvdykge1xuICAgICAgICByZXR1cm4gXCJ3aW5kb3dcIjtcbiAgICB9XG4gICAgZWxzZSBpZiAoZXZlbnRUYXJnZXQgPT0gZG9jdW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIFwiZG9jdW1lbnRcIjtcbiAgICB9XG59XG5cbmZ1bmN0aW9uIGNhbWVsaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLnJlcGxhY2UoLyg/OltfLV0pKFthLXowLTldKS9nLCAoXywgY2hhcikgPT4gY2hhci50b1VwcGVyQ2FzZSgpKTtcbn1cbmZ1bmN0aW9uIG5hbWVzcGFjZUNhbWVsaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIGNhbWVsaXplKHZhbHVlLnJlcGxhY2UoLy0tL2csIFwiLVwiKS5yZXBsYWNlKC9fXy9nLCBcIl9cIikpO1xufVxuZnVuY3Rpb24gY2FwaXRhbGl6ZSh2YWx1ZSkge1xuICAgIHJldHVybiB2YWx1ZS5jaGFyQXQoMCkudG9VcHBlckNhc2UoKSArIHZhbHVlLnNsaWNlKDEpO1xufVxuZnVuY3Rpb24gZGFzaGVyaXplKHZhbHVlKSB7XG4gICAgcmV0dXJuIHZhbHVlLnJlcGxhY2UoLyhbQS1aXSkvZywgKF8sIGNoYXIpID0+IGAtJHtjaGFyLnRvTG93ZXJDYXNlKCl9YCk7XG59XG5mdW5jdGlvbiB0b2tlbml6ZSh2YWx1ZSkge1xuICAgIHJldHVybiB2YWx1ZS5tYXRjaCgvW15cXHNdKy9nKSB8fCBbXTtcbn1cblxuZnVuY3Rpb24gaXNTb21ldGhpbmcob2JqZWN0KSB7XG4gICAgcmV0dXJuIG9iamVjdCAhPT0gbnVsbCAmJiBvYmplY3QgIT09IHVuZGVmaW5lZDtcbn1cbmZ1bmN0aW9uIGhhc1Byb3BlcnR5KG9iamVjdCwgcHJvcGVydHkpIHtcbiAgICByZXR1cm4gT2JqZWN0LnByb3RvdHlwZS5oYXNPd25Qcm9wZXJ0eS5jYWxsKG9iamVjdCwgcHJvcGVydHkpO1xufVxuXG5jb25zdCBhbGxNb2RpZmllcnMgPSBbXCJtZXRhXCIsIFwiY3RybFwiLCBcImFsdFwiLCBcInNoaWZ0XCJdO1xuY2xhc3MgQWN0aW9uIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBpbmRleCwgZGVzY3JpcHRvciwgc2NoZW1hKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuaW5kZXggPSBpbmRleDtcbiAgICAgICAgdGhpcy5ldmVudFRhcmdldCA9IGRlc2NyaXB0b3IuZXZlbnRUYXJnZXQgfHwgZWxlbWVudDtcbiAgICAgICAgdGhpcy5ldmVudE5hbWUgPSBkZXNjcmlwdG9yLmV2ZW50TmFtZSB8fCBnZXREZWZhdWx0RXZlbnROYW1lRm9yRWxlbWVudChlbGVtZW50KSB8fCBlcnJvcihcIm1pc3NpbmcgZXZlbnQgbmFtZVwiKTtcbiAgICAgICAgdGhpcy5ldmVudE9wdGlvbnMgPSBkZXNjcmlwdG9yLmV2ZW50T3B0aW9ucyB8fCB7fTtcbiAgICAgICAgdGhpcy5pZGVudGlmaWVyID0gZGVzY3JpcHRvci5pZGVudGlmaWVyIHx8IGVycm9yKFwibWlzc2luZyBpZGVudGlmaWVyXCIpO1xuICAgICAgICB0aGlzLm1ldGhvZE5hbWUgPSBkZXNjcmlwdG9yLm1ldGhvZE5hbWUgfHwgZXJyb3IoXCJtaXNzaW5nIG1ldGhvZCBuYW1lXCIpO1xuICAgICAgICB0aGlzLmtleUZpbHRlciA9IGRlc2NyaXB0b3Iua2V5RmlsdGVyIHx8IFwiXCI7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgIH1cbiAgICBzdGF0aWMgZm9yVG9rZW4odG9rZW4sIHNjaGVtYSkge1xuICAgICAgICByZXR1cm4gbmV3IHRoaXModG9rZW4uZWxlbWVudCwgdG9rZW4uaW5kZXgsIHBhcnNlQWN0aW9uRGVzY3JpcHRvclN0cmluZyh0b2tlbi5jb250ZW50KSwgc2NoZW1hKTtcbiAgICB9XG4gICAgdG9TdHJpbmcoKSB7XG4gICAgICAgIGNvbnN0IGV2ZW50RmlsdGVyID0gdGhpcy5rZXlGaWx0ZXIgPyBgLiR7dGhpcy5rZXlGaWx0ZXJ9YCA6IFwiXCI7XG4gICAgICAgIGNvbnN0IGV2ZW50VGFyZ2V0ID0gdGhpcy5ldmVudFRhcmdldE5hbWUgPyBgQCR7dGhpcy5ldmVudFRhcmdldE5hbWV9YCA6IFwiXCI7XG4gICAgICAgIHJldHVybiBgJHt0aGlzLmV2ZW50TmFtZX0ke2V2ZW50RmlsdGVyfSR7ZXZlbnRUYXJnZXR9LT4ke3RoaXMuaWRlbnRpZmllcn0jJHt0aGlzLm1ldGhvZE5hbWV9YDtcbiAgICB9XG4gICAgc2hvdWxkSWdub3JlS2V5Ym9hcmRFdmVudChldmVudCkge1xuICAgICAgICBpZiAoIXRoaXMua2V5RmlsdGVyKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgY29uc3QgZmlsdGVycyA9IHRoaXMua2V5RmlsdGVyLnNwbGl0KFwiK1wiKTtcbiAgICAgICAgaWYgKHRoaXMua2V5RmlsdGVyRGlzc2F0aXNmaWVkKGV2ZW50LCBmaWx0ZXJzKSkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgY29uc3Qgc3RhbmRhcmRGaWx0ZXIgPSBmaWx0ZXJzLmZpbHRlcigoa2V5KSA9PiAhYWxsTW9kaWZpZXJzLmluY2x1ZGVzKGtleSkpWzBdO1xuICAgICAgICBpZiAoIXN0YW5kYXJkRmlsdGVyKSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICAgICAgaWYgKCFoYXNQcm9wZXJ0eSh0aGlzLmtleU1hcHBpbmdzLCBzdGFuZGFyZEZpbHRlcikpIHtcbiAgICAgICAgICAgIGVycm9yKGBjb250YWlucyB1bmtub3duIGtleSBmaWx0ZXI6ICR7dGhpcy5rZXlGaWx0ZXJ9YCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHRoaXMua2V5TWFwcGluZ3Nbc3RhbmRhcmRGaWx0ZXJdLnRvTG93ZXJDYXNlKCkgIT09IGV2ZW50LmtleS50b0xvd2VyQ2FzZSgpO1xuICAgIH1cbiAgICBzaG91bGRJZ25vcmVNb3VzZUV2ZW50KGV2ZW50KSB7XG4gICAgICAgIGlmICghdGhpcy5rZXlGaWx0ZXIpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBjb25zdCBmaWx0ZXJzID0gW3RoaXMua2V5RmlsdGVyXTtcbiAgICAgICAgaWYgKHRoaXMua2V5RmlsdGVyRGlzc2F0aXNmaWVkKGV2ZW50LCBmaWx0ZXJzKSkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgIH1cbiAgICBnZXQgcGFyYW1zKCkge1xuICAgICAgICBjb25zdCBwYXJhbXMgPSB7fTtcbiAgICAgICAgY29uc3QgcGF0dGVybiA9IG5ldyBSZWdFeHAoYF5kYXRhLSR7dGhpcy5pZGVudGlmaWVyfS0oLispLXBhcmFtJGAsIFwiaVwiKTtcbiAgICAgICAgZm9yIChjb25zdCB7IG5hbWUsIHZhbHVlIH0gb2YgQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnQuYXR0cmlidXRlcykpIHtcbiAgICAgICAgICAgIGNvbnN0IG1hdGNoID0gbmFtZS5tYXRjaChwYXR0ZXJuKTtcbiAgICAgICAgICAgIGNvbnN0IGtleSA9IG1hdGNoICYmIG1hdGNoWzFdO1xuICAgICAgICAgICAgaWYgKGtleSkge1xuICAgICAgICAgICAgICAgIHBhcmFtc1tjYW1lbGl6ZShrZXkpXSA9IHR5cGVjYXN0KHZhbHVlKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFyYW1zO1xuICAgIH1cbiAgICBnZXQgZXZlbnRUYXJnZXROYW1lKCkge1xuICAgICAgICByZXR1cm4gc3RyaW5naWZ5RXZlbnRUYXJnZXQodGhpcy5ldmVudFRhcmdldCk7XG4gICAgfVxuICAgIGdldCBrZXlNYXBwaW5ncygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmtleU1hcHBpbmdzO1xuICAgIH1cbiAgICBrZXlGaWx0ZXJEaXNzYXRpc2ZpZWQoZXZlbnQsIGZpbHRlcnMpIHtcbiAgICAgICAgY29uc3QgW21ldGEsIGN0cmwsIGFsdCwgc2hpZnRdID0gYWxsTW9kaWZpZXJzLm1hcCgobW9kaWZpZXIpID0+IGZpbHRlcnMuaW5jbHVkZXMobW9kaWZpZXIpKTtcbiAgICAgICAgcmV0dXJuIGV2ZW50Lm1ldGFLZXkgIT09IG1ldGEgfHwgZXZlbnQuY3RybEtleSAhPT0gY3RybCB8fCBldmVudC5hbHRLZXkgIT09IGFsdCB8fCBldmVudC5zaGlmdEtleSAhPT0gc2hpZnQ7XG4gICAgfVxufVxuY29uc3QgZGVmYXVsdEV2ZW50TmFtZXMgPSB7XG4gICAgYTogKCkgPT4gXCJjbGlja1wiLFxuICAgIGJ1dHRvbjogKCkgPT4gXCJjbGlja1wiLFxuICAgIGZvcm06ICgpID0+IFwic3VibWl0XCIsXG4gICAgZGV0YWlsczogKCkgPT4gXCJ0b2dnbGVcIixcbiAgICBpbnB1dDogKGUpID0+IChlLmdldEF0dHJpYnV0ZShcInR5cGVcIikgPT0gXCJzdWJtaXRcIiA/IFwiY2xpY2tcIiA6IFwiaW5wdXRcIiksXG4gICAgc2VsZWN0OiAoKSA9PiBcImNoYW5nZVwiLFxuICAgIHRleHRhcmVhOiAoKSA9PiBcImlucHV0XCIsXG59O1xuZnVuY3Rpb24gZ2V0RGVmYXVsdEV2ZW50TmFtZUZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgIGNvbnN0IHRhZ05hbWUgPSBlbGVtZW50LnRhZ05hbWUudG9Mb3dlckNhc2UoKTtcbiAgICBpZiAodGFnTmFtZSBpbiBkZWZhdWx0RXZlbnROYW1lcykge1xuICAgICAgICByZXR1cm4gZGVmYXVsdEV2ZW50TmFtZXNbdGFnTmFtZV0oZWxlbWVudCk7XG4gICAgfVxufVxuZnVuY3Rpb24gZXJyb3IobWVzc2FnZSkge1xuICAgIHRocm93IG5ldyBFcnJvcihtZXNzYWdlKTtcbn1cbmZ1bmN0aW9uIHR5cGVjYXN0KHZhbHVlKSB7XG4gICAgdHJ5IHtcbiAgICAgICAgcmV0dXJuIEpTT04ucGFyc2UodmFsdWUpO1xuICAgIH1cbiAgICBjYXRjaCAob19PKSB7XG4gICAgICAgIHJldHVybiB2YWx1ZTtcbiAgICB9XG59XG5cbmNsYXNzIEJpbmRpbmcge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmFjdGlvbiA9IGFjdGlvbjtcbiAgICB9XG4gICAgZ2V0IGluZGV4KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uaW5kZXg7XG4gICAgfVxuICAgIGdldCBldmVudFRhcmdldCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYWN0aW9uLmV2ZW50VGFyZ2V0O1xuICAgIH1cbiAgICBnZXQgZXZlbnRPcHRpb25zKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uZXZlbnRPcHRpb25zO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBoYW5kbGVFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBhY3Rpb25FdmVudCA9IHRoaXMucHJlcGFyZUFjdGlvbkV2ZW50KGV2ZW50KTtcbiAgICAgICAgaWYgKHRoaXMud2lsbEJlSW52b2tlZEJ5RXZlbnQoZXZlbnQpICYmIHRoaXMuYXBwbHlFdmVudE1vZGlmaWVycyhhY3Rpb25FdmVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuaW52b2tlV2l0aEV2ZW50KGFjdGlvbkV2ZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgZXZlbnROYW1lKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hY3Rpb24uZXZlbnROYW1lO1xuICAgIH1cbiAgICBnZXQgbWV0aG9kKCkge1xuICAgICAgICBjb25zdCBtZXRob2QgPSB0aGlzLmNvbnRyb2xsZXJbdGhpcy5tZXRob2ROYW1lXTtcbiAgICAgICAgaWYgKHR5cGVvZiBtZXRob2QgPT0gXCJmdW5jdGlvblwiKSB7XG4gICAgICAgICAgICByZXR1cm4gbWV0aG9kO1xuICAgICAgICB9XG4gICAgICAgIHRocm93IG5ldyBFcnJvcihgQWN0aW9uIFwiJHt0aGlzLmFjdGlvbn1cIiByZWZlcmVuY2VzIHVuZGVmaW5lZCBtZXRob2QgXCIke3RoaXMubWV0aG9kTmFtZX1cImApO1xuICAgIH1cbiAgICBhcHBseUV2ZW50TW9kaWZpZXJzKGV2ZW50KSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdGhpcy5hY3Rpb247XG4gICAgICAgIGNvbnN0IHsgYWN0aW9uRGVzY3JpcHRvckZpbHRlcnMgfSA9IHRoaXMuY29udGV4dC5hcHBsaWNhdGlvbjtcbiAgICAgICAgY29uc3QgeyBjb250cm9sbGVyIH0gPSB0aGlzLmNvbnRleHQ7XG4gICAgICAgIGxldCBwYXNzZXMgPSB0cnVlO1xuICAgICAgICBmb3IgKGNvbnN0IFtuYW1lLCB2YWx1ZV0gb2YgT2JqZWN0LmVudHJpZXModGhpcy5ldmVudE9wdGlvbnMpKSB7XG4gICAgICAgICAgICBpZiAobmFtZSBpbiBhY3Rpb25EZXNjcmlwdG9yRmlsdGVycykge1xuICAgICAgICAgICAgICAgIGNvbnN0IGZpbHRlciA9IGFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzW25hbWVdO1xuICAgICAgICAgICAgICAgIHBhc3NlcyA9IHBhc3NlcyAmJiBmaWx0ZXIoeyBuYW1lLCB2YWx1ZSwgZXZlbnQsIGVsZW1lbnQsIGNvbnRyb2xsZXIgfSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICBjb250aW51ZTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFzc2VzO1xuICAgIH1cbiAgICBwcmVwYXJlQWN0aW9uRXZlbnQoZXZlbnQpIHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24oZXZlbnQsIHsgcGFyYW1zOiB0aGlzLmFjdGlvbi5wYXJhbXMgfSk7XG4gICAgfVxuICAgIGludm9rZVdpdGhFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCB7IHRhcmdldCwgY3VycmVudFRhcmdldCB9ID0gZXZlbnQ7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLm1ldGhvZC5jYWxsKHRoaXMuY29udHJvbGxlciwgZXZlbnQpO1xuICAgICAgICAgICAgdGhpcy5jb250ZXh0LmxvZ0RlYnVnQWN0aXZpdHkodGhpcy5tZXRob2ROYW1lLCB7IGV2ZW50LCB0YXJnZXQsIGN1cnJlbnRUYXJnZXQsIGFjdGlvbjogdGhpcy5tZXRob2ROYW1lIH0pO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50LCBpbmRleCB9ID0gdGhpcztcbiAgICAgICAgICAgIGNvbnN0IGRldGFpbCA9IHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCwgaW5kZXgsIGV2ZW50IH07XG4gICAgICAgICAgICB0aGlzLmNvbnRleHQuaGFuZGxlRXJyb3IoZXJyb3IsIGBpbnZva2luZyBhY3Rpb24gXCIke3RoaXMuYWN0aW9ufVwiYCwgZGV0YWlsKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICB3aWxsQmVJbnZva2VkQnlFdmVudChldmVudCkge1xuICAgICAgICBjb25zdCBldmVudFRhcmdldCA9IGV2ZW50LnRhcmdldDtcbiAgICAgICAgaWYgKGV2ZW50IGluc3RhbmNlb2YgS2V5Ym9hcmRFdmVudCAmJiB0aGlzLmFjdGlvbi5zaG91bGRJZ25vcmVLZXlib2FyZEV2ZW50KGV2ZW50KSkge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGlmIChldmVudCBpbnN0YW5jZW9mIE1vdXNlRXZlbnQgJiYgdGhpcy5hY3Rpb24uc2hvdWxkSWdub3JlTW91c2VFdmVudChldmVudCkpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBpZiAodGhpcy5lbGVtZW50ID09PSBldmVudFRhcmdldCkge1xuICAgICAgICAgICAgcmV0dXJuIHRydWU7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAoZXZlbnRUYXJnZXQgaW5zdGFuY2VvZiBFbGVtZW50ICYmIHRoaXMuZWxlbWVudC5jb250YWlucyhldmVudFRhcmdldCkpIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmNvbnRhaW5zRWxlbWVudChldmVudFRhcmdldCk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5jb250YWluc0VsZW1lbnQodGhpcy5hY3Rpb24uZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuY29udHJvbGxlcjtcbiAgICB9XG4gICAgZ2V0IG1ldGhvZE5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFjdGlvbi5tZXRob2ROYW1lO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbn1cblxuY2xhc3MgRWxlbWVudE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXJJbml0ID0geyBhdHRyaWJ1dGVzOiB0cnVlLCBjaGlsZExpc3Q6IHRydWUsIHN1YnRyZWU6IHRydWUgfTtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5lbGVtZW50cyA9IG5ldyBTZXQoKTtcbiAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyID0gbmV3IE11dGF0aW9uT2JzZXJ2ZXIoKG11dGF0aW9ucykgPT4gdGhpcy5wcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IHRydWU7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIub2JzZXJ2ZSh0aGlzLmVsZW1lbnQsIHRoaXMubXV0YXRpb25PYnNlcnZlckluaXQpO1xuICAgICAgICAgICAgdGhpcy5yZWZyZXNoKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcGF1c2UoY2FsbGJhY2spIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLmRpc2Nvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMuc3RhcnRlZCA9IGZhbHNlO1xuICAgICAgICB9XG4gICAgICAgIGNhbGxiYWNrKCk7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIub2JzZXJ2ZSh0aGlzLmVsZW1lbnQsIHRoaXMubXV0YXRpb25PYnNlcnZlckluaXQpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIudGFrZVJlY29yZHMoKTtcbiAgICAgICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gbmV3IFNldCh0aGlzLm1hdGNoRWxlbWVudHNJblRyZWUoKSk7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgQXJyYXkuZnJvbSh0aGlzLmVsZW1lbnRzKSkge1xuICAgICAgICAgICAgICAgIGlmICghbWF0Y2hlcy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICAgICAgdGhpcy5yZW1vdmVFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiBBcnJheS5mcm9tKG1hdGNoZXMpKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5hZGRFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbnMobXV0YXRpb25zKSB7XG4gICAgICAgIGlmICh0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIGZvciAoY29uc3QgbXV0YXRpb24gb2YgbXV0YXRpb25zKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzTXV0YXRpb24obXV0YXRpb24pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NNdXRhdGlvbihtdXRhdGlvbikge1xuICAgICAgICBpZiAobXV0YXRpb24udHlwZSA9PSBcImF0dHJpYnV0ZXNcIikge1xuICAgICAgICAgICAgdGhpcy5wcm9jZXNzQXR0cmlidXRlQ2hhbmdlKG11dGF0aW9uLnRhcmdldCwgbXV0YXRpb24uYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSBpZiAobXV0YXRpb24udHlwZSA9PSBcImNoaWxkTGlzdFwiKSB7XG4gICAgICAgICAgICB0aGlzLnByb2Nlc3NSZW1vdmVkTm9kZXMobXV0YXRpb24ucmVtb3ZlZE5vZGVzKTtcbiAgICAgICAgICAgIHRoaXMucHJvY2Vzc0FkZGVkTm9kZXMobXV0YXRpb24uYWRkZWROb2Rlcyk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc0F0dHJpYnV0ZUNoYW5nZShlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmICh0aGlzLmVsZW1lbnRzLmhhcyhlbGVtZW50KSkge1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZUNoYW5nZWQgJiYgdGhpcy5tYXRjaEVsZW1lbnQoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICAgICAgdGhpcy5yZW1vdmVFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgICAgIGVsc2UgaWYgKHRoaXMubWF0Y2hFbGVtZW50KGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLmFkZEVsZW1lbnQoZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcHJvY2Vzc1JlbW92ZWROb2Rlcyhub2Rlcykge1xuICAgICAgICBmb3IgKGNvbnN0IG5vZGUgb2YgQXJyYXkuZnJvbShub2RlcykpIHtcbiAgICAgICAgICAgIGNvbnN0IGVsZW1lbnQgPSB0aGlzLmVsZW1lbnRGcm9tTm9kZShub2RlKTtcbiAgICAgICAgICAgIGlmIChlbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgdGhpcy5wcm9jZXNzVHJlZShlbGVtZW50LCB0aGlzLnJlbW92ZUVsZW1lbnQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHByb2Nlc3NBZGRlZE5vZGVzKG5vZGVzKSB7XG4gICAgICAgIGZvciAoY29uc3Qgbm9kZSBvZiBBcnJheS5mcm9tKG5vZGVzKSkge1xuICAgICAgICAgICAgY29uc3QgZWxlbWVudCA9IHRoaXMuZWxlbWVudEZyb21Ob2RlKG5vZGUpO1xuICAgICAgICAgICAgaWYgKGVsZW1lbnQgJiYgdGhpcy5lbGVtZW50SXNBY3RpdmUoZWxlbWVudCkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnByb2Nlc3NUcmVlKGVsZW1lbnQsIHRoaXMuYWRkRWxlbWVudCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVsZWdhdGUubWF0Y2hFbGVtZW50KGVsZW1lbnQpO1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUgPSB0aGlzLmVsZW1lbnQpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVsZWdhdGUubWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKTtcbiAgICB9XG4gICAgcHJvY2Vzc1RyZWUodHJlZSwgcHJvY2Vzc29yKSB7XG4gICAgICAgIGZvciAoY29uc3QgZWxlbWVudCBvZiB0aGlzLm1hdGNoRWxlbWVudHNJblRyZWUodHJlZSkpIHtcbiAgICAgICAgICAgIHByb2Nlc3Nvci5jYWxsKHRoaXMsIGVsZW1lbnQpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRGcm9tTm9kZShub2RlKSB7XG4gICAgICAgIGlmIChub2RlLm5vZGVUeXBlID09IE5vZGUuRUxFTUVOVF9OT0RFKSB7XG4gICAgICAgICAgICByZXR1cm4gbm9kZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50SXNBY3RpdmUoZWxlbWVudCkge1xuICAgICAgICBpZiAoZWxlbWVudC5pc0Nvbm5lY3RlZCAhPSB0aGlzLmVsZW1lbnQuaXNDb25uZWN0ZWQpIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiB0aGlzLmVsZW1lbnQuY29udGFpbnMoZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgYWRkRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGlmICghdGhpcy5lbGVtZW50cy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIGlmICh0aGlzLmVsZW1lbnRJc0FjdGl2ZShlbGVtZW50KSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZWxlbWVudHMuYWRkKGVsZW1lbnQpO1xuICAgICAgICAgICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkKSB7XG4gICAgICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudE1hdGNoZWQoZWxlbWVudCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHJlbW92ZUVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBpZiAodGhpcy5lbGVtZW50cy5oYXMoZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMuZWxlbWVudHMuZGVsZXRlKGVsZW1lbnQpO1xuICAgICAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZCkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZChlbGVtZW50KTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbn1cblxuY2xhc3MgQXR0cmlidXRlT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlTmFtZSA9IGF0dHJpYnV0ZU5hbWU7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIgPSBuZXcgRWxlbWVudE9ic2VydmVyKGVsZW1lbnQsIHRoaXMpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudE9ic2VydmVyLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzZWxlY3RvcigpIHtcbiAgICAgICAgcmV0dXJuIGBbJHt0aGlzLmF0dHJpYnV0ZU5hbWV9XWA7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5wYXVzZShjYWxsYmFjayk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBnZXQgc3RhcnRlZCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0ZWQ7XG4gICAgfVxuICAgIG1hdGNoRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIHJldHVybiBlbGVtZW50Lmhhc0F0dHJpYnV0ZSh0aGlzLmF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbiAgICBtYXRjaEVsZW1lbnRzSW5UcmVlKHRyZWUpIHtcbiAgICAgICAgY29uc3QgbWF0Y2ggPSB0aGlzLm1hdGNoRWxlbWVudCh0cmVlKSA/IFt0cmVlXSA6IFtdO1xuICAgICAgICBjb25zdCBtYXRjaGVzID0gQXJyYXkuZnJvbSh0cmVlLnF1ZXJ5U2VsZWN0b3JBbGwodGhpcy5zZWxlY3RvcikpO1xuICAgICAgICByZXR1cm4gbWF0Y2guY29uY2F0KG1hdGNoZXMpO1xuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKSB7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQsIHRoaXMuYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZChlbGVtZW50KSB7XG4gICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLmVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZEF0dHJpYnV0ZShlbGVtZW50LCB0aGlzLmF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuZWxlbWVudEF0dHJpYnV0ZVZhbHVlQ2hhbmdlZCAmJiB0aGlzLmF0dHJpYnV0ZU5hbWUgPT0gYXR0cmlidXRlTmFtZSkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5lbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxufVxuXG5mdW5jdGlvbiBhZGQobWFwLCBrZXksIHZhbHVlKSB7XG4gICAgZmV0Y2gobWFwLCBrZXkpLmFkZCh2YWx1ZSk7XG59XG5mdW5jdGlvbiBkZWwobWFwLCBrZXksIHZhbHVlKSB7XG4gICAgZmV0Y2gobWFwLCBrZXkpLmRlbGV0ZSh2YWx1ZSk7XG4gICAgcHJ1bmUobWFwLCBrZXkpO1xufVxuZnVuY3Rpb24gZmV0Y2gobWFwLCBrZXkpIHtcbiAgICBsZXQgdmFsdWVzID0gbWFwLmdldChrZXkpO1xuICAgIGlmICghdmFsdWVzKSB7XG4gICAgICAgIHZhbHVlcyA9IG5ldyBTZXQoKTtcbiAgICAgICAgbWFwLnNldChrZXksIHZhbHVlcyk7XG4gICAgfVxuICAgIHJldHVybiB2YWx1ZXM7XG59XG5mdW5jdGlvbiBwcnVuZShtYXAsIGtleSkge1xuICAgIGNvbnN0IHZhbHVlcyA9IG1hcC5nZXQoa2V5KTtcbiAgICBpZiAodmFsdWVzICE9IG51bGwgJiYgdmFsdWVzLnNpemUgPT0gMCkge1xuICAgICAgICBtYXAuZGVsZXRlKGtleSk7XG4gICAgfVxufVxuXG5jbGFzcyBNdWx0aW1hcCB7XG4gICAgY29uc3RydWN0b3IoKSB7XG4gICAgICAgIHRoaXMudmFsdWVzQnlLZXkgPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIGdldCBrZXlzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLnZhbHVlc0J5S2V5LmtleXMoKSk7XG4gICAgfVxuICAgIGdldCB2YWx1ZXMoKSB7XG4gICAgICAgIGNvbnN0IHNldHMgPSBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkudmFsdWVzKCkpO1xuICAgICAgICByZXR1cm4gc2V0cy5yZWR1Y2UoKHZhbHVlcywgc2V0KSA9PiB2YWx1ZXMuY29uY2F0KEFycmF5LmZyb20oc2V0KSksIFtdKTtcbiAgICB9XG4gICAgZ2V0IHNpemUoKSB7XG4gICAgICAgIGNvbnN0IHNldHMgPSBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkudmFsdWVzKCkpO1xuICAgICAgICByZXR1cm4gc2V0cy5yZWR1Y2UoKHNpemUsIHNldCkgPT4gc2l6ZSArIHNldC5zaXplLCAwKTtcbiAgICB9XG4gICAgYWRkKGtleSwgdmFsdWUpIHtcbiAgICAgICAgYWRkKHRoaXMudmFsdWVzQnlLZXksIGtleSwgdmFsdWUpO1xuICAgIH1cbiAgICBkZWxldGUoa2V5LCB2YWx1ZSkge1xuICAgICAgICBkZWwodGhpcy52YWx1ZXNCeUtleSwga2V5LCB2YWx1ZSk7XG4gICAgfVxuICAgIGhhcyhrZXksIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IHZhbHVlcyA9IHRoaXMudmFsdWVzQnlLZXkuZ2V0KGtleSk7XG4gICAgICAgIHJldHVybiB2YWx1ZXMgIT0gbnVsbCAmJiB2YWx1ZXMuaGFzKHZhbHVlKTtcbiAgICB9XG4gICAgaGFzS2V5KGtleSkge1xuICAgICAgICByZXR1cm4gdGhpcy52YWx1ZXNCeUtleS5oYXMoa2V5KTtcbiAgICB9XG4gICAgaGFzVmFsdWUodmFsdWUpIHtcbiAgICAgICAgY29uc3Qgc2V0cyA9IEFycmF5LmZyb20odGhpcy52YWx1ZXNCeUtleS52YWx1ZXMoKSk7XG4gICAgICAgIHJldHVybiBzZXRzLnNvbWUoKHNldCkgPT4gc2V0Lmhhcyh2YWx1ZSkpO1xuICAgIH1cbiAgICBnZXRWYWx1ZXNGb3JLZXkoa2V5KSB7XG4gICAgICAgIGNvbnN0IHZhbHVlcyA9IHRoaXMudmFsdWVzQnlLZXkuZ2V0KGtleSk7XG4gICAgICAgIHJldHVybiB2YWx1ZXMgPyBBcnJheS5mcm9tKHZhbHVlcykgOiBbXTtcbiAgICB9XG4gICAgZ2V0S2V5c0ZvclZhbHVlKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMudmFsdWVzQnlLZXkpXG4gICAgICAgICAgICAuZmlsdGVyKChbX2tleSwgdmFsdWVzXSkgPT4gdmFsdWVzLmhhcyh2YWx1ZSkpXG4gICAgICAgICAgICAubWFwKChba2V5LCBfdmFsdWVzXSkgPT4ga2V5KTtcbiAgICB9XG59XG5cbmNsYXNzIEluZGV4ZWRNdWx0aW1hcCBleHRlbmRzIE11bHRpbWFwIHtcbiAgICBjb25zdHJ1Y3RvcigpIHtcbiAgICAgICAgc3VwZXIoKTtcbiAgICAgICAgdGhpcy5rZXlzQnlWYWx1ZSA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgZ2V0IHZhbHVlcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5rZXlzQnlWYWx1ZS5rZXlzKCkpO1xuICAgIH1cbiAgICBhZGQoa2V5LCB2YWx1ZSkge1xuICAgICAgICBzdXBlci5hZGQoa2V5LCB2YWx1ZSk7XG4gICAgICAgIGFkZCh0aGlzLmtleXNCeVZhbHVlLCB2YWx1ZSwga2V5KTtcbiAgICB9XG4gICAgZGVsZXRlKGtleSwgdmFsdWUpIHtcbiAgICAgICAgc3VwZXIuZGVsZXRlKGtleSwgdmFsdWUpO1xuICAgICAgICBkZWwodGhpcy5rZXlzQnlWYWx1ZSwgdmFsdWUsIGtleSk7XG4gICAgfVxuICAgIGhhc1ZhbHVlKHZhbHVlKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmtleXNCeVZhbHVlLmhhcyh2YWx1ZSk7XG4gICAgfVxuICAgIGdldEtleXNGb3JWYWx1ZSh2YWx1ZSkge1xuICAgICAgICBjb25zdCBzZXQgPSB0aGlzLmtleXNCeVZhbHVlLmdldCh2YWx1ZSk7XG4gICAgICAgIHJldHVybiBzZXQgPyBBcnJheS5mcm9tKHNldCkgOiBbXTtcbiAgICB9XG59XG5cbmNsYXNzIFNlbGVjdG9yT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQsIHNlbGVjdG9yLCBkZWxlZ2F0ZSwgZGV0YWlscykge1xuICAgICAgICB0aGlzLl9zZWxlY3RvciA9IHNlbGVjdG9yO1xuICAgICAgICB0aGlzLmRldGFpbHMgPSBkZXRhaWxzO1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlciA9IG5ldyBFbGVtZW50T2JzZXJ2ZXIoZWxlbWVudCwgdGhpcyk7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5tYXRjaGVzQnlFbGVtZW50ID0gbmV3IE11bHRpbWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RhcnRlZDtcbiAgICB9XG4gICAgZ2V0IHNlbGVjdG9yKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5fc2VsZWN0b3I7XG4gICAgfVxuICAgIHNldCBzZWxlY3RvcihzZWxlY3Rvcikge1xuICAgICAgICB0aGlzLl9zZWxlY3RvciA9IHNlbGVjdG9yO1xuICAgICAgICB0aGlzLnJlZnJlc2goKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHBhdXNlKGNhbGxiYWNrKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudE9ic2VydmVyLnBhdXNlKGNhbGxiYWNrKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50T2JzZXJ2ZXIuc3RvcCgpO1xuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLmVsZW1lbnRPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50T2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgeyBzZWxlY3RvciB9ID0gdGhpcztcbiAgICAgICAgaWYgKHNlbGVjdG9yKSB7XG4gICAgICAgICAgICBjb25zdCBtYXRjaGVzID0gZWxlbWVudC5tYXRjaGVzKHNlbGVjdG9yKTtcbiAgICAgICAgICAgIGlmICh0aGlzLmRlbGVnYXRlLnNlbGVjdG9yTWF0Y2hFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIG1hdGNoZXMgJiYgdGhpcy5kZWxlZ2F0ZS5zZWxlY3Rvck1hdGNoRWxlbWVudChlbGVtZW50LCB0aGlzLmRldGFpbHMpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgcmV0dXJuIG1hdGNoZXM7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgbWF0Y2hFbGVtZW50c0luVHJlZSh0cmVlKSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2ggPSB0aGlzLm1hdGNoRWxlbWVudCh0cmVlKSA/IFt0cmVlXSA6IFtdO1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlcyA9IEFycmF5LmZyb20odHJlZS5xdWVyeVNlbGVjdG9yQWxsKHNlbGVjdG9yKSkuZmlsdGVyKChtYXRjaCkgPT4gdGhpcy5tYXRjaEVsZW1lbnQobWF0Y2gpKTtcbiAgICAgICAgICAgIHJldHVybiBtYXRjaC5jb25jYXQobWF0Y2hlcyk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXR1cm4gW107XG4gICAgICAgIH1cbiAgICB9XG4gICAgZWxlbWVudE1hdGNoZWQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCB7IHNlbGVjdG9yIH0gPSB0aGlzO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkKGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3JzID0gdGhpcy5tYXRjaGVzQnlFbGVtZW50LmdldEtleXNGb3JWYWx1ZShlbGVtZW50KTtcbiAgICAgICAgZm9yIChjb25zdCBzZWxlY3RvciBvZiBzZWxlY3RvcnMpIHtcbiAgICAgICAgICAgIHRoaXMuc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRBdHRyaWJ1dGVDaGFuZ2VkKGVsZW1lbnQsIF9hdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IHsgc2VsZWN0b3IgfSA9IHRoaXM7XG4gICAgICAgIGlmIChzZWxlY3Rvcikge1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlcyA9IHRoaXMubWF0Y2hFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICAgICAgY29uc3QgbWF0Y2hlZEJlZm9yZSA9IHRoaXMubWF0Y2hlc0J5RWxlbWVudC5oYXMoc2VsZWN0b3IsIGVsZW1lbnQpO1xuICAgICAgICAgICAgaWYgKG1hdGNoZXMgJiYgIW1hdGNoZWRCZWZvcmUpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnNlbGVjdG9yTWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIGlmICghbWF0Y2hlcyAmJiBtYXRjaGVkQmVmb3JlKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3Rvcik7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUuc2VsZWN0b3JNYXRjaGVkKGVsZW1lbnQsIHNlbGVjdG9yLCB0aGlzLmRldGFpbHMpO1xuICAgICAgICB0aGlzLm1hdGNoZXNCeUVsZW1lbnQuYWRkKHNlbGVjdG9yLCBlbGVtZW50KTtcbiAgICB9XG4gICAgc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgc2VsZWN0b3IpIHtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zZWxlY3RvclVubWF0Y2hlZChlbGVtZW50LCBzZWxlY3RvciwgdGhpcy5kZXRhaWxzKTtcbiAgICAgICAgdGhpcy5tYXRjaGVzQnlFbGVtZW50LmRlbGV0ZShzZWxlY3RvciwgZWxlbWVudCk7XG4gICAgfVxufVxuXG5jbGFzcyBTdHJpbmdNYXBPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoZWxlbWVudCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5lbGVtZW50ID0gZWxlbWVudDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLnN0YXJ0ZWQgPSBmYWxzZTtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXAgPSBuZXcgTWFwKCk7XG4gICAgICAgIHRoaXMubXV0YXRpb25PYnNlcnZlciA9IG5ldyBNdXRhdGlvbk9ic2VydmVyKChtdXRhdGlvbnMpID0+IHRoaXMucHJvY2Vzc011dGF0aW9ucyhtdXRhdGlvbnMpKTtcbiAgICB9XG4gICAgc3RhcnQoKSB7XG4gICAgICAgIGlmICghdGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICB0aGlzLnN0YXJ0ZWQgPSB0cnVlO1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLm9ic2VydmUodGhpcy5lbGVtZW50LCB7IGF0dHJpYnV0ZXM6IHRydWUsIGF0dHJpYnV0ZU9sZFZhbHVlOiB0cnVlIH0pO1xuICAgICAgICAgICAgdGhpcy5yZWZyZXNoKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5tdXRhdGlvbk9ic2VydmVyLnRha2VSZWNvcmRzKCk7XG4gICAgICAgICAgICB0aGlzLm11dGF0aW9uT2JzZXJ2ZXIuZGlzY29ubmVjdCgpO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgZm9yIChjb25zdCBhdHRyaWJ1dGVOYW1lIG9mIHRoaXMua25vd25BdHRyaWJ1dGVOYW1lcykge1xuICAgICAgICAgICAgICAgIHRoaXMucmVmcmVzaEF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lLCBudWxsKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzTXV0YXRpb25zKG11dGF0aW9ucykge1xuICAgICAgICBpZiAodGhpcy5zdGFydGVkKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IG11dGF0aW9uIG9mIG11dGF0aW9ucykge1xuICAgICAgICAgICAgICAgIHRoaXMucHJvY2Vzc011dGF0aW9uKG11dGF0aW9uKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9jZXNzTXV0YXRpb24obXV0YXRpb24pIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IG11dGF0aW9uLmF0dHJpYnV0ZU5hbWU7XG4gICAgICAgIGlmIChhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgICAgICB0aGlzLnJlZnJlc2hBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSwgbXV0YXRpb24ub2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2hBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3Qga2V5ID0gdGhpcy5kZWxlZ2F0ZS5nZXRTdHJpbmdNYXBLZXlGb3JBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgIGlmIChrZXkgIT0gbnVsbCkge1xuICAgICAgICAgICAgaWYgKCF0aGlzLnN0cmluZ01hcC5oYXMoYXR0cmlidXRlTmFtZSkpIHtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjb25zdCB2YWx1ZSA9IHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICBpZiAodGhpcy5zdHJpbmdNYXAuZ2V0KGF0dHJpYnV0ZU5hbWUpICE9IHZhbHVlKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5zdHJpbmdNYXBWYWx1ZUNoYW5nZWQodmFsdWUsIGtleSwgb2xkVmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICAgICAgaWYgKHZhbHVlID09IG51bGwpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvbGRWYWx1ZSA9IHRoaXMuc3RyaW5nTWFwLmdldChhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcC5kZWxldGUoYXR0cmlidXRlTmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG9sZFZhbHVlKVxuICAgICAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICB0aGlzLnN0cmluZ01hcC5zZXQoYXR0cmlidXRlTmFtZSwgdmFsdWUpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcEtleUFkZGVkKGtleSwgYXR0cmlidXRlTmFtZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlBZGRlZCkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlBZGRlZChrZXksIGF0dHJpYnV0ZU5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwga2V5LCBvbGRWYWx1ZSkge1xuICAgICAgICBpZiAodGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBWYWx1ZUNoYW5nZWQpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwVmFsdWVDaGFuZ2VkKHZhbHVlLCBrZXksIG9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBLZXlSZW1vdmVkKGtleSwgYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgaWYgKHRoaXMuZGVsZWdhdGUuc3RyaW5nTWFwS2V5UmVtb3ZlZCkge1xuICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zdHJpbmdNYXBLZXlSZW1vdmVkKGtleSwgYXR0cmlidXRlTmFtZSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGdldCBrbm93bkF0dHJpYnV0ZU5hbWVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbShuZXcgU2V0KHRoaXMuY3VycmVudEF0dHJpYnV0ZU5hbWVzLmNvbmNhdCh0aGlzLnJlY29yZGVkQXR0cmlidXRlTmFtZXMpKSk7XG4gICAgfVxuICAgIGdldCBjdXJyZW50QXR0cmlidXRlTmFtZXMoKSB7XG4gICAgICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMuZWxlbWVudC5hdHRyaWJ1dGVzKS5tYXAoKGF0dHJpYnV0ZSkgPT4gYXR0cmlidXRlLm5hbWUpO1xuICAgIH1cbiAgICBnZXQgcmVjb3JkZWRBdHRyaWJ1dGVOYW1lcygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5zdHJpbmdNYXAua2V5cygpKTtcbiAgICB9XG59XG5cbmNsYXNzIFRva2VuTGlzdE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyID0gbmV3IEF0dHJpYnV0ZU9ic2VydmVyKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudG9rZW5zQnlFbGVtZW50ID0gbmV3IE11bHRpbWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBwYXVzZShjYWxsYmFjaykge1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLnBhdXNlKGNhbGxiYWNrKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIucmVmcmVzaCgpO1xuICAgIH1cbiAgICBnZXQgZWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXIuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGF0dHJpYnV0ZU5hbWUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyLmF0dHJpYnV0ZU5hbWU7XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKGVsZW1lbnQpIHtcbiAgICAgICAgdGhpcy50b2tlbnNNYXRjaGVkKHRoaXMucmVhZFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCkpO1xuICAgIH1cbiAgICBlbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKGVsZW1lbnQpIHtcbiAgICAgICAgY29uc3QgW3VubWF0Y2hlZFRva2VucywgbWF0Y2hlZFRva2Vuc10gPSB0aGlzLnJlZnJlc2hUb2tlbnNGb3JFbGVtZW50KGVsZW1lbnQpO1xuICAgICAgICB0aGlzLnRva2Vuc1VubWF0Y2hlZCh1bm1hdGNoZWRUb2tlbnMpO1xuICAgICAgICB0aGlzLnRva2Vuc01hdGNoZWQobWF0Y2hlZFRva2Vucyk7XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRBdHRyaWJ1dGUoZWxlbWVudCkge1xuICAgICAgICB0aGlzLnRva2Vuc1VubWF0Y2hlZCh0aGlzLnRva2Vuc0J5RWxlbWVudC5nZXRWYWx1ZXNGb3JLZXkoZWxlbWVudCkpO1xuICAgIH1cbiAgICB0b2tlbnNNYXRjaGVkKHRva2Vucykge1xuICAgICAgICB0b2tlbnMuZm9yRWFjaCgodG9rZW4pID0+IHRoaXMudG9rZW5NYXRjaGVkKHRva2VuKSk7XG4gICAgfVxuICAgIHRva2Vuc1VubWF0Y2hlZCh0b2tlbnMpIHtcbiAgICAgICAgdG9rZW5zLmZvckVhY2goKHRva2VuKSA9PiB0aGlzLnRva2VuVW5tYXRjaGVkKHRva2VuKSk7XG4gICAgfVxuICAgIHRva2VuTWF0Y2hlZCh0b2tlbikge1xuICAgICAgICB0aGlzLmRlbGVnYXRlLnRva2VuTWF0Y2hlZCh0b2tlbik7XG4gICAgICAgIHRoaXMudG9rZW5zQnlFbGVtZW50LmFkZCh0b2tlbi5lbGVtZW50LCB0b2tlbik7XG4gICAgfVxuICAgIHRva2VuVW5tYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUudG9rZW5Vbm1hdGNoZWQodG9rZW4pO1xuICAgICAgICB0aGlzLnRva2Vuc0J5RWxlbWVudC5kZWxldGUodG9rZW4uZWxlbWVudCwgdG9rZW4pO1xuICAgIH1cbiAgICByZWZyZXNoVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KSB7XG4gICAgICAgIGNvbnN0IHByZXZpb3VzVG9rZW5zID0gdGhpcy50b2tlbnNCeUVsZW1lbnQuZ2V0VmFsdWVzRm9yS2V5KGVsZW1lbnQpO1xuICAgICAgICBjb25zdCBjdXJyZW50VG9rZW5zID0gdGhpcy5yZWFkVG9rZW5zRm9yRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgY29uc3QgZmlyc3REaWZmZXJpbmdJbmRleCA9IHppcChwcmV2aW91c1Rva2VucywgY3VycmVudFRva2VucykuZmluZEluZGV4KChbcHJldmlvdXNUb2tlbiwgY3VycmVudFRva2VuXSkgPT4gIXRva2Vuc0FyZUVxdWFsKHByZXZpb3VzVG9rZW4sIGN1cnJlbnRUb2tlbikpO1xuICAgICAgICBpZiAoZmlyc3REaWZmZXJpbmdJbmRleCA9PSAtMSkge1xuICAgICAgICAgICAgcmV0dXJuIFtbXSwgW11dO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIFtwcmV2aW91c1Rva2Vucy5zbGljZShmaXJzdERpZmZlcmluZ0luZGV4KSwgY3VycmVudFRva2Vucy5zbGljZShmaXJzdERpZmZlcmluZ0luZGV4KV07XG4gICAgICAgIH1cbiAgICB9XG4gICAgcmVhZFRva2Vuc0ZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5hdHRyaWJ1dGVOYW1lO1xuICAgICAgICBjb25zdCB0b2tlblN0cmluZyA9IGVsZW1lbnQuZ2V0QXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpIHx8IFwiXCI7XG4gICAgICAgIHJldHVybiBwYXJzZVRva2VuU3RyaW5nKHRva2VuU3RyaW5nLCBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKTtcbiAgICB9XG59XG5mdW5jdGlvbiBwYXJzZVRva2VuU3RyaW5nKHRva2VuU3RyaW5nLCBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgcmV0dXJuIHRva2VuU3RyaW5nXG4gICAgICAgIC50cmltKClcbiAgICAgICAgLnNwbGl0KC9cXHMrLylcbiAgICAgICAgLmZpbHRlcigoY29udGVudCkgPT4gY29udGVudC5sZW5ndGgpXG4gICAgICAgIC5tYXAoKGNvbnRlbnQsIGluZGV4KSA9PiAoeyBlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBjb250ZW50LCBpbmRleCB9KSk7XG59XG5mdW5jdGlvbiB6aXAobGVmdCwgcmlnaHQpIHtcbiAgICBjb25zdCBsZW5ndGggPSBNYXRoLm1heChsZWZ0Lmxlbmd0aCwgcmlnaHQubGVuZ3RoKTtcbiAgICByZXR1cm4gQXJyYXkuZnJvbSh7IGxlbmd0aCB9LCAoXywgaW5kZXgpID0+IFtsZWZ0W2luZGV4XSwgcmlnaHRbaW5kZXhdXSk7XG59XG5mdW5jdGlvbiB0b2tlbnNBcmVFcXVhbChsZWZ0LCByaWdodCkge1xuICAgIHJldHVybiBsZWZ0ICYmIHJpZ2h0ICYmIGxlZnQuaW5kZXggPT0gcmlnaHQuaW5kZXggJiYgbGVmdC5jb250ZW50ID09IHJpZ2h0LmNvbnRlbnQ7XG59XG5cbmNsYXNzIFZhbHVlTGlzdE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBhdHRyaWJ1dGVOYW1lLCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyID0gbmV3IFRva2VuTGlzdE9ic2VydmVyKGVsZW1lbnQsIGF0dHJpYnV0ZU5hbWUsIHRoaXMpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMucGFyc2VSZXN1bHRzQnlUb2tlbiA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudCA9IG5ldyBXZWFrTWFwKCk7XG4gICAgfVxuICAgIGdldCBzdGFydGVkKCkge1xuICAgICAgICByZXR1cm4gdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdGFydGVkO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgcmVmcmVzaCgpIHtcbiAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5yZWZyZXNoKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy50b2tlbkxpc3RPYnNlcnZlci5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgYXR0cmlidXRlTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuYXR0cmlidXRlTmFtZTtcbiAgICB9XG4gICAgdG9rZW5NYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdG9rZW47XG4gICAgICAgIGNvbnN0IHsgdmFsdWUgfSA9IHRoaXMuZmV0Y2hQYXJzZVJlc3VsdEZvclRva2VuKHRva2VuKTtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICB0aGlzLmZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkuc2V0KHRva2VuLCB2YWx1ZSk7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmVsZW1lbnRNYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHRva2VuVW5tYXRjaGVkKHRva2VuKSB7XG4gICAgICAgIGNvbnN0IHsgZWxlbWVudCB9ID0gdG9rZW47XG4gICAgICAgIGNvbnN0IHsgdmFsdWUgfSA9IHRoaXMuZmV0Y2hQYXJzZVJlc3VsdEZvclRva2VuKHRva2VuKTtcbiAgICAgICAgaWYgKHZhbHVlKSB7XG4gICAgICAgICAgICB0aGlzLmZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkuZGVsZXRlKHRva2VuKTtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuZWxlbWVudFVubWF0Y2hlZFZhbHVlKGVsZW1lbnQsIHZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBmZXRjaFBhcnNlUmVzdWx0Rm9yVG9rZW4odG9rZW4pIHtcbiAgICAgICAgbGV0IHBhcnNlUmVzdWx0ID0gdGhpcy5wYXJzZVJlc3VsdHNCeVRva2VuLmdldCh0b2tlbik7XG4gICAgICAgIGlmICghcGFyc2VSZXN1bHQpIHtcbiAgICAgICAgICAgIHBhcnNlUmVzdWx0ID0gdGhpcy5wYXJzZVRva2VuKHRva2VuKTtcbiAgICAgICAgICAgIHRoaXMucGFyc2VSZXN1bHRzQnlUb2tlbi5zZXQodG9rZW4sIHBhcnNlUmVzdWx0KTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gcGFyc2VSZXN1bHQ7XG4gICAgfVxuICAgIGZldGNoVmFsdWVzQnlUb2tlbkZvckVsZW1lbnQoZWxlbWVudCkge1xuICAgICAgICBsZXQgdmFsdWVzQnlUb2tlbiA9IHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudC5nZXQoZWxlbWVudCk7XG4gICAgICAgIGlmICghdmFsdWVzQnlUb2tlbikge1xuICAgICAgICAgICAgdmFsdWVzQnlUb2tlbiA9IG5ldyBNYXAoKTtcbiAgICAgICAgICAgIHRoaXMudmFsdWVzQnlUb2tlbkJ5RWxlbWVudC5zZXQoZWxlbWVudCwgdmFsdWVzQnlUb2tlbik7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHZhbHVlc0J5VG9rZW47XG4gICAgfVxuICAgIHBhcnNlVG9rZW4odG9rZW4pIHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gdGhpcy5kZWxlZ2F0ZS5wYXJzZVZhbHVlRm9yVG9rZW4odG9rZW4pO1xuICAgICAgICAgICAgcmV0dXJuIHsgdmFsdWUgfTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHJldHVybiB7IGVycm9yIH07XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmNsYXNzIEJpbmRpbmdPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICAgICAgdGhpcy5kZWxlZ2F0ZSA9IGRlbGVnYXRlO1xuICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24gPSBuZXcgTWFwKCk7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICBpZiAoIXRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIgPSBuZXcgVmFsdWVMaXN0T2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLmFjdGlvbkF0dHJpYnV0ZSwgdGhpcyk7XG4gICAgICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIpIHtcbiAgICAgICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICAgICAgZGVsZXRlIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXI7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBbGxBY3Rpb25zKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuZWxlbWVudDtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IGFjdGlvbkF0dHJpYnV0ZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmFjdGlvbkF0dHJpYnV0ZTtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY2hlbWE7XG4gICAgfVxuICAgIGdldCBiaW5kaW5ncygpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5iaW5kaW5nc0J5QWN0aW9uLnZhbHVlcygpKTtcbiAgICB9XG4gICAgY29ubmVjdEFjdGlvbihhY3Rpb24pIHtcbiAgICAgICAgY29uc3QgYmluZGluZyA9IG5ldyBCaW5kaW5nKHRoaXMuY29udGV4dCwgYWN0aW9uKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nc0J5QWN0aW9uLnNldChhY3Rpb24sIGJpbmRpbmcpO1xuICAgICAgICB0aGlzLmRlbGVnYXRlLmJpbmRpbmdDb25uZWN0ZWQoYmluZGluZyk7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RBY3Rpb24oYWN0aW9uKSB7XG4gICAgICAgIGNvbnN0IGJpbmRpbmcgPSB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uZ2V0KGFjdGlvbik7XG4gICAgICAgIGlmIChiaW5kaW5nKSB7XG4gICAgICAgICAgICB0aGlzLmJpbmRpbmdzQnlBY3Rpb24uZGVsZXRlKGFjdGlvbik7XG4gICAgICAgICAgICB0aGlzLmRlbGVnYXRlLmJpbmRpbmdEaXNjb25uZWN0ZWQoYmluZGluZyk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZGlzY29ubmVjdEFsbEFjdGlvbnMoKSB7XG4gICAgICAgIHRoaXMuYmluZGluZ3MuZm9yRWFjaCgoYmluZGluZykgPT4gdGhpcy5kZWxlZ2F0ZS5iaW5kaW5nRGlzY29ubmVjdGVkKGJpbmRpbmcsIHRydWUpKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nc0J5QWN0aW9uLmNsZWFyKCk7XG4gICAgfVxuICAgIHBhcnNlVmFsdWVGb3JUb2tlbih0b2tlbikge1xuICAgICAgICBjb25zdCBhY3Rpb24gPSBBY3Rpb24uZm9yVG9rZW4odG9rZW4sIHRoaXMuc2NoZW1hKTtcbiAgICAgICAgaWYgKGFjdGlvbi5pZGVudGlmaWVyID09IHRoaXMuaWRlbnRpZmllcikge1xuICAgICAgICAgICAgcmV0dXJuIGFjdGlvbjtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50TWF0Y2hlZFZhbHVlKGVsZW1lbnQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmNvbm5lY3RBY3Rpb24oYWN0aW9uKTtcbiAgICB9XG4gICAgZWxlbWVudFVubWF0Y2hlZFZhbHVlKGVsZW1lbnQsIGFjdGlvbikge1xuICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBY3Rpb24oYWN0aW9uKTtcbiAgICB9XG59XG5cbmNsYXNzIFZhbHVlT2JzZXJ2ZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQsIHJlY2VpdmVyKSB7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMucmVjZWl2ZXIgPSByZWNlaXZlcjtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXBPYnNlcnZlciA9IG5ldyBTdHJpbmdNYXBPYnNlcnZlcih0aGlzLmVsZW1lbnQsIHRoaXMpO1xuICAgICAgICB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcCA9IHRoaXMuY29udHJvbGxlci52YWx1ZURlc2NyaXB0b3JNYXA7XG4gICAgfVxuICAgIHN0YXJ0KCkge1xuICAgICAgICB0aGlzLnN0cmluZ01hcE9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrc0ZvckRlZmF1bHRWYWx1ZXMoKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgdGhpcy5zdHJpbmdNYXBPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmNvbnRyb2xsZXI7XG4gICAgfVxuICAgIGdldFN0cmluZ01hcEtleUZvckF0dHJpYnV0ZShhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGlmIChhdHRyaWJ1dGVOYW1lIGluIHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwKSB7XG4gICAgICAgICAgICByZXR1cm4gdGhpcy52YWx1ZURlc2NyaXB0b3JNYXBbYXR0cmlidXRlTmFtZV0ubmFtZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdHJpbmdNYXBLZXlBZGRlZChrZXksIGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwW2F0dHJpYnV0ZU5hbWVdO1xuICAgICAgICBpZiAoIXRoaXMuaGFzVmFsdWUoa2V5KSkge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2soa2V5LCBkZXNjcmlwdG9yLndyaXRlcih0aGlzLnJlY2VpdmVyW2tleV0pLCBkZXNjcmlwdG9yLndyaXRlcihkZXNjcmlwdG9yLmRlZmF1bHRWYWx1ZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHN0cmluZ01hcFZhbHVlQ2hhbmdlZCh2YWx1ZSwgbmFtZSwgb2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFtuYW1lXTtcbiAgICAgICAgaWYgKHZhbHVlID09PSBudWxsKVxuICAgICAgICAgICAgcmV0dXJuO1xuICAgICAgICBpZiAob2xkVmFsdWUgPT09IG51bGwpIHtcbiAgICAgICAgICAgIG9sZFZhbHVlID0gZGVzY3JpcHRvci53cml0ZXIoZGVzY3JpcHRvci5kZWZhdWx0VmFsdWUpO1xuICAgICAgICB9XG4gICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKG5hbWUsIHZhbHVlLCBvbGRWYWx1ZSk7XG4gICAgfVxuICAgIHN0cmluZ01hcEtleVJlbW92ZWQoa2V5LCBhdHRyaWJ1dGVOYW1lLCBvbGRWYWx1ZSkge1xuICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gdGhpcy52YWx1ZURlc2NyaXB0b3JOYW1lTWFwW2tleV07XG4gICAgICAgIGlmICh0aGlzLmhhc1ZhbHVlKGtleSkpIHtcbiAgICAgICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKGtleSwgZGVzY3JpcHRvci53cml0ZXIodGhpcy5yZWNlaXZlcltrZXldKSwgb2xkVmFsdWUpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgdGhpcy5pbnZva2VDaGFuZ2VkQ2FsbGJhY2soa2V5LCBkZXNjcmlwdG9yLndyaXRlcihkZXNjcmlwdG9yLmRlZmF1bHRWYWx1ZSksIG9sZFZhbHVlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBpbnZva2VDaGFuZ2VkQ2FsbGJhY2tzRm9yRGVmYXVsdFZhbHVlcygpIHtcbiAgICAgICAgZm9yIChjb25zdCB7IGtleSwgbmFtZSwgZGVmYXVsdFZhbHVlLCB3cml0ZXIgfSBvZiB0aGlzLnZhbHVlRGVzY3JpcHRvcnMpIHtcbiAgICAgICAgICAgIGlmIChkZWZhdWx0VmFsdWUgIT0gdW5kZWZpbmVkICYmICF0aGlzLmNvbnRyb2xsZXIuZGF0YS5oYXMoa2V5KSkge1xuICAgICAgICAgICAgICAgIHRoaXMuaW52b2tlQ2hhbmdlZENhbGxiYWNrKG5hbWUsIHdyaXRlcihkZWZhdWx0VmFsdWUpLCB1bmRlZmluZWQpO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGludm9rZUNoYW5nZWRDYWxsYmFjayhuYW1lLCByYXdWYWx1ZSwgcmF3T2xkVmFsdWUpIHtcbiAgICAgICAgY29uc3QgY2hhbmdlZE1ldGhvZE5hbWUgPSBgJHtuYW1lfUNoYW5nZWRgO1xuICAgICAgICBjb25zdCBjaGFuZ2VkTWV0aG9kID0gdGhpcy5yZWNlaXZlcltjaGFuZ2VkTWV0aG9kTmFtZV07XG4gICAgICAgIGlmICh0eXBlb2YgY2hhbmdlZE1ldGhvZCA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck5hbWVNYXBbbmFtZV07XG4gICAgICAgICAgICB0cnkge1xuICAgICAgICAgICAgICAgIGNvbnN0IHZhbHVlID0gZGVzY3JpcHRvci5yZWFkZXIocmF3VmFsdWUpO1xuICAgICAgICAgICAgICAgIGxldCBvbGRWYWx1ZSA9IHJhd09sZFZhbHVlO1xuICAgICAgICAgICAgICAgIGlmIChyYXdPbGRWYWx1ZSkge1xuICAgICAgICAgICAgICAgICAgICBvbGRWYWx1ZSA9IGRlc2NyaXB0b3IucmVhZGVyKHJhd09sZFZhbHVlKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgY2hhbmdlZE1ldGhvZC5jYWxsKHRoaXMucmVjZWl2ZXIsIHZhbHVlLCBvbGRWYWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgICAgICBpZiAoZXJyb3IgaW5zdGFuY2VvZiBUeXBlRXJyb3IpIHtcbiAgICAgICAgICAgICAgICAgICAgZXJyb3IubWVzc2FnZSA9IGBTdGltdWx1cyBWYWx1ZSBcIiR7dGhpcy5jb250ZXh0LmlkZW50aWZpZXJ9LiR7ZGVzY3JpcHRvci5uYW1lfVwiIC0gJHtlcnJvci5tZXNzYWdlfWA7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHRocm93IGVycm9yO1xuICAgICAgICAgICAgfVxuICAgICAgICB9XG4gICAgfVxuICAgIGdldCB2YWx1ZURlc2NyaXB0b3JzKCkge1xuICAgICAgICBjb25zdCB7IHZhbHVlRGVzY3JpcHRvck1hcCB9ID0gdGhpcztcbiAgICAgICAgcmV0dXJuIE9iamVjdC5rZXlzKHZhbHVlRGVzY3JpcHRvck1hcCkubWFwKChrZXkpID0+IHZhbHVlRGVzY3JpcHRvck1hcFtrZXldKTtcbiAgICB9XG4gICAgZ2V0IHZhbHVlRGVzY3JpcHRvck5hbWVNYXAoKSB7XG4gICAgICAgIGNvbnN0IGRlc2NyaXB0b3JzID0ge307XG4gICAgICAgIE9iamVjdC5rZXlzKHRoaXMudmFsdWVEZXNjcmlwdG9yTWFwKS5mb3JFYWNoKChrZXkpID0+IHtcbiAgICAgICAgICAgIGNvbnN0IGRlc2NyaXB0b3IgPSB0aGlzLnZhbHVlRGVzY3JpcHRvck1hcFtrZXldO1xuICAgICAgICAgICAgZGVzY3JpcHRvcnNbZGVzY3JpcHRvci5uYW1lXSA9IGRlc2NyaXB0b3I7XG4gICAgICAgIH0pO1xuICAgICAgICByZXR1cm4gZGVzY3JpcHRvcnM7XG4gICAgfVxuICAgIGhhc1ZhbHVlKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IHRoaXMudmFsdWVEZXNjcmlwdG9yTmFtZU1hcFthdHRyaWJ1dGVOYW1lXTtcbiAgICAgICAgY29uc3QgaGFzTWV0aG9kTmFtZSA9IGBoYXMke2NhcGl0YWxpemUoZGVzY3JpcHRvci5uYW1lKX1gO1xuICAgICAgICByZXR1cm4gdGhpcy5yZWNlaXZlcltoYXNNZXRob2ROYW1lXTtcbiAgICB9XG59XG5cbmNsYXNzIFRhcmdldE9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3Rvcihjb250ZXh0LCBkZWxlZ2F0ZSkge1xuICAgICAgICB0aGlzLmNvbnRleHQgPSBjb250ZXh0O1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudGFyZ2V0c0J5TmFtZSA9IG5ldyBNdWx0aW1hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnRva2VuTGlzdE9ic2VydmVyKSB7XG4gICAgICAgICAgICB0aGlzLnRva2VuTGlzdE9ic2VydmVyID0gbmV3IFRva2VuTGlzdE9ic2VydmVyKHRoaXMuZWxlbWVudCwgdGhpcy5hdHRyaWJ1dGVOYW1lLCB0aGlzKTtcbiAgICAgICAgICAgIHRoaXMudG9rZW5MaXN0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICBpZiAodGhpcy50b2tlbkxpc3RPYnNlcnZlcikge1xuICAgICAgICAgICAgdGhpcy5kaXNjb25uZWN0QWxsVGFyZ2V0cygpO1xuICAgICAgICAgICAgdGhpcy50b2tlbkxpc3RPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgICAgICBkZWxldGUgdGhpcy50b2tlbkxpc3RPYnNlcnZlcjtcbiAgICAgICAgfVxuICAgIH1cbiAgICB0b2tlbk1hdGNoZWQoeyBlbGVtZW50LCBjb250ZW50OiBuYW1lIH0pIHtcbiAgICAgICAgaWYgKHRoaXMuc2NvcGUuY29udGFpbnNFbGVtZW50KGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLmNvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgdG9rZW5Vbm1hdGNoZWQoeyBlbGVtZW50LCBjb250ZW50OiBuYW1lIH0pIHtcbiAgICAgICAgdGhpcy5kaXNjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpO1xuICAgIH1cbiAgICBjb25uZWN0VGFyZ2V0KGVsZW1lbnQsIG5hbWUpIHtcbiAgICAgICAgdmFyIF9hO1xuICAgICAgICBpZiAoIXRoaXMudGFyZ2V0c0J5TmFtZS5oYXMobmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMudGFyZ2V0c0J5TmFtZS5hZGQobmFtZSwgZWxlbWVudCk7XG4gICAgICAgICAgICAoX2EgPSB0aGlzLnRva2VuTGlzdE9ic2VydmVyKSA9PT0gbnVsbCB8fCBfYSA9PT0gdm9pZCAwID8gdm9pZCAwIDogX2EucGF1c2UoKCkgPT4gdGhpcy5kZWxlZ2F0ZS50YXJnZXRDb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RUYXJnZXQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICh0aGlzLnRhcmdldHNCeU5hbWUuaGFzKG5hbWUsIGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLnRhcmdldHNCeU5hbWUuZGVsZXRlKG5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy50b2tlbkxpc3RPYnNlcnZlcikgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUudGFyZ2V0RGlzY29ubmVjdGVkKGVsZW1lbnQsIG5hbWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0QWxsVGFyZ2V0cygpIHtcbiAgICAgICAgZm9yIChjb25zdCBuYW1lIG9mIHRoaXMudGFyZ2V0c0J5TmFtZS5rZXlzKSB7XG4gICAgICAgICAgICBmb3IgKGNvbnN0IGVsZW1lbnQgb2YgdGhpcy50YXJnZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShuYW1lKSkge1xuICAgICAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdFRhcmdldChlbGVtZW50LCBuYW1lKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXQgYXR0cmlidXRlTmFtZSgpIHtcbiAgICAgICAgcmV0dXJuIGBkYXRhLSR7dGhpcy5jb250ZXh0LmlkZW50aWZpZXJ9LXRhcmdldGA7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzY29wZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udGV4dC5zY29wZTtcbiAgICB9XG59XG5cbmZ1bmN0aW9uIHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBhbmNlc3RvcnMgPSBnZXRBbmNlc3RvcnNGb3JDb25zdHJ1Y3Rvcihjb25zdHJ1Y3Rvcik7XG4gICAgcmV0dXJuIEFycmF5LmZyb20oYW5jZXN0b3JzLnJlZHVjZSgodmFsdWVzLCBjb25zdHJ1Y3RvcikgPT4ge1xuICAgICAgICBnZXRPd25TdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgcHJvcGVydHlOYW1lKS5mb3JFYWNoKChuYW1lKSA9PiB2YWx1ZXMuYWRkKG5hbWUpKTtcbiAgICAgICAgcmV0dXJuIHZhbHVlcztcbiAgICB9LCBuZXcgU2V0KCkpKTtcbn1cbmZ1bmN0aW9uIHJlYWRJbmhlcml0YWJsZVN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBhbmNlc3RvcnMgPSBnZXRBbmNlc3RvcnNGb3JDb25zdHJ1Y3Rvcihjb25zdHJ1Y3Rvcik7XG4gICAgcmV0dXJuIGFuY2VzdG9ycy5yZWR1Y2UoKHBhaXJzLCBjb25zdHJ1Y3RvcikgPT4ge1xuICAgICAgICBwYWlycy5wdXNoKC4uLmdldE93blN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpKTtcbiAgICAgICAgcmV0dXJuIHBhaXJzO1xuICAgIH0sIFtdKTtcbn1cbmZ1bmN0aW9uIGdldEFuY2VzdG9yc0ZvckNvbnN0cnVjdG9yKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgYW5jZXN0b3JzID0gW107XG4gICAgd2hpbGUgKGNvbnN0cnVjdG9yKSB7XG4gICAgICAgIGFuY2VzdG9ycy5wdXNoKGNvbnN0cnVjdG9yKTtcbiAgICAgICAgY29uc3RydWN0b3IgPSBPYmplY3QuZ2V0UHJvdG90eXBlT2YoY29uc3RydWN0b3IpO1xuICAgIH1cbiAgICByZXR1cm4gYW5jZXN0b3JzLnJldmVyc2UoKTtcbn1cbmZ1bmN0aW9uIGdldE93blN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBkZWZpbml0aW9uID0gY29uc3RydWN0b3JbcHJvcGVydHlOYW1lXTtcbiAgICByZXR1cm4gQXJyYXkuaXNBcnJheShkZWZpbml0aW9uKSA/IGRlZmluaXRpb24gOiBbXTtcbn1cbmZ1bmN0aW9uIGdldE93blN0YXRpY09iamVjdFBhaXJzKGNvbnN0cnVjdG9yLCBwcm9wZXJ0eU5hbWUpIHtcbiAgICBjb25zdCBkZWZpbml0aW9uID0gY29uc3RydWN0b3JbcHJvcGVydHlOYW1lXTtcbiAgICByZXR1cm4gZGVmaW5pdGlvbiA/IE9iamVjdC5rZXlzKGRlZmluaXRpb24pLm1hcCgoa2V5KSA9PiBba2V5LCBkZWZpbml0aW9uW2tleV1dKSA6IFtdO1xufVxuXG5jbGFzcyBPdXRsZXRPYnNlcnZlciB7XG4gICAgY29uc3RydWN0b3IoY29udGV4dCwgZGVsZWdhdGUpIHtcbiAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgIHRoaXMuY29udGV4dCA9IGNvbnRleHQ7XG4gICAgICAgIHRoaXMuZGVsZWdhdGUgPSBkZWxlZ2F0ZTtcbiAgICAgICAgdGhpcy5vdXRsZXRzQnlOYW1lID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUgPSBuZXcgTXVsdGltYXAoKTtcbiAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwID0gbmV3IE1hcCgpO1xuICAgICAgICB0aGlzLmF0dHJpYnV0ZU9ic2VydmVyTWFwID0gbmV3IE1hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgaWYgKCF0aGlzLnN0YXJ0ZWQpIHtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0RGVmaW5pdGlvbnMuZm9yRWFjaCgob3V0bGV0TmFtZSkgPT4ge1xuICAgICAgICAgICAgICAgIHRoaXMuc2V0dXBTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpO1xuICAgICAgICAgICAgICAgIHRoaXMuc2V0dXBBdHRyaWJ1dGVPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgICAgIH0pO1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gdHJ1ZTtcbiAgICAgICAgICAgIHRoaXMuZGVwZW5kZW50Q29udGV4dHMuZm9yRWFjaCgoY29udGV4dCkgPT4gY29udGV4dC5yZWZyZXNoKCkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHJlZnJlc2goKSB7XG4gICAgICAgIHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIucmVmcmVzaCgpKTtcbiAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIucmVmcmVzaCgpKTtcbiAgICB9XG4gICAgc3RvcCgpIHtcbiAgICAgICAgaWYgKHRoaXMuc3RhcnRlZCkge1xuICAgICAgICAgICAgdGhpcy5zdGFydGVkID0gZmFsc2U7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RBbGxPdXRsZXRzKCk7XG4gICAgICAgICAgICB0aGlzLnN0b3BTZWxlY3Rvck9ic2VydmVycygpO1xuICAgICAgICAgICAgdGhpcy5zdG9wQXR0cmlidXRlT2JzZXJ2ZXJzKCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc3RvcFNlbGVjdG9yT2JzZXJ2ZXJzKCkge1xuICAgICAgICBpZiAodGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLnNpemUgPiAwKSB7XG4gICAgICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuZm9yRWFjaCgob2JzZXJ2ZXIpID0+IG9ic2VydmVyLnN0b3AoKSk7XG4gICAgICAgICAgICB0aGlzLnNlbGVjdG9yT2JzZXJ2ZXJNYXAuY2xlYXIoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzdG9wQXR0cmlidXRlT2JzZXJ2ZXJzKCkge1xuICAgICAgICBpZiAodGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5zaXplID4gMCkge1xuICAgICAgICAgICAgdGhpcy5hdHRyaWJ1dGVPYnNlcnZlck1hcC5mb3JFYWNoKChvYnNlcnZlcikgPT4gb2JzZXJ2ZXIuc3RvcCgpKTtcbiAgICAgICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuY2xlYXIoKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBzZWxlY3Rvck1hdGNoZWQoZWxlbWVudCwgX3NlbGVjdG9yLCB7IG91dGxldE5hbWUgfSkge1xuICAgICAgICBjb25zdCBvdXRsZXQgPSB0aGlzLmdldE91dGxldChlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKG91dGxldCkge1xuICAgICAgICAgICAgdGhpcy5jb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2VsZWN0b3JVbm1hdGNoZWQoZWxlbWVudCwgX3NlbGVjdG9yLCB7IG91dGxldE5hbWUgfSkge1xuICAgICAgICBjb25zdCBvdXRsZXQgPSB0aGlzLmdldE91dGxldEZyb21NYXAoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIGlmIChvdXRsZXQpIHtcbiAgICAgICAgICAgIHRoaXMuZGlzY29ubmVjdE91dGxldChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIHNlbGVjdG9yTWF0Y2hFbGVtZW50KGVsZW1lbnQsIHsgb3V0bGV0TmFtZSB9KSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5zZWxlY3RvcihvdXRsZXROYW1lKTtcbiAgICAgICAgY29uc3QgaGFzT3V0bGV0ID0gdGhpcy5oYXNPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IGhhc091dGxldENvbnRyb2xsZXIgPSBlbGVtZW50Lm1hdGNoZXMoYFske3RoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGV9fj0ke291dGxldE5hbWV9XWApO1xuICAgICAgICBpZiAoc2VsZWN0b3IpIHtcbiAgICAgICAgICAgIHJldHVybiBoYXNPdXRsZXQgJiYgaGFzT3V0bGV0Q29udHJvbGxlciAmJiBlbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpO1xuICAgICAgICB9XG4gICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgcmV0dXJuIGZhbHNlO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkQXR0cmlidXRlKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50QXR0cmlidXRlVmFsdWVDaGFuZ2VkKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBlbGVtZW50VW5tYXRjaGVkQXR0cmlidXRlKF9lbGVtZW50LCBhdHRyaWJ1dGVOYW1lKSB7XG4gICAgICAgIGNvbnN0IG91dGxldE5hbWUgPSB0aGlzLmdldE91dGxldE5hbWVGcm9tT3V0bGV0QXR0cmlidXRlTmFtZShhdHRyaWJ1dGVOYW1lKTtcbiAgICAgICAgaWYgKG91dGxldE5hbWUpIHtcbiAgICAgICAgICAgIHRoaXMudXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBjb25uZWN0T3V0bGV0KG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICB2YXIgX2E7XG4gICAgICAgIGlmICghdGhpcy5vdXRsZXRFbGVtZW50c0J5TmFtZS5oYXMob3V0bGV0TmFtZSwgZWxlbWVudCkpIHtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0c0J5TmFtZS5hZGQob3V0bGV0TmFtZSwgb3V0bGV0KTtcbiAgICAgICAgICAgIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuYWRkKG91dGxldE5hbWUsIGVsZW1lbnQpO1xuICAgICAgICAgICAgKF9hID0gdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmdldChvdXRsZXROYW1lKSkgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLnBhdXNlKCgpID0+IHRoaXMuZGVsZWdhdGUub3V0bGV0Q29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgb3V0bGV0TmFtZSkpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGRpc2Nvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgaWYgKHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuaGFzKG91dGxldE5hbWUsIGVsZW1lbnQpKSB7XG4gICAgICAgICAgICB0aGlzLm91dGxldHNCeU5hbWUuZGVsZXRlKG91dGxldE5hbWUsIG91dGxldCk7XG4gICAgICAgICAgICB0aGlzLm91dGxldEVsZW1lbnRzQnlOYW1lLmRlbGV0ZShvdXRsZXROYW1lLCBlbGVtZW50KTtcbiAgICAgICAgICAgIChfYSA9IHRoaXMuc2VsZWN0b3JPYnNlcnZlck1hcFxuICAgICAgICAgICAgICAgIC5nZXQob3V0bGV0TmFtZSkpID09PSBudWxsIHx8IF9hID09PSB2b2lkIDAgPyB2b2lkIDAgOiBfYS5wYXVzZSgoKSA9PiB0aGlzLmRlbGVnYXRlLm91dGxldERpc2Nvbm5lY3RlZChvdXRsZXQsIGVsZW1lbnQsIG91dGxldE5hbWUpKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBkaXNjb25uZWN0QWxsT3V0bGV0cygpIHtcbiAgICAgICAgZm9yIChjb25zdCBvdXRsZXROYW1lIG9mIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUua2V5cykge1xuICAgICAgICAgICAgZm9yIChjb25zdCBlbGVtZW50IG9mIHRoaXMub3V0bGV0RWxlbWVudHNCeU5hbWUuZ2V0VmFsdWVzRm9yS2V5KG91dGxldE5hbWUpKSB7XG4gICAgICAgICAgICAgICAgZm9yIChjb25zdCBvdXRsZXQgb2YgdGhpcy5vdXRsZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShvdXRsZXROYW1lKSkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RPdXRsZXQob3V0bGV0LCBlbGVtZW50LCBvdXRsZXROYW1lKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgdXBkYXRlU2VsZWN0b3JPYnNlcnZlckZvck91dGxldChvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IG9ic2VydmVyID0gdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLmdldChvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKG9ic2VydmVyKSB7XG4gICAgICAgICAgICBvYnNlcnZlci5zZWxlY3RvciA9IHRoaXMuc2VsZWN0b3Iob3V0bGV0TmFtZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2V0dXBTZWxlY3Rvck9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLnNlbGVjdG9yKG91dGxldE5hbWUpO1xuICAgICAgICBjb25zdCBzZWxlY3Rvck9ic2VydmVyID0gbmV3IFNlbGVjdG9yT2JzZXJ2ZXIoZG9jdW1lbnQuYm9keSwgc2VsZWN0b3IsIHRoaXMsIHsgb3V0bGV0TmFtZSB9KTtcbiAgICAgICAgdGhpcy5zZWxlY3Rvck9ic2VydmVyTWFwLnNldChvdXRsZXROYW1lLCBzZWxlY3Rvck9ic2VydmVyKTtcbiAgICAgICAgc2VsZWN0b3JPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzZXR1cEF0dHJpYnV0ZU9ic2VydmVyRm9yT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuYXR0cmlidXRlTmFtZUZvck91dGxldE5hbWUob3V0bGV0TmFtZSk7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU9ic2VydmVyID0gbmV3IEF0dHJpYnV0ZU9ic2VydmVyKHRoaXMuc2NvcGUuZWxlbWVudCwgYXR0cmlidXRlTmFtZSwgdGhpcyk7XG4gICAgICAgIHRoaXMuYXR0cmlidXRlT2JzZXJ2ZXJNYXAuc2V0KG91dGxldE5hbWUsIGF0dHJpYnV0ZU9ic2VydmVyKTtcbiAgICAgICAgYXR0cmlidXRlT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICB9XG4gICAgc2VsZWN0b3Iob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5vdXRsZXRzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgYXR0cmlidXRlTmFtZUZvck91dGxldE5hbWUob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5zY2hlbWEub3V0bGV0QXR0cmlidXRlRm9yU2NvcGUodGhpcy5pZGVudGlmaWVyLCBvdXRsZXROYW1lKTtcbiAgICB9XG4gICAgZ2V0T3V0bGV0TmFtZUZyb21PdXRsZXRBdHRyaWJ1dGVOYW1lKGF0dHJpYnV0ZU5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0RGVmaW5pdGlvbnMuZmluZCgob3V0bGV0TmFtZSkgPT4gdGhpcy5hdHRyaWJ1dGVOYW1lRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKSA9PT0gYXR0cmlidXRlTmFtZSk7XG4gICAgfVxuICAgIGdldCBvdXRsZXREZXBlbmRlbmNpZXMoKSB7XG4gICAgICAgIGNvbnN0IGRlcGVuZGVuY2llcyA9IG5ldyBNdWx0aW1hcCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5tb2R1bGVzLmZvckVhY2goKG1vZHVsZSkgPT4ge1xuICAgICAgICAgICAgY29uc3QgY29uc3RydWN0b3IgPSBtb2R1bGUuZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3I7XG4gICAgICAgICAgICBjb25zdCBvdXRsZXRzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwib3V0bGV0c1wiKTtcbiAgICAgICAgICAgIG91dGxldHMuZm9yRWFjaCgob3V0bGV0KSA9PiBkZXBlbmRlbmNpZXMuYWRkKG91dGxldCwgbW9kdWxlLmlkZW50aWZpZXIpKTtcbiAgICAgICAgfSk7XG4gICAgICAgIHJldHVybiBkZXBlbmRlbmNpZXM7XG4gICAgfVxuICAgIGdldCBvdXRsZXREZWZpbml0aW9ucygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0RGVwZW5kZW5jaWVzLmdldEtleXNGb3JWYWx1ZSh0aGlzLmlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBnZXQgZGVwZW5kZW50Q29udHJvbGxlcklkZW50aWZpZXJzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXREZXBlbmRlbmNpZXMuZ2V0VmFsdWVzRm9yS2V5KHRoaXMuaWRlbnRpZmllcik7XG4gICAgfVxuICAgIGdldCBkZXBlbmRlbnRDb250ZXh0cygpIHtcbiAgICAgICAgY29uc3QgaWRlbnRpZmllcnMgPSB0aGlzLmRlcGVuZGVudENvbnRyb2xsZXJJZGVudGlmaWVycztcbiAgICAgICAgcmV0dXJuIHRoaXMucm91dGVyLmNvbnRleHRzLmZpbHRlcigoY29udGV4dCkgPT4gaWRlbnRpZmllcnMuaW5jbHVkZXMoY29udGV4dC5pZGVudGlmaWVyKSk7XG4gICAgfVxuICAgIGhhc091dGxldChlbGVtZW50LCBvdXRsZXROYW1lKSB7XG4gICAgICAgIHJldHVybiAhIXRoaXMuZ2V0T3V0bGV0KGVsZW1lbnQsIG91dGxldE5hbWUpIHx8ICEhdGhpcy5nZXRPdXRsZXRGcm9tTWFwKGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBnZXRPdXRsZXQoZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5nZXRDb250cm9sbGVyRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgfVxuICAgIGdldE91dGxldEZyb21NYXAoZWxlbWVudCwgb3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXRzQnlOYW1lLmdldFZhbHVlc0ZvcktleShvdXRsZXROYW1lKS5maW5kKChvdXRsZXQpID0+IG91dGxldC5lbGVtZW50ID09PSBlbGVtZW50KTtcbiAgICB9XG4gICAgZ2V0IHNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjb3BlO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGlkZW50aWZpZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IGFwcGxpY2F0aW9uKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgcm91dGVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5hcHBsaWNhdGlvbi5yb3V0ZXI7XG4gICAgfVxufVxuXG5jbGFzcyBDb250ZXh0IHtcbiAgICBjb25zdHJ1Y3Rvcihtb2R1bGUsIHNjb3BlKSB7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eSA9IChmdW5jdGlvbk5hbWUsIGRldGFpbCA9IHt9KSA9PiB7XG4gICAgICAgICAgICBjb25zdCB7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXIsIGVsZW1lbnQgfSA9IHRoaXM7XG4gICAgICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9LCBkZXRhaWwpO1xuICAgICAgICAgICAgdGhpcy5hcHBsaWNhdGlvbi5sb2dEZWJ1Z0FjdGl2aXR5KHRoaXMuaWRlbnRpZmllciwgZnVuY3Rpb25OYW1lLCBkZXRhaWwpO1xuICAgICAgICB9O1xuICAgICAgICB0aGlzLm1vZHVsZSA9IG1vZHVsZTtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgICAgICB0aGlzLmNvbnRyb2xsZXIgPSBuZXcgbW9kdWxlLmNvbnRyb2xsZXJDb25zdHJ1Y3Rvcih0aGlzKTtcbiAgICAgICAgdGhpcy5iaW5kaW5nT2JzZXJ2ZXIgPSBuZXcgQmluZGluZ09ic2VydmVyKHRoaXMsIHRoaXMuZGlzcGF0Y2hlcik7XG4gICAgICAgIHRoaXMudmFsdWVPYnNlcnZlciA9IG5ldyBWYWx1ZU9ic2VydmVyKHRoaXMsIHRoaXMuY29udHJvbGxlcik7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIgPSBuZXcgVGFyZ2V0T2JzZXJ2ZXIodGhpcywgdGhpcyk7XG4gICAgICAgIHRoaXMub3V0bGV0T2JzZXJ2ZXIgPSBuZXcgT3V0bGV0T2JzZXJ2ZXIodGhpcywgdGhpcyk7XG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICB0aGlzLmNvbnRyb2xsZXIuaW5pdGlhbGl6ZSgpO1xuICAgICAgICAgICAgdGhpcy5sb2dEZWJ1Z0FjdGl2aXR5KFwiaW5pdGlhbGl6ZVwiKTtcbiAgICAgICAgfVxuICAgICAgICBjYXRjaCAoZXJyb3IpIHtcbiAgICAgICAgICAgIHRoaXMuaGFuZGxlRXJyb3IoZXJyb3IsIFwiaW5pdGlhbGl6aW5nIGNvbnRyb2xsZXJcIik7XG4gICAgICAgIH1cbiAgICB9XG4gICAgY29ubmVjdCgpIHtcbiAgICAgICAgdGhpcy5iaW5kaW5nT2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy52YWx1ZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIuc3RhcnQoKTtcbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlci5zdGFydCgpO1xuICAgICAgICB0cnkge1xuICAgICAgICAgICAgdGhpcy5jb250cm9sbGVyLmNvbm5lY3QoKTtcbiAgICAgICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImNvbm5lY3RcIik7XG4gICAgICAgIH1cbiAgICAgICAgY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICB0aGlzLmhhbmRsZUVycm9yKGVycm9yLCBcImNvbm5lY3RpbmcgY29udHJvbGxlclwiKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICByZWZyZXNoKCkge1xuICAgICAgICB0aGlzLm91dGxldE9ic2VydmVyLnJlZnJlc2goKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdCgpIHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgIHRoaXMuY29udHJvbGxlci5kaXNjb25uZWN0KCk7XG4gICAgICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJkaXNjb25uZWN0XCIpO1xuICAgICAgICB9XG4gICAgICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICAgICAgdGhpcy5oYW5kbGVFcnJvcihlcnJvciwgXCJkaXNjb25uZWN0aW5nIGNvbnRyb2xsZXJcIik7XG4gICAgICAgIH1cbiAgICAgICAgdGhpcy5vdXRsZXRPYnNlcnZlci5zdG9wKCk7XG4gICAgICAgIHRoaXMudGFyZ2V0T2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLnZhbHVlT2JzZXJ2ZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLmJpbmRpbmdPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGdldCBhcHBsaWNhdGlvbigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMubW9kdWxlLmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMubW9kdWxlLmlkZW50aWZpZXI7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGRpc3BhdGNoZXIoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmRpc3BhdGNoZXI7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgcGFyZW50RWxlbWVudCgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5wYXJlbnRFbGVtZW50O1xuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsID0ge30pIHtcbiAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyLCBjb250cm9sbGVyLCBlbGVtZW50IH0gPSB0aGlzO1xuICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgaWRlbnRpZmllciwgY29udHJvbGxlciwgZWxlbWVudCB9LCBkZXRhaWwpO1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBgRXJyb3IgJHttZXNzYWdlfWAsIGRldGFpbCk7XG4gICAgfVxuICAgIHRhcmdldENvbm5lY3RlZChlbGVtZW50LCBuYW1lKSB7XG4gICAgICAgIHRoaXMuaW52b2tlQ29udHJvbGxlck1ldGhvZChgJHtuYW1lfVRhcmdldENvbm5lY3RlZGAsIGVsZW1lbnQpO1xuICAgIH1cbiAgICB0YXJnZXREaXNjb25uZWN0ZWQoZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZX1UYXJnZXREaXNjb25uZWN0ZWRgLCBlbGVtZW50KTtcbiAgICB9XG4gICAgb3V0bGV0Q29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZXNwYWNlQ2FtZWxpemUobmFtZSl9T3V0bGV0Q29ubmVjdGVkYCwgb3V0bGV0LCBlbGVtZW50KTtcbiAgICB9XG4gICAgb3V0bGV0RGlzY29ubmVjdGVkKG91dGxldCwgZWxlbWVudCwgbmFtZSkge1xuICAgICAgICB0aGlzLmludm9rZUNvbnRyb2xsZXJNZXRob2QoYCR7bmFtZXNwYWNlQ2FtZWxpemUobmFtZSl9T3V0bGV0RGlzY29ubmVjdGVkYCwgb3V0bGV0LCBlbGVtZW50KTtcbiAgICB9XG4gICAgaW52b2tlQ29udHJvbGxlck1ldGhvZChtZXRob2ROYW1lLCAuLi5hcmdzKSB7XG4gICAgICAgIGNvbnN0IGNvbnRyb2xsZXIgPSB0aGlzLmNvbnRyb2xsZXI7XG4gICAgICAgIGlmICh0eXBlb2YgY29udHJvbGxlclttZXRob2ROYW1lXSA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgICAgIGNvbnRyb2xsZXJbbWV0aG9kTmFtZV0oLi4uYXJncyk7XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmZ1bmN0aW9uIGJsZXNzKGNvbnN0cnVjdG9yKSB7XG4gICAgcmV0dXJuIHNoYWRvdyhjb25zdHJ1Y3RvciwgZ2V0Qmxlc3NlZFByb3BlcnRpZXMoY29uc3RydWN0b3IpKTtcbn1cbmZ1bmN0aW9uIHNoYWRvdyhjb25zdHJ1Y3RvciwgcHJvcGVydGllcykge1xuICAgIGNvbnN0IHNoYWRvd0NvbnN0cnVjdG9yID0gZXh0ZW5kKGNvbnN0cnVjdG9yKTtcbiAgICBjb25zdCBzaGFkb3dQcm9wZXJ0aWVzID0gZ2V0U2hhZG93UHJvcGVydGllcyhjb25zdHJ1Y3Rvci5wcm90b3R5cGUsIHByb3BlcnRpZXMpO1xuICAgIE9iamVjdC5kZWZpbmVQcm9wZXJ0aWVzKHNoYWRvd0NvbnN0cnVjdG9yLnByb3RvdHlwZSwgc2hhZG93UHJvcGVydGllcyk7XG4gICAgcmV0dXJuIHNoYWRvd0NvbnN0cnVjdG9yO1xufVxuZnVuY3Rpb24gZ2V0Qmxlc3NlZFByb3BlcnRpZXMoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBibGVzc2luZ3MgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJibGVzc2luZ3NcIik7XG4gICAgcmV0dXJuIGJsZXNzaW5ncy5yZWR1Y2UoKGJsZXNzZWRQcm9wZXJ0aWVzLCBibGVzc2luZykgPT4ge1xuICAgICAgICBjb25zdCBwcm9wZXJ0aWVzID0gYmxlc3NpbmcoY29uc3RydWN0b3IpO1xuICAgICAgICBmb3IgKGNvbnN0IGtleSBpbiBwcm9wZXJ0aWVzKSB7XG4gICAgICAgICAgICBjb25zdCBkZXNjcmlwdG9yID0gYmxlc3NlZFByb3BlcnRpZXNba2V5XSB8fCB7fTtcbiAgICAgICAgICAgIGJsZXNzZWRQcm9wZXJ0aWVzW2tleV0gPSBPYmplY3QuYXNzaWduKGRlc2NyaXB0b3IsIHByb3BlcnRpZXNba2V5XSk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGJsZXNzZWRQcm9wZXJ0aWVzO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIGdldFNoYWRvd1Byb3BlcnRpZXMocHJvdG90eXBlLCBwcm9wZXJ0aWVzKSB7XG4gICAgcmV0dXJuIGdldE93bktleXMocHJvcGVydGllcykucmVkdWNlKChzaGFkb3dQcm9wZXJ0aWVzLCBrZXkpID0+IHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IGdldFNoYWRvd2VkRGVzY3JpcHRvcihwcm90b3R5cGUsIHByb3BlcnRpZXMsIGtleSk7XG4gICAgICAgIGlmIChkZXNjcmlwdG9yKSB7XG4gICAgICAgICAgICBPYmplY3QuYXNzaWduKHNoYWRvd1Byb3BlcnRpZXMsIHsgW2tleV06IGRlc2NyaXB0b3IgfSk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIHNoYWRvd1Byb3BlcnRpZXM7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gZ2V0U2hhZG93ZWREZXNjcmlwdG9yKHByb3RvdHlwZSwgcHJvcGVydGllcywga2V5KSB7XG4gICAgY29uc3Qgc2hhZG93aW5nRGVzY3JpcHRvciA9IE9iamVjdC5nZXRPd25Qcm9wZXJ0eURlc2NyaXB0b3IocHJvdG90eXBlLCBrZXkpO1xuICAgIGNvbnN0IHNoYWRvd2VkQnlWYWx1ZSA9IHNoYWRvd2luZ0Rlc2NyaXB0b3IgJiYgXCJ2YWx1ZVwiIGluIHNoYWRvd2luZ0Rlc2NyaXB0b3I7XG4gICAgaWYgKCFzaGFkb3dlZEJ5VmFsdWUpIHtcbiAgICAgICAgY29uc3QgZGVzY3JpcHRvciA9IE9iamVjdC5nZXRPd25Qcm9wZXJ0eURlc2NyaXB0b3IocHJvcGVydGllcywga2V5KS52YWx1ZTtcbiAgICAgICAgaWYgKHNoYWRvd2luZ0Rlc2NyaXB0b3IpIHtcbiAgICAgICAgICAgIGRlc2NyaXB0b3IuZ2V0ID0gc2hhZG93aW5nRGVzY3JpcHRvci5nZXQgfHwgZGVzY3JpcHRvci5nZXQ7XG4gICAgICAgICAgICBkZXNjcmlwdG9yLnNldCA9IHNoYWRvd2luZ0Rlc2NyaXB0b3Iuc2V0IHx8IGRlc2NyaXB0b3Iuc2V0O1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBkZXNjcmlwdG9yO1xuICAgIH1cbn1cbmNvbnN0IGdldE93bktleXMgPSAoKCkgPT4ge1xuICAgIGlmICh0eXBlb2YgT2JqZWN0LmdldE93blByb3BlcnR5U3ltYm9scyA9PSBcImZ1bmN0aW9uXCIpIHtcbiAgICAgICAgcmV0dXJuIChvYmplY3QpID0+IFsuLi5PYmplY3QuZ2V0T3duUHJvcGVydHlOYW1lcyhvYmplY3QpLCAuLi5PYmplY3QuZ2V0T3duUHJvcGVydHlTeW1ib2xzKG9iamVjdCldO1xuICAgIH1cbiAgICBlbHNlIHtcbiAgICAgICAgcmV0dXJuIE9iamVjdC5nZXRPd25Qcm9wZXJ0eU5hbWVzO1xuICAgIH1cbn0pKCk7XG5jb25zdCBleHRlbmQgPSAoKCkgPT4ge1xuICAgIGZ1bmN0aW9uIGV4dGVuZFdpdGhSZWZsZWN0KGNvbnN0cnVjdG9yKSB7XG4gICAgICAgIGZ1bmN0aW9uIGV4dGVuZGVkKCkge1xuICAgICAgICAgICAgcmV0dXJuIFJlZmxlY3QuY29uc3RydWN0KGNvbnN0cnVjdG9yLCBhcmd1bWVudHMsIG5ldy50YXJnZXQpO1xuICAgICAgICB9XG4gICAgICAgIGV4dGVuZGVkLnByb3RvdHlwZSA9IE9iamVjdC5jcmVhdGUoY29uc3RydWN0b3IucHJvdG90eXBlLCB7XG4gICAgICAgICAgICBjb25zdHJ1Y3RvcjogeyB2YWx1ZTogZXh0ZW5kZWQgfSxcbiAgICAgICAgfSk7XG4gICAgICAgIFJlZmxlY3Quc2V0UHJvdG90eXBlT2YoZXh0ZW5kZWQsIGNvbnN0cnVjdG9yKTtcbiAgICAgICAgcmV0dXJuIGV4dGVuZGVkO1xuICAgIH1cbiAgICBmdW5jdGlvbiB0ZXN0UmVmbGVjdEV4dGVuc2lvbigpIHtcbiAgICAgICAgY29uc3QgYSA9IGZ1bmN0aW9uICgpIHtcbiAgICAgICAgICAgIHRoaXMuYS5jYWxsKHRoaXMpO1xuICAgICAgICB9O1xuICAgICAgICBjb25zdCBiID0gZXh0ZW5kV2l0aFJlZmxlY3QoYSk7XG4gICAgICAgIGIucHJvdG90eXBlLmEgPSBmdW5jdGlvbiAoKSB7IH07XG4gICAgICAgIHJldHVybiBuZXcgYigpO1xuICAgIH1cbiAgICB0cnkge1xuICAgICAgICB0ZXN0UmVmbGVjdEV4dGVuc2lvbigpO1xuICAgICAgICByZXR1cm4gZXh0ZW5kV2l0aFJlZmxlY3Q7XG4gICAgfVxuICAgIGNhdGNoIChlcnJvcikge1xuICAgICAgICByZXR1cm4gKGNvbnN0cnVjdG9yKSA9PiBjbGFzcyBleHRlbmRlZCBleHRlbmRzIGNvbnN0cnVjdG9yIHtcbiAgICAgICAgfTtcbiAgICB9XG59KSgpO1xuXG5mdW5jdGlvbiBibGVzc0RlZmluaXRpb24oZGVmaW5pdGlvbikge1xuICAgIHJldHVybiB7XG4gICAgICAgIGlkZW50aWZpZXI6IGRlZmluaXRpb24uaWRlbnRpZmllcixcbiAgICAgICAgY29udHJvbGxlckNvbnN0cnVjdG9yOiBibGVzcyhkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvciksXG4gICAgfTtcbn1cblxuY2xhc3MgTW9kdWxlIHtcbiAgICBjb25zdHJ1Y3RvcihhcHBsaWNhdGlvbiwgZGVmaW5pdGlvbikge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uID0gYXBwbGljYXRpb247XG4gICAgICAgIHRoaXMuZGVmaW5pdGlvbiA9IGJsZXNzRGVmaW5pdGlvbihkZWZpbml0aW9uKTtcbiAgICAgICAgdGhpcy5jb250ZXh0c0J5U2NvcGUgPSBuZXcgV2Vha01hcCgpO1xuICAgICAgICB0aGlzLmNvbm5lY3RlZENvbnRleHRzID0gbmV3IFNldCgpO1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVmaW5pdGlvbi5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlckNvbnN0cnVjdG9yKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5kZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3RvcjtcbiAgICB9XG4gICAgZ2V0IGNvbnRleHRzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLmNvbm5lY3RlZENvbnRleHRzKTtcbiAgICB9XG4gICAgY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkge1xuICAgICAgICBjb25zdCBjb250ZXh0ID0gdGhpcy5mZXRjaENvbnRleHRGb3JTY29wZShzY29wZSk7XG4gICAgICAgIHRoaXMuY29ubmVjdGVkQ29udGV4dHMuYWRkKGNvbnRleHQpO1xuICAgICAgICBjb250ZXh0LmNvbm5lY3QoKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSkge1xuICAgICAgICBjb25zdCBjb250ZXh0ID0gdGhpcy5jb250ZXh0c0J5U2NvcGUuZ2V0KHNjb3BlKTtcbiAgICAgICAgaWYgKGNvbnRleHQpIHtcbiAgICAgICAgICAgIHRoaXMuY29ubmVjdGVkQ29udGV4dHMuZGVsZXRlKGNvbnRleHQpO1xuICAgICAgICAgICAgY29udGV4dC5kaXNjb25uZWN0KCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgZmV0Y2hDb250ZXh0Rm9yU2NvcGUoc2NvcGUpIHtcbiAgICAgICAgbGV0IGNvbnRleHQgPSB0aGlzLmNvbnRleHRzQnlTY29wZS5nZXQoc2NvcGUpO1xuICAgICAgICBpZiAoIWNvbnRleHQpIHtcbiAgICAgICAgICAgIGNvbnRleHQgPSBuZXcgQ29udGV4dCh0aGlzLCBzY29wZSk7XG4gICAgICAgICAgICB0aGlzLmNvbnRleHRzQnlTY29wZS5zZXQoc2NvcGUsIGNvbnRleHQpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBjb250ZXh0O1xuICAgIH1cbn1cblxuY2xhc3MgQ2xhc3NNYXAge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgaGFzKG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGF0YS5oYXModGhpcy5nZXREYXRhS2V5KG5hbWUpKTtcbiAgICB9XG4gICAgZ2V0KG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZ2V0QWxsKG5hbWUpWzBdO1xuICAgIH1cbiAgICBnZXRBbGwobmFtZSkge1xuICAgICAgICBjb25zdCB0b2tlblN0cmluZyA9IHRoaXMuZGF0YS5nZXQodGhpcy5nZXREYXRhS2V5KG5hbWUpKSB8fCBcIlwiO1xuICAgICAgICByZXR1cm4gdG9rZW5pemUodG9rZW5TdHJpbmcpO1xuICAgIH1cbiAgICBnZXRBdHRyaWJ1dGVOYW1lKG5hbWUpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGF0YS5nZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KHRoaXMuZ2V0RGF0YUtleShuYW1lKSk7XG4gICAgfVxuICAgIGdldERhdGFLZXkobmFtZSkge1xuICAgICAgICByZXR1cm4gYCR7bmFtZX0tY2xhc3NgO1xuICAgIH1cbiAgICBnZXQgZGF0YSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZGF0YTtcbiAgICB9XG59XG5cbmNsYXNzIERhdGFNYXAge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQoa2V5KSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUobmFtZSk7XG4gICAgfVxuICAgIHNldChrZXksIHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgdGhpcy5lbGVtZW50LnNldEF0dHJpYnV0ZShuYW1lLCB2YWx1ZSk7XG4gICAgICAgIHJldHVybiB0aGlzLmdldChrZXkpO1xuICAgIH1cbiAgICBoYXMoa2V5KSB7XG4gICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZWxlbWVudC5oYXNBdHRyaWJ1dGUobmFtZSk7XG4gICAgfVxuICAgIGRlbGV0ZShrZXkpIHtcbiAgICAgICAgaWYgKHRoaXMuaGFzKGtleSkpIHtcbiAgICAgICAgICAgIGNvbnN0IG5hbWUgPSB0aGlzLmdldEF0dHJpYnV0ZU5hbWVGb3JLZXkoa2V5KTtcbiAgICAgICAgICAgIHRoaXMuZWxlbWVudC5yZW1vdmVBdHRyaWJ1dGUobmFtZSk7XG4gICAgICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICAgICAgfVxuICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgIHJldHVybiBmYWxzZTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXRBdHRyaWJ1dGVOYW1lRm9yS2V5KGtleSkge1xuICAgICAgICByZXR1cm4gYGRhdGEtJHt0aGlzLmlkZW50aWZpZXJ9LSR7ZGFzaGVyaXplKGtleSl9YDtcbiAgICB9XG59XG5cbmNsYXNzIEd1aWRlIHtcbiAgICBjb25zdHJ1Y3Rvcihsb2dnZXIpIHtcbiAgICAgICAgdGhpcy53YXJuZWRLZXlzQnlPYmplY3QgPSBuZXcgV2Vha01hcCgpO1xuICAgICAgICB0aGlzLmxvZ2dlciA9IGxvZ2dlcjtcbiAgICB9XG4gICAgd2FybihvYmplY3QsIGtleSwgbWVzc2FnZSkge1xuICAgICAgICBsZXQgd2FybmVkS2V5cyA9IHRoaXMud2FybmVkS2V5c0J5T2JqZWN0LmdldChvYmplY3QpO1xuICAgICAgICBpZiAoIXdhcm5lZEtleXMpIHtcbiAgICAgICAgICAgIHdhcm5lZEtleXMgPSBuZXcgU2V0KCk7XG4gICAgICAgICAgICB0aGlzLndhcm5lZEtleXNCeU9iamVjdC5zZXQob2JqZWN0LCB3YXJuZWRLZXlzKTtcbiAgICAgICAgfVxuICAgICAgICBpZiAoIXdhcm5lZEtleXMuaGFzKGtleSkpIHtcbiAgICAgICAgICAgIHdhcm5lZEtleXMuYWRkKGtleSk7XG4gICAgICAgICAgICB0aGlzLmxvZ2dlci53YXJuKG1lc3NhZ2UsIG9iamVjdCk7XG4gICAgICAgIH1cbiAgICB9XG59XG5cbmZ1bmN0aW9uIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbihhdHRyaWJ1dGVOYW1lLCB0b2tlbikge1xuICAgIHJldHVybiBgWyR7YXR0cmlidXRlTmFtZX1+PVwiJHt0b2tlbn1cIl1gO1xufVxuXG5jbGFzcyBUYXJnZXRTZXQge1xuICAgIGNvbnN0cnVjdG9yKHNjb3BlKSB7XG4gICAgICAgIHRoaXMuc2NvcGUgPSBzY29wZTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBpZGVudGlmaWVyKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5pZGVudGlmaWVyO1xuICAgIH1cbiAgICBnZXQgc2NoZW1hKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5zY2hlbWE7XG4gICAgfVxuICAgIGhhcyh0YXJnZXROYW1lKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmZpbmQodGFyZ2V0TmFtZSkgIT0gbnVsbDtcbiAgICB9XG4gICAgZmluZCguLi50YXJnZXROYW1lcykge1xuICAgICAgICByZXR1cm4gdGFyZ2V0TmFtZXMucmVkdWNlKCh0YXJnZXQsIHRhcmdldE5hbWUpID0+IHRhcmdldCB8fCB0aGlzLmZpbmRUYXJnZXQodGFyZ2V0TmFtZSkgfHwgdGhpcy5maW5kTGVnYWN5VGFyZ2V0KHRhcmdldE5hbWUpLCB1bmRlZmluZWQpO1xuICAgIH1cbiAgICBmaW5kQWxsKC4uLnRhcmdldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiB0YXJnZXROYW1lcy5yZWR1Y2UoKHRhcmdldHMsIHRhcmdldE5hbWUpID0+IFtcbiAgICAgICAgICAgIC4uLnRhcmdldHMsXG4gICAgICAgICAgICAuLi50aGlzLmZpbmRBbGxUYXJnZXRzKHRhcmdldE5hbWUpLFxuICAgICAgICAgICAgLi4udGhpcy5maW5kQWxsTGVnYWN5VGFyZ2V0cyh0YXJnZXROYW1lKSxcbiAgICAgICAgXSwgW10pO1xuICAgIH1cbiAgICBmaW5kVGFyZ2V0KHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuZmluZEVsZW1lbnQoc2VsZWN0b3IpO1xuICAgIH1cbiAgICBmaW5kQWxsVGFyZ2V0cyh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSk7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmZpbmRBbGxFbGVtZW50cyhzZWxlY3Rvcik7XG4gICAgfVxuICAgIGdldFNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGVGb3JTY29wZSh0aGlzLmlkZW50aWZpZXIpO1xuICAgICAgICByZXR1cm4gYXR0cmlidXRlVmFsdWVDb250YWluc1Rva2VuKGF0dHJpYnV0ZU5hbWUsIHRhcmdldE5hbWUpO1xuICAgIH1cbiAgICBmaW5kTGVnYWN5VGFyZ2V0KHRhcmdldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldExlZ2FjeVNlbGVjdG9yRm9yVGFyZ2V0TmFtZSh0YXJnZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuZGVwcmVjYXRlKHRoaXMuc2NvcGUuZmluZEVsZW1lbnQoc2VsZWN0b3IpLCB0YXJnZXROYW1lKTtcbiAgICB9XG4gICAgZmluZEFsbExlZ2FjeVRhcmdldHModGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCBzZWxlY3RvciA9IHRoaXMuZ2V0TGVnYWN5U2VsZWN0b3JGb3JUYXJnZXROYW1lKHRhcmdldE5hbWUpO1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5maW5kQWxsRWxlbWVudHMoc2VsZWN0b3IpLm1hcCgoZWxlbWVudCkgPT4gdGhpcy5kZXByZWNhdGUoZWxlbWVudCwgdGFyZ2V0TmFtZSkpO1xuICAgIH1cbiAgICBnZXRMZWdhY3lTZWxlY3RvckZvclRhcmdldE5hbWUodGFyZ2V0TmFtZSkge1xuICAgICAgICBjb25zdCB0YXJnZXREZXNjcmlwdG9yID0gYCR7dGhpcy5pZGVudGlmaWVyfS4ke3RhcmdldE5hbWV9YDtcbiAgICAgICAgcmV0dXJuIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbih0aGlzLnNjaGVtYS50YXJnZXRBdHRyaWJ1dGUsIHRhcmdldERlc2NyaXB0b3IpO1xuICAgIH1cbiAgICBkZXByZWNhdGUoZWxlbWVudCwgdGFyZ2V0TmFtZSkge1xuICAgICAgICBpZiAoZWxlbWVudCkge1xuICAgICAgICAgICAgY29uc3QgeyBpZGVudGlmaWVyIH0gPSB0aGlzO1xuICAgICAgICAgICAgY29uc3QgYXR0cmlidXRlTmFtZSA9IHRoaXMuc2NoZW1hLnRhcmdldEF0dHJpYnV0ZTtcbiAgICAgICAgICAgIGNvbnN0IHJldmlzZWRBdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEudGFyZ2V0QXR0cmlidXRlRm9yU2NvcGUoaWRlbnRpZmllcik7XG4gICAgICAgICAgICB0aGlzLmd1aWRlLndhcm4oZWxlbWVudCwgYHRhcmdldDoke3RhcmdldE5hbWV9YCwgYFBsZWFzZSByZXBsYWNlICR7YXR0cmlidXRlTmFtZX09XCIke2lkZW50aWZpZXJ9LiR7dGFyZ2V0TmFtZX1cIiB3aXRoICR7cmV2aXNlZEF0dHJpYnV0ZU5hbWV9PVwiJHt0YXJnZXROYW1lfVwiLiBgICtcbiAgICAgICAgICAgICAgICBgVGhlICR7YXR0cmlidXRlTmFtZX0gYXR0cmlidXRlIGlzIGRlcHJlY2F0ZWQgYW5kIHdpbGwgYmUgcmVtb3ZlZCBpbiBhIGZ1dHVyZSB2ZXJzaW9uIG9mIFN0aW11bHVzLmApO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBlbGVtZW50O1xuICAgIH1cbiAgICBnZXQgZ3VpZGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmd1aWRlO1xuICAgIH1cbn1cblxuY2xhc3MgT3V0bGV0U2V0IHtcbiAgICBjb25zdHJ1Y3RvcihzY29wZSwgY29udHJvbGxlckVsZW1lbnQpIHtcbiAgICAgICAgdGhpcy5zY29wZSA9IHNjb3BlO1xuICAgICAgICB0aGlzLmNvbnRyb2xsZXJFbGVtZW50ID0gY29udHJvbGxlckVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHNjaGVtYSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuc2NoZW1hO1xuICAgIH1cbiAgICBoYXMob3V0bGV0TmFtZSkge1xuICAgICAgICByZXR1cm4gdGhpcy5maW5kKG91dGxldE5hbWUpICE9IG51bGw7XG4gICAgfVxuICAgIGZpbmQoLi4ub3V0bGV0TmFtZXMpIHtcbiAgICAgICAgcmV0dXJuIG91dGxldE5hbWVzLnJlZHVjZSgob3V0bGV0LCBvdXRsZXROYW1lKSA9PiBvdXRsZXQgfHwgdGhpcy5maW5kT3V0bGV0KG91dGxldE5hbWUpLCB1bmRlZmluZWQpO1xuICAgIH1cbiAgICBmaW5kQWxsKC4uLm91dGxldE5hbWVzKSB7XG4gICAgICAgIHJldHVybiBvdXRsZXROYW1lcy5yZWR1Y2UoKG91dGxldHMsIG91dGxldE5hbWUpID0+IFsuLi5vdXRsZXRzLCAuLi50aGlzLmZpbmRBbGxPdXRsZXRzKG91dGxldE5hbWUpXSwgW10pO1xuICAgIH1cbiAgICBnZXRTZWxlY3RvckZvck91dGxldE5hbWUob3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBhdHRyaWJ1dGVOYW1lID0gdGhpcy5zY2hlbWEub3V0bGV0QXR0cmlidXRlRm9yU2NvcGUodGhpcy5pZGVudGlmaWVyLCBvdXRsZXROYW1lKTtcbiAgICAgICAgcmV0dXJuIHRoaXMuY29udHJvbGxlckVsZW1lbnQuZ2V0QXR0cmlidXRlKGF0dHJpYnV0ZU5hbWUpO1xuICAgIH1cbiAgICBmaW5kT3V0bGV0KG91dGxldE5hbWUpIHtcbiAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLmdldFNlbGVjdG9yRm9yT3V0bGV0TmFtZShvdXRsZXROYW1lKTtcbiAgICAgICAgaWYgKHNlbGVjdG9yKVxuICAgICAgICAgICAgcmV0dXJuIHRoaXMuZmluZEVsZW1lbnQoc2VsZWN0b3IsIG91dGxldE5hbWUpO1xuICAgIH1cbiAgICBmaW5kQWxsT3V0bGV0cyhvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IHNlbGVjdG9yID0gdGhpcy5nZXRTZWxlY3RvckZvck91dGxldE5hbWUob3V0bGV0TmFtZSk7XG4gICAgICAgIHJldHVybiBzZWxlY3RvciA/IHRoaXMuZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yLCBvdXRsZXROYW1lKSA6IFtdO1xuICAgIH1cbiAgICBmaW5kRWxlbWVudChzZWxlY3Rvciwgb3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBlbGVtZW50cyA9IHRoaXMuc2NvcGUucXVlcnlFbGVtZW50cyhzZWxlY3Rvcik7XG4gICAgICAgIHJldHVybiBlbGVtZW50cy5maWx0ZXIoKGVsZW1lbnQpID0+IHRoaXMubWF0Y2hlc0VsZW1lbnQoZWxlbWVudCwgc2VsZWN0b3IsIG91dGxldE5hbWUpKVswXTtcbiAgICB9XG4gICAgZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yLCBvdXRsZXROYW1lKSB7XG4gICAgICAgIGNvbnN0IGVsZW1lbnRzID0gdGhpcy5zY29wZS5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKTtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnRzLmZpbHRlcigoZWxlbWVudCkgPT4gdGhpcy5tYXRjaGVzRWxlbWVudChlbGVtZW50LCBzZWxlY3Rvciwgb3V0bGV0TmFtZSkpO1xuICAgIH1cbiAgICBtYXRjaGVzRWxlbWVudChlbGVtZW50LCBzZWxlY3Rvciwgb3V0bGV0TmFtZSkge1xuICAgICAgICBjb25zdCBjb250cm9sbGVyQXR0cmlidXRlID0gZWxlbWVudC5nZXRBdHRyaWJ1dGUodGhpcy5zY29wZS5zY2hlbWEuY29udHJvbGxlckF0dHJpYnV0ZSkgfHwgXCJcIjtcbiAgICAgICAgcmV0dXJuIGVsZW1lbnQubWF0Y2hlcyhzZWxlY3RvcikgJiYgY29udHJvbGxlckF0dHJpYnV0ZS5zcGxpdChcIiBcIikuaW5jbHVkZXMob3V0bGV0TmFtZSk7XG4gICAgfVxufVxuXG5jbGFzcyBTY29wZSB7XG4gICAgY29uc3RydWN0b3Ioc2NoZW1hLCBlbGVtZW50LCBpZGVudGlmaWVyLCBsb2dnZXIpIHtcbiAgICAgICAgdGhpcy50YXJnZXRzID0gbmV3IFRhcmdldFNldCh0aGlzKTtcbiAgICAgICAgdGhpcy5jbGFzc2VzID0gbmV3IENsYXNzTWFwKHRoaXMpO1xuICAgICAgICB0aGlzLmRhdGEgPSBuZXcgRGF0YU1hcCh0aGlzKTtcbiAgICAgICAgdGhpcy5jb250YWluc0VsZW1lbnQgPSAoZWxlbWVudCkgPT4ge1xuICAgICAgICAgICAgcmV0dXJuIGVsZW1lbnQuY2xvc2VzdCh0aGlzLmNvbnRyb2xsZXJTZWxlY3RvcikgPT09IHRoaXMuZWxlbWVudDtcbiAgICAgICAgfTtcbiAgICAgICAgdGhpcy5zY2hlbWEgPSBzY2hlbWE7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuaWRlbnRpZmllciA9IGlkZW50aWZpZXI7XG4gICAgICAgIHRoaXMuZ3VpZGUgPSBuZXcgR3VpZGUobG9nZ2VyKTtcbiAgICAgICAgdGhpcy5vdXRsZXRzID0gbmV3IE91dGxldFNldCh0aGlzLmRvY3VtZW50U2NvcGUsIGVsZW1lbnQpO1xuICAgIH1cbiAgICBmaW5kRWxlbWVudChzZWxlY3Rvcikge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpID8gdGhpcy5lbGVtZW50IDogdGhpcy5xdWVyeUVsZW1lbnRzKHNlbGVjdG9yKS5maW5kKHRoaXMuY29udGFpbnNFbGVtZW50KTtcbiAgICB9XG4gICAgZmluZEFsbEVsZW1lbnRzKHNlbGVjdG9yKSB7XG4gICAgICAgIHJldHVybiBbXG4gICAgICAgICAgICAuLi4odGhpcy5lbGVtZW50Lm1hdGNoZXMoc2VsZWN0b3IpID8gW3RoaXMuZWxlbWVudF0gOiBbXSksXG4gICAgICAgICAgICAuLi50aGlzLnF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpLmZpbHRlcih0aGlzLmNvbnRhaW5zRWxlbWVudCksXG4gICAgICAgIF07XG4gICAgfVxuICAgIHF1ZXJ5RWxlbWVudHMoc2VsZWN0b3IpIHtcbiAgICAgICAgcmV0dXJuIEFycmF5LmZyb20odGhpcy5lbGVtZW50LnF1ZXJ5U2VsZWN0b3JBbGwoc2VsZWN0b3IpKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJTZWxlY3RvcigpIHtcbiAgICAgICAgcmV0dXJuIGF0dHJpYnV0ZVZhbHVlQ29udGFpbnNUb2tlbih0aGlzLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlLCB0aGlzLmlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBnZXQgaXNEb2N1bWVudFNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5lbGVtZW50ID09PSBkb2N1bWVudC5kb2N1bWVudEVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBkb2N1bWVudFNjb3BlKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5pc0RvY3VtZW50U2NvcGVcbiAgICAgICAgICAgID8gdGhpc1xuICAgICAgICAgICAgOiBuZXcgU2NvcGUodGhpcy5zY2hlbWEsIGRvY3VtZW50LmRvY3VtZW50RWxlbWVudCwgdGhpcy5pZGVudGlmaWVyLCB0aGlzLmd1aWRlLmxvZ2dlcik7XG4gICAgfVxufVxuXG5jbGFzcyBTY29wZU9ic2VydmVyIHtcbiAgICBjb25zdHJ1Y3RvcihlbGVtZW50LCBzY2hlbWEsIGRlbGVnYXRlKSB7XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgICAgICB0aGlzLmRlbGVnYXRlID0gZGVsZWdhdGU7XG4gICAgICAgIHRoaXMudmFsdWVMaXN0T2JzZXJ2ZXIgPSBuZXcgVmFsdWVMaXN0T2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLmNvbnRyb2xsZXJBdHRyaWJ1dGUsIHRoaXMpO1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllckJ5RWxlbWVudCA9IG5ldyBXZWFrTWFwKCk7XG4gICAgICAgIHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMgPSBuZXcgV2Vha01hcCgpO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy52YWx1ZUxpc3RPYnNlcnZlci5zdGFydCgpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLnZhbHVlTGlzdE9ic2VydmVyLnN0b3AoKTtcbiAgICB9XG4gICAgZ2V0IGNvbnRyb2xsZXJBdHRyaWJ1dGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjaGVtYS5jb250cm9sbGVyQXR0cmlidXRlO1xuICAgIH1cbiAgICBwYXJzZVZhbHVlRm9yVG9rZW4odG9rZW4pIHtcbiAgICAgICAgY29uc3QgeyBlbGVtZW50LCBjb250ZW50OiBpZGVudGlmaWVyIH0gPSB0b2tlbjtcbiAgICAgICAgcmV0dXJuIHRoaXMucGFyc2VWYWx1ZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xuICAgIH1cbiAgICBwYXJzZVZhbHVlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBzY29wZXNCeUlkZW50aWZpZXIgPSB0aGlzLmZldGNoU2NvcGVzQnlJZGVudGlmaWVyRm9yRWxlbWVudChlbGVtZW50KTtcbiAgICAgICAgbGV0IHNjb3BlID0gc2NvcGVzQnlJZGVudGlmaWVyLmdldChpZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKCFzY29wZSkge1xuICAgICAgICAgICAgc2NvcGUgPSB0aGlzLmRlbGVnYXRlLmNyZWF0ZVNjb3BlRm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcik7XG4gICAgICAgICAgICBzY29wZXNCeUlkZW50aWZpZXIuc2V0KGlkZW50aWZpZXIsIHNjb3BlKTtcbiAgICAgICAgfVxuICAgICAgICByZXR1cm4gc2NvcGU7XG4gICAgfVxuICAgIGVsZW1lbnRNYXRjaGVkVmFsdWUoZWxlbWVudCwgdmFsdWUpIHtcbiAgICAgICAgY29uc3QgcmVmZXJlbmNlQ291bnQgPSAodGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5nZXQodmFsdWUpIHx8IDApICsgMTtcbiAgICAgICAgdGhpcy5zY29wZVJlZmVyZW5jZUNvdW50cy5zZXQodmFsdWUsIHJlZmVyZW5jZUNvdW50KTtcbiAgICAgICAgaWYgKHJlZmVyZW5jZUNvdW50ID09IDEpIHtcbiAgICAgICAgICAgIHRoaXMuZGVsZWdhdGUuc2NvcGVDb25uZWN0ZWQodmFsdWUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGVsZW1lbnRVbm1hdGNoZWRWYWx1ZShlbGVtZW50LCB2YWx1ZSkge1xuICAgICAgICBjb25zdCByZWZlcmVuY2VDb3VudCA9IHRoaXMuc2NvcGVSZWZlcmVuY2VDb3VudHMuZ2V0KHZhbHVlKTtcbiAgICAgICAgaWYgKHJlZmVyZW5jZUNvdW50KSB7XG4gICAgICAgICAgICB0aGlzLnNjb3BlUmVmZXJlbmNlQ291bnRzLnNldCh2YWx1ZSwgcmVmZXJlbmNlQ291bnQgLSAxKTtcbiAgICAgICAgICAgIGlmIChyZWZlcmVuY2VDb3VudCA9PSAxKSB7XG4gICAgICAgICAgICAgICAgdGhpcy5kZWxlZ2F0ZS5zY29wZURpc2Nvbm5lY3RlZCh2YWx1ZSk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH1cbiAgICB9XG4gICAgZmV0Y2hTY29wZXNCeUlkZW50aWZpZXJGb3JFbGVtZW50KGVsZW1lbnQpIHtcbiAgICAgICAgbGV0IHNjb3Blc0J5SWRlbnRpZmllciA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyQnlFbGVtZW50LmdldChlbGVtZW50KTtcbiAgICAgICAgaWYgKCFzY29wZXNCeUlkZW50aWZpZXIpIHtcbiAgICAgICAgICAgIHNjb3Blc0J5SWRlbnRpZmllciA9IG5ldyBNYXAoKTtcbiAgICAgICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyQnlFbGVtZW50LnNldChlbGVtZW50LCBzY29wZXNCeUlkZW50aWZpZXIpO1xuICAgICAgICB9XG4gICAgICAgIHJldHVybiBzY29wZXNCeUlkZW50aWZpZXI7XG4gICAgfVxufVxuXG5jbGFzcyBSb3V0ZXIge1xuICAgIGNvbnN0cnVjdG9yKGFwcGxpY2F0aW9uKSB7XG4gICAgICAgIHRoaXMuYXBwbGljYXRpb24gPSBhcHBsaWNhdGlvbjtcbiAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyID0gbmV3IFNjb3BlT2JzZXJ2ZXIodGhpcy5lbGVtZW50LCB0aGlzLnNjaGVtYSwgdGhpcyk7XG4gICAgICAgIHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyID0gbmV3IE11bHRpbWFwKCk7XG4gICAgICAgIHRoaXMubW9kdWxlc0J5SWRlbnRpZmllciA9IG5ldyBNYXAoKTtcbiAgICB9XG4gICAgZ2V0IGVsZW1lbnQoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLmVsZW1lbnQ7XG4gICAgfVxuICAgIGdldCBzY2hlbWEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmFwcGxpY2F0aW9uLnNjaGVtYTtcbiAgICB9XG4gICAgZ2V0IGxvZ2dlcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuYXBwbGljYXRpb24ubG9nZ2VyO1xuICAgIH1cbiAgICBnZXQgY29udHJvbGxlckF0dHJpYnV0ZSgpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NoZW1hLmNvbnRyb2xsZXJBdHRyaWJ1dGU7XG4gICAgfVxuICAgIGdldCBtb2R1bGVzKCkge1xuICAgICAgICByZXR1cm4gQXJyYXkuZnJvbSh0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIudmFsdWVzKCkpO1xuICAgIH1cbiAgICBnZXQgY29udGV4dHMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLm1vZHVsZXMucmVkdWNlKChjb250ZXh0cywgbW9kdWxlKSA9PiBjb250ZXh0cy5jb25jYXQobW9kdWxlLmNvbnRleHRzKSwgW10pO1xuICAgIH1cbiAgICBzdGFydCgpIHtcbiAgICAgICAgdGhpcy5zY29wZU9ic2VydmVyLnN0YXJ0KCk7XG4gICAgfVxuICAgIHN0b3AoKSB7XG4gICAgICAgIHRoaXMuc2NvcGVPYnNlcnZlci5zdG9wKCk7XG4gICAgfVxuICAgIGxvYWREZWZpbml0aW9uKGRlZmluaXRpb24pIHtcbiAgICAgICAgdGhpcy51bmxvYWRJZGVudGlmaWVyKGRlZmluaXRpb24uaWRlbnRpZmllcik7XG4gICAgICAgIGNvbnN0IG1vZHVsZSA9IG5ldyBNb2R1bGUodGhpcy5hcHBsaWNhdGlvbiwgZGVmaW5pdGlvbik7XG4gICAgICAgIHRoaXMuY29ubmVjdE1vZHVsZShtb2R1bGUpO1xuICAgICAgICBjb25zdCBhZnRlckxvYWQgPSBkZWZpbml0aW9uLmNvbnRyb2xsZXJDb25zdHJ1Y3Rvci5hZnRlckxvYWQ7XG4gICAgICAgIGlmIChhZnRlckxvYWQpIHtcbiAgICAgICAgICAgIGFmdGVyTG9hZC5jYWxsKGRlZmluaXRpb24uY29udHJvbGxlckNvbnN0cnVjdG9yLCBkZWZpbml0aW9uLmlkZW50aWZpZXIsIHRoaXMuYXBwbGljYXRpb24pO1xuICAgICAgICB9XG4gICAgfVxuICAgIHVubG9hZElkZW50aWZpZXIoaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICB0aGlzLmRpc2Nvbm5lY3RNb2R1bGUobW9kdWxlKTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBnZXRDb250ZXh0Rm9yRWxlbWVudEFuZElkZW50aWZpZXIoZWxlbWVudCwgaWRlbnRpZmllcikge1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KGlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICByZXR1cm4gbW9kdWxlLmNvbnRleHRzLmZpbmQoKGNvbnRleHQpID0+IGNvbnRleHQuZWxlbWVudCA9PSBlbGVtZW50KTtcbiAgICAgICAgfVxuICAgIH1cbiAgICBwcm9wb3NlVG9Db25uZWN0U2NvcGVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgICAgIGNvbnN0IHNjb3BlID0gdGhpcy5zY29wZU9ic2VydmVyLnBhcnNlVmFsdWVGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKHNjb3BlKSB7XG4gICAgICAgICAgICB0aGlzLnNjb3BlT2JzZXJ2ZXIuZWxlbWVudE1hdGNoZWRWYWx1ZShzY29wZS5lbGVtZW50LCBzY29wZSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICBjb25zb2xlLmVycm9yKGBDb3VsZG4ndCBmaW5kIG9yIGNyZWF0ZSBzY29wZSBmb3IgaWRlbnRpZmllcjogXCIke2lkZW50aWZpZXJ9XCIgYW5kIGVsZW1lbnQ6YCwgZWxlbWVudCk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgaGFuZGxlRXJyb3IoZXJyb3IsIG1lc3NhZ2UsIGRldGFpbCkge1xuICAgICAgICB0aGlzLmFwcGxpY2F0aW9uLmhhbmRsZUVycm9yKGVycm9yLCBtZXNzYWdlLCBkZXRhaWwpO1xuICAgIH1cbiAgICBjcmVhdGVTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgcmV0dXJuIG5ldyBTY29wZSh0aGlzLnNjaGVtYSwgZWxlbWVudCwgaWRlbnRpZmllciwgdGhpcy5sb2dnZXIpO1xuICAgIH1cbiAgICBzY29wZUNvbm5lY3RlZChzY29wZSkge1xuICAgICAgICB0aGlzLnNjb3Blc0J5SWRlbnRpZmllci5hZGQoc2NvcGUuaWRlbnRpZmllciwgc2NvcGUpO1xuICAgICAgICBjb25zdCBtb2R1bGUgPSB0aGlzLm1vZHVsZXNCeUlkZW50aWZpZXIuZ2V0KHNjb3BlLmlkZW50aWZpZXIpO1xuICAgICAgICBpZiAobW9kdWxlKSB7XG4gICAgICAgICAgICBtb2R1bGUuY29ubmVjdENvbnRleHRGb3JTY29wZShzY29wZSk7XG4gICAgICAgIH1cbiAgICB9XG4gICAgc2NvcGVEaXNjb25uZWN0ZWQoc2NvcGUpIHtcbiAgICAgICAgdGhpcy5zY29wZXNCeUlkZW50aWZpZXIuZGVsZXRlKHNjb3BlLmlkZW50aWZpZXIsIHNjb3BlKTtcbiAgICAgICAgY29uc3QgbW9kdWxlID0gdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmdldChzY29wZS5pZGVudGlmaWVyKTtcbiAgICAgICAgaWYgKG1vZHVsZSkge1xuICAgICAgICAgICAgbW9kdWxlLmRpc2Nvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpO1xuICAgICAgICB9XG4gICAgfVxuICAgIGNvbm5lY3RNb2R1bGUobW9kdWxlKSB7XG4gICAgICAgIHRoaXMubW9kdWxlc0J5SWRlbnRpZmllci5zZXQobW9kdWxlLmlkZW50aWZpZXIsIG1vZHVsZSk7XG4gICAgICAgIGNvbnN0IHNjb3BlcyA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmdldFZhbHVlc0ZvcktleShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIHNjb3Blcy5mb3JFYWNoKChzY29wZSkgPT4gbW9kdWxlLmNvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpKTtcbiAgICB9XG4gICAgZGlzY29ubmVjdE1vZHVsZShtb2R1bGUpIHtcbiAgICAgICAgdGhpcy5tb2R1bGVzQnlJZGVudGlmaWVyLmRlbGV0ZShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIGNvbnN0IHNjb3BlcyA9IHRoaXMuc2NvcGVzQnlJZGVudGlmaWVyLmdldFZhbHVlc0ZvcktleShtb2R1bGUuaWRlbnRpZmllcik7XG4gICAgICAgIHNjb3Blcy5mb3JFYWNoKChzY29wZSkgPT4gbW9kdWxlLmRpc2Nvbm5lY3RDb250ZXh0Rm9yU2NvcGUoc2NvcGUpKTtcbiAgICB9XG59XG5cbmNvbnN0IGRlZmF1bHRTY2hlbWEgPSB7XG4gICAgY29udHJvbGxlckF0dHJpYnV0ZTogXCJkYXRhLWNvbnRyb2xsZXJcIixcbiAgICBhY3Rpb25BdHRyaWJ1dGU6IFwiZGF0YS1hY3Rpb25cIixcbiAgICB0YXJnZXRBdHRyaWJ1dGU6IFwiZGF0YS10YXJnZXRcIixcbiAgICB0YXJnZXRBdHRyaWJ1dGVGb3JTY29wZTogKGlkZW50aWZpZXIpID0+IGBkYXRhLSR7aWRlbnRpZmllcn0tdGFyZ2V0YCxcbiAgICBvdXRsZXRBdHRyaWJ1dGVGb3JTY29wZTogKGlkZW50aWZpZXIsIG91dGxldCkgPT4gYGRhdGEtJHtpZGVudGlmaWVyfS0ke291dGxldH0tb3V0bGV0YCxcbiAgICBrZXlNYXBwaW5nczogT2JqZWN0LmFzc2lnbihPYmplY3QuYXNzaWduKHsgZW50ZXI6IFwiRW50ZXJcIiwgdGFiOiBcIlRhYlwiLCBlc2M6IFwiRXNjYXBlXCIsIHNwYWNlOiBcIiBcIiwgdXA6IFwiQXJyb3dVcFwiLCBkb3duOiBcIkFycm93RG93blwiLCBsZWZ0OiBcIkFycm93TGVmdFwiLCByaWdodDogXCJBcnJvd1JpZ2h0XCIsIGhvbWU6IFwiSG9tZVwiLCBlbmQ6IFwiRW5kXCIsIHBhZ2VfdXA6IFwiUGFnZVVwXCIsIHBhZ2VfZG93bjogXCJQYWdlRG93blwiIH0sIG9iamVjdEZyb21FbnRyaWVzKFwiYWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXpcIi5zcGxpdChcIlwiKS5tYXAoKGMpID0+IFtjLCBjXSkpKSwgb2JqZWN0RnJvbUVudHJpZXMoXCIwMTIzNDU2Nzg5XCIuc3BsaXQoXCJcIikubWFwKChuKSA9PiBbbiwgbl0pKSksXG59O1xuZnVuY3Rpb24gb2JqZWN0RnJvbUVudHJpZXMoYXJyYXkpIHtcbiAgICByZXR1cm4gYXJyYXkucmVkdWNlKChtZW1vLCBbaywgdl0pID0+IChPYmplY3QuYXNzaWduKE9iamVjdC5hc3NpZ24oe30sIG1lbW8pLCB7IFtrXTogdiB9KSksIHt9KTtcbn1cblxuY2xhc3MgQXBwbGljYXRpb24ge1xuICAgIGNvbnN0cnVjdG9yKGVsZW1lbnQgPSBkb2N1bWVudC5kb2N1bWVudEVsZW1lbnQsIHNjaGVtYSA9IGRlZmF1bHRTY2hlbWEpIHtcbiAgICAgICAgdGhpcy5sb2dnZXIgPSBjb25zb2xlO1xuICAgICAgICB0aGlzLmRlYnVnID0gZmFsc2U7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eSA9IChpZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCA9IHt9KSA9PiB7XG4gICAgICAgICAgICBpZiAodGhpcy5kZWJ1Zykge1xuICAgICAgICAgICAgICAgIHRoaXMubG9nRm9ybWF0dGVkTWVzc2FnZShpZGVudGlmaWVyLCBmdW5jdGlvbk5hbWUsIGRldGFpbCk7XG4gICAgICAgICAgICB9XG4gICAgICAgIH07XG4gICAgICAgIHRoaXMuZWxlbWVudCA9IGVsZW1lbnQ7XG4gICAgICAgIHRoaXMuc2NoZW1hID0gc2NoZW1hO1xuICAgICAgICB0aGlzLmRpc3BhdGNoZXIgPSBuZXcgRGlzcGF0Y2hlcih0aGlzKTtcbiAgICAgICAgdGhpcy5yb3V0ZXIgPSBuZXcgUm91dGVyKHRoaXMpO1xuICAgICAgICB0aGlzLmFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzID0gT2JqZWN0LmFzc2lnbih7fSwgZGVmYXVsdEFjdGlvbkRlc2NyaXB0b3JGaWx0ZXJzKTtcbiAgICB9XG4gICAgc3RhdGljIHN0YXJ0KGVsZW1lbnQsIHNjaGVtYSkge1xuICAgICAgICBjb25zdCBhcHBsaWNhdGlvbiA9IG5ldyB0aGlzKGVsZW1lbnQsIHNjaGVtYSk7XG4gICAgICAgIGFwcGxpY2F0aW9uLnN0YXJ0KCk7XG4gICAgICAgIHJldHVybiBhcHBsaWNhdGlvbjtcbiAgICB9XG4gICAgYXN5bmMgc3RhcnQoKSB7XG4gICAgICAgIGF3YWl0IGRvbVJlYWR5KCk7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RhcnRpbmdcIik7XG4gICAgICAgIHRoaXMuZGlzcGF0Y2hlci5zdGFydCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5zdGFydCgpO1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJhcHBsaWNhdGlvblwiLCBcInN0YXJ0XCIpO1xuICAgIH1cbiAgICBzdG9wKCkge1xuICAgICAgICB0aGlzLmxvZ0RlYnVnQWN0aXZpdHkoXCJhcHBsaWNhdGlvblwiLCBcInN0b3BwaW5nXCIpO1xuICAgICAgICB0aGlzLmRpc3BhdGNoZXIuc3RvcCgpO1xuICAgICAgICB0aGlzLnJvdXRlci5zdG9wKCk7XG4gICAgICAgIHRoaXMubG9nRGVidWdBY3Rpdml0eShcImFwcGxpY2F0aW9uXCIsIFwic3RvcFwiKTtcbiAgICB9XG4gICAgcmVnaXN0ZXIoaWRlbnRpZmllciwgY29udHJvbGxlckNvbnN0cnVjdG9yKSB7XG4gICAgICAgIHRoaXMubG9hZCh7IGlkZW50aWZpZXIsIGNvbnRyb2xsZXJDb25zdHJ1Y3RvciB9KTtcbiAgICB9XG4gICAgcmVnaXN0ZXJBY3Rpb25PcHRpb24obmFtZSwgZmlsdGVyKSB7XG4gICAgICAgIHRoaXMuYWN0aW9uRGVzY3JpcHRvckZpbHRlcnNbbmFtZV0gPSBmaWx0ZXI7XG4gICAgfVxuICAgIGxvYWQoaGVhZCwgLi4ucmVzdCkge1xuICAgICAgICBjb25zdCBkZWZpbml0aW9ucyA9IEFycmF5LmlzQXJyYXkoaGVhZCkgPyBoZWFkIDogW2hlYWQsIC4uLnJlc3RdO1xuICAgICAgICBkZWZpbml0aW9ucy5mb3JFYWNoKChkZWZpbml0aW9uKSA9PiB7XG4gICAgICAgICAgICBpZiAoZGVmaW5pdGlvbi5jb250cm9sbGVyQ29uc3RydWN0b3Iuc2hvdWxkTG9hZCkge1xuICAgICAgICAgICAgICAgIHRoaXMucm91dGVyLmxvYWREZWZpbml0aW9uKGRlZmluaXRpb24pO1xuICAgICAgICAgICAgfVxuICAgICAgICB9KTtcbiAgICB9XG4gICAgdW5sb2FkKGhlYWQsIC4uLnJlc3QpIHtcbiAgICAgICAgY29uc3QgaWRlbnRpZmllcnMgPSBBcnJheS5pc0FycmF5KGhlYWQpID8gaGVhZCA6IFtoZWFkLCAuLi5yZXN0XTtcbiAgICAgICAgaWRlbnRpZmllcnMuZm9yRWFjaCgoaWRlbnRpZmllcikgPT4gdGhpcy5yb3V0ZXIudW5sb2FkSWRlbnRpZmllcihpZGVudGlmaWVyKSk7XG4gICAgfVxuICAgIGdldCBjb250cm9sbGVycygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMucm91dGVyLmNvbnRleHRzLm1hcCgoY29udGV4dCkgPT4gY29udGV4dC5jb250cm9sbGVyKTtcbiAgICB9XG4gICAgZ2V0Q29udHJvbGxlckZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpIHtcbiAgICAgICAgY29uc3QgY29udGV4dCA9IHRoaXMucm91dGVyLmdldENvbnRleHRGb3JFbGVtZW50QW5kSWRlbnRpZmllcihlbGVtZW50LCBpZGVudGlmaWVyKTtcbiAgICAgICAgcmV0dXJuIGNvbnRleHQgPyBjb250ZXh0LmNvbnRyb2xsZXIgOiBudWxsO1xuICAgIH1cbiAgICBoYW5kbGVFcnJvcihlcnJvciwgbWVzc2FnZSwgZGV0YWlsKSB7XG4gICAgICAgIHZhciBfYTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZXJyb3IoYCVzXFxuXFxuJW9cXG5cXG4lb2AsIG1lc3NhZ2UsIGVycm9yLCBkZXRhaWwpO1xuICAgICAgICAoX2EgPSB3aW5kb3cub25lcnJvcikgPT09IG51bGwgfHwgX2EgPT09IHZvaWQgMCA/IHZvaWQgMCA6IF9hLmNhbGwod2luZG93LCBtZXNzYWdlLCBcIlwiLCAwLCAwLCBlcnJvcik7XG4gICAgfVxuICAgIGxvZ0Zvcm1hdHRlZE1lc3NhZ2UoaWRlbnRpZmllciwgZnVuY3Rpb25OYW1lLCBkZXRhaWwgPSB7fSkge1xuICAgICAgICBkZXRhaWwgPSBPYmplY3QuYXNzaWduKHsgYXBwbGljYXRpb246IHRoaXMgfSwgZGV0YWlsKTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZ3JvdXBDb2xsYXBzZWQoYCR7aWRlbnRpZmllcn0gIyR7ZnVuY3Rpb25OYW1lfWApO1xuICAgICAgICB0aGlzLmxvZ2dlci5sb2coXCJkZXRhaWxzOlwiLCBPYmplY3QuYXNzaWduKHt9LCBkZXRhaWwpKTtcbiAgICAgICAgdGhpcy5sb2dnZXIuZ3JvdXBFbmQoKTtcbiAgICB9XG59XG5mdW5jdGlvbiBkb21SZWFkeSgpIHtcbiAgICByZXR1cm4gbmV3IFByb21pc2UoKHJlc29sdmUpID0+IHtcbiAgICAgICAgaWYgKGRvY3VtZW50LnJlYWR5U3RhdGUgPT0gXCJsb2FkaW5nXCIpIHtcbiAgICAgICAgICAgIGRvY3VtZW50LmFkZEV2ZW50TGlzdGVuZXIoXCJET01Db250ZW50TG9hZGVkXCIsICgpID0+IHJlc29sdmUoKSk7XG4gICAgICAgIH1cbiAgICAgICAgZWxzZSB7XG4gICAgICAgICAgICByZXNvbHZlKCk7XG4gICAgICAgIH1cbiAgICB9KTtcbn1cblxuZnVuY3Rpb24gQ2xhc3NQcm9wZXJ0aWVzQmxlc3NpbmcoY29uc3RydWN0b3IpIHtcbiAgICBjb25zdCBjbGFzc2VzID0gcmVhZEluaGVyaXRhYmxlU3RhdGljQXJyYXlWYWx1ZXMoY29uc3RydWN0b3IsIFwiY2xhc3Nlc1wiKTtcbiAgICByZXR1cm4gY2xhc3Nlcy5yZWR1Y2UoKHByb3BlcnRpZXMsIGNsYXNzRGVmaW5pdGlvbikgPT4ge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihwcm9wZXJ0aWVzLCBwcm9wZXJ0aWVzRm9yQ2xhc3NEZWZpbml0aW9uKGNsYXNzRGVmaW5pdGlvbikpO1xuICAgIH0sIHt9KTtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JDbGFzc0RlZmluaXRpb24oa2V5KSB7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake2tleX1DbGFzc2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgY29uc3QgeyBjbGFzc2VzIH0gPSB0aGlzO1xuICAgICAgICAgICAgICAgIGlmIChjbGFzc2VzLmhhcyhrZXkpKSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBjbGFzc2VzLmdldChrZXkpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgY29uc3QgYXR0cmlidXRlID0gY2xhc3Nlcy5nZXRBdHRyaWJ1dGVOYW1lKGtleSk7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyBhdHRyaWJ1dGUgXCIke2F0dHJpYnV0ZX1cImApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtrZXl9Q2xhc3Nlc2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMuY2xhc3Nlcy5nZXRBbGwoa2V5KTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKGtleSl9Q2xhc3NgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLmNsYXNzZXMuaGFzKGtleSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5cbmZ1bmN0aW9uIE91dGxldFByb3BlcnRpZXNCbGVzc2luZyhjb25zdHJ1Y3Rvcikge1xuICAgIGNvbnN0IG91dGxldHMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNBcnJheVZhbHVlcyhjb25zdHJ1Y3RvciwgXCJvdXRsZXRzXCIpO1xuICAgIHJldHVybiBvdXRsZXRzLnJlZHVjZSgocHJvcGVydGllcywgb3V0bGV0RGVmaW5pdGlvbikgPT4ge1xuICAgICAgICByZXR1cm4gT2JqZWN0LmFzc2lnbihwcm9wZXJ0aWVzLCBwcm9wZXJ0aWVzRm9yT3V0bGV0RGVmaW5pdGlvbihvdXRsZXREZWZpbml0aW9uKSk7XG4gICAgfSwge30pO1xufVxuZnVuY3Rpb24gZ2V0T3V0bGV0Q29udHJvbGxlcihjb250cm9sbGVyLCBlbGVtZW50LCBpZGVudGlmaWVyKSB7XG4gICAgcmV0dXJuIGNvbnRyb2xsZXIuYXBwbGljYXRpb24uZ2V0Q29udHJvbGxlckZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIGlkZW50aWZpZXIpO1xufVxuZnVuY3Rpb24gZ2V0Q29udHJvbGxlckFuZEVuc3VyZUNvbm5lY3RlZFNjb3BlKGNvbnRyb2xsZXIsIGVsZW1lbnQsIG91dGxldE5hbWUpIHtcbiAgICBsZXQgb3V0bGV0Q29udHJvbGxlciA9IGdldE91dGxldENvbnRyb2xsZXIoY29udHJvbGxlciwgZWxlbWVudCwgb3V0bGV0TmFtZSk7XG4gICAgaWYgKG91dGxldENvbnRyb2xsZXIpXG4gICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xuICAgIGNvbnRyb2xsZXIuYXBwbGljYXRpb24ucm91dGVyLnByb3Bvc2VUb0Nvbm5lY3RTY29wZUZvckVsZW1lbnRBbmRJZGVudGlmaWVyKGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIG91dGxldENvbnRyb2xsZXIgPSBnZXRPdXRsZXRDb250cm9sbGVyKGNvbnRyb2xsZXIsIGVsZW1lbnQsIG91dGxldE5hbWUpO1xuICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICByZXR1cm4gb3V0bGV0Q29udHJvbGxlcjtcbn1cbmZ1bmN0aW9uIHByb3BlcnRpZXNGb3JPdXRsZXREZWZpbml0aW9uKG5hbWUpIHtcbiAgICBjb25zdCBjYW1lbGl6ZWROYW1lID0gbmFtZXNwYWNlQ2FtZWxpemUobmFtZSk7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake2NhbWVsaXplZE5hbWV9T3V0bGV0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRFbGVtZW50ID0gdGhpcy5vdXRsZXRzLmZpbmQobmFtZSk7XG4gICAgICAgICAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLm91dGxldHMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmIChvdXRsZXRFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IG91dGxldENvbnRyb2xsZXIgPSBnZXRDb250cm9sbGVyQW5kRW5zdXJlQ29ubmVjdGVkU2NvcGUodGhpcywgb3V0bGV0RWxlbWVudCwgbmFtZSk7XG4gICAgICAgICAgICAgICAgICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICAgICAgICAgICAgICAgICAgcmV0dXJuIG91dGxldENvbnRyb2xsZXI7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgVGhlIHByb3ZpZGVkIG91dGxldCBlbGVtZW50IGlzIG1pc3NpbmcgYW4gb3V0bGV0IGNvbnRyb2xsZXIgXCIke25hbWV9XCIgaW5zdGFuY2UgZm9yIGhvc3QgY29udHJvbGxlciBcIiR7dGhpcy5pZGVudGlmaWVyfVwiYCk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihgTWlzc2luZyBvdXRsZXQgZWxlbWVudCBcIiR7bmFtZX1cIiBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCIuIFN0aW11bHVzIGNvdWxkbid0IGZpbmQgYSBtYXRjaGluZyBvdXRsZXQgZWxlbWVudCB1c2luZyBzZWxlY3RvciBcIiR7c2VsZWN0b3J9XCIuYCk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRzYF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRzID0gdGhpcy5vdXRsZXRzLmZpbmRBbGwobmFtZSk7XG4gICAgICAgICAgICAgICAgaWYgKG91dGxldHMubGVuZ3RoID4gMCkge1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gb3V0bGV0c1xuICAgICAgICAgICAgICAgICAgICAgICAgLm1hcCgob3V0bGV0RWxlbWVudCkgPT4ge1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc3Qgb3V0bGV0Q29udHJvbGxlciA9IGdldENvbnRyb2xsZXJBbmRFbnN1cmVDb25uZWN0ZWRTY29wZSh0aGlzLCBvdXRsZXRFbGVtZW50LCBuYW1lKTtcbiAgICAgICAgICAgICAgICAgICAgICAgIGlmIChvdXRsZXRDb250cm9sbGVyKVxuICAgICAgICAgICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRDb250cm9sbGVyO1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc29sZS53YXJuKGBUaGUgcHJvdmlkZWQgb3V0bGV0IGVsZW1lbnQgaXMgbWlzc2luZyBhbiBvdXRsZXQgY29udHJvbGxlciBcIiR7bmFtZX1cIiBpbnN0YW5jZSBmb3IgaG9zdCBjb250cm9sbGVyIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCJgLCBvdXRsZXRFbGVtZW50KTtcbiAgICAgICAgICAgICAgICAgICAgfSlcbiAgICAgICAgICAgICAgICAgICAgICAgIC5maWx0ZXIoKGNvbnRyb2xsZXIpID0+IGNvbnRyb2xsZXIpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICByZXR1cm4gW107XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRFbGVtZW50YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCBvdXRsZXRFbGVtZW50ID0gdGhpcy5vdXRsZXRzLmZpbmQobmFtZSk7XG4gICAgICAgICAgICAgICAgY29uc3Qgc2VsZWN0b3IgPSB0aGlzLm91dGxldHMuZ2V0U2VsZWN0b3JGb3JPdXRsZXROYW1lKG5hbWUpO1xuICAgICAgICAgICAgICAgIGlmIChvdXRsZXRFbGVtZW50KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiBvdXRsZXRFbGVtZW50O1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGBNaXNzaW5nIG91dGxldCBlbGVtZW50IFwiJHtuYW1lfVwiIGZvciBob3N0IGNvbnRyb2xsZXIgXCIke3RoaXMuaWRlbnRpZmllcn1cIi4gU3RpbXVsdXMgY291bGRuJ3QgZmluZCBhIG1hdGNoaW5nIG91dGxldCBlbGVtZW50IHVzaW5nIHNlbGVjdG9yIFwiJHtzZWxlY3Rvcn1cIi5gKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYCR7Y2FtZWxpemVkTmFtZX1PdXRsZXRFbGVtZW50c2BdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMub3V0bGV0cy5maW5kQWxsKG5hbWUpO1xuICAgICAgICAgICAgfSxcbiAgICAgICAgfSxcbiAgICAgICAgW2BoYXMke2NhcGl0YWxpemUoY2FtZWxpemVkTmFtZSl9T3V0bGV0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdGhpcy5vdXRsZXRzLmhhcyhuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbn1cblxuZnVuY3Rpb24gVGFyZ2V0UHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgdGFyZ2V0cyA9IHJlYWRJbmhlcml0YWJsZVN0YXRpY0FycmF5VmFsdWVzKGNvbnN0cnVjdG9yLCBcInRhcmdldHNcIik7XG4gICAgcmV0dXJuIHRhcmdldHMucmVkdWNlKChwcm9wZXJ0aWVzLCB0YXJnZXREZWZpbml0aW9uKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JUYXJnZXREZWZpbml0aW9uKHRhcmdldERlZmluaXRpb24pKTtcbiAgICB9LCB7fSk7XG59XG5mdW5jdGlvbiBwcm9wZXJ0aWVzRm9yVGFyZ2V0RGVmaW5pdGlvbihuYW1lKSB7XG4gICAgcmV0dXJuIHtcbiAgICAgICAgW2Ake25hbWV9VGFyZ2V0YF06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCB0YXJnZXQgPSB0aGlzLnRhcmdldHMuZmluZChuYW1lKTtcbiAgICAgICAgICAgICAgICBpZiAodGFyZ2V0KSB7XG4gICAgICAgICAgICAgICAgICAgIHJldHVybiB0YXJnZXQ7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICB0aHJvdyBuZXcgRXJyb3IoYE1pc3NpbmcgdGFyZ2V0IGVsZW1lbnQgXCIke25hbWV9XCIgZm9yIFwiJHt0aGlzLmlkZW50aWZpZXJ9XCIgY29udHJvbGxlcmApO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgJHtuYW1lfVRhcmdldHNgXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLnRhcmdldHMuZmluZEFsbChuYW1lKTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgICAgIFtgaGFzJHtjYXBpdGFsaXplKG5hbWUpfVRhcmdldGBdOiB7XG4gICAgICAgICAgICBnZXQoKSB7XG4gICAgICAgICAgICAgICAgcmV0dXJuIHRoaXMudGFyZ2V0cy5oYXMobmFtZSk7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5cbmZ1bmN0aW9uIFZhbHVlUHJvcGVydGllc0JsZXNzaW5nKGNvbnN0cnVjdG9yKSB7XG4gICAgY29uc3QgdmFsdWVEZWZpbml0aW9uUGFpcnMgPSByZWFkSW5oZXJpdGFibGVTdGF0aWNPYmplY3RQYWlycyhjb25zdHJ1Y3RvciwgXCJ2YWx1ZXNcIik7XG4gICAgY29uc3QgcHJvcGVydHlEZXNjcmlwdG9yTWFwID0ge1xuICAgICAgICB2YWx1ZURlc2NyaXB0b3JNYXA6IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICByZXR1cm4gdmFsdWVEZWZpbml0aW9uUGFpcnMucmVkdWNlKChyZXN1bHQsIHZhbHVlRGVmaW5pdGlvblBhaXIpID0+IHtcbiAgICAgICAgICAgICAgICAgICAgY29uc3QgdmFsdWVEZXNjcmlwdG9yID0gcGFyc2VWYWx1ZURlZmluaXRpb25QYWlyKHZhbHVlRGVmaW5pdGlvblBhaXIsIHRoaXMuaWRlbnRpZmllcik7XG4gICAgICAgICAgICAgICAgICAgIGNvbnN0IGF0dHJpYnV0ZU5hbWUgPSB0aGlzLmRhdGEuZ2V0QXR0cmlidXRlTmFtZUZvcktleSh2YWx1ZURlc2NyaXB0b3Iua2V5KTtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIE9iamVjdC5hc3NpZ24ocmVzdWx0LCB7IFthdHRyaWJ1dGVOYW1lXTogdmFsdWVEZXNjcmlwdG9yIH0pO1xuICAgICAgICAgICAgICAgIH0sIHt9KTtcbiAgICAgICAgICAgIH0sXG4gICAgICAgIH0sXG4gICAgfTtcbiAgICByZXR1cm4gdmFsdWVEZWZpbml0aW9uUGFpcnMucmVkdWNlKChwcm9wZXJ0aWVzLCB2YWx1ZURlZmluaXRpb25QYWlyKSA9PiB7XG4gICAgICAgIHJldHVybiBPYmplY3QuYXNzaWduKHByb3BlcnRpZXMsIHByb3BlcnRpZXNGb3JWYWx1ZURlZmluaXRpb25QYWlyKHZhbHVlRGVmaW5pdGlvblBhaXIpKTtcbiAgICB9LCBwcm9wZXJ0eURlc2NyaXB0b3JNYXApO1xufVxuZnVuY3Rpb24gcHJvcGVydGllc0ZvclZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpciwgY29udHJvbGxlcikge1xuICAgIGNvbnN0IGRlZmluaXRpb24gPSBwYXJzZVZhbHVlRGVmaW5pdGlvblBhaXIodmFsdWVEZWZpbml0aW9uUGFpciwgY29udHJvbGxlcik7XG4gICAgY29uc3QgeyBrZXksIG5hbWUsIHJlYWRlcjogcmVhZCwgd3JpdGVyOiB3cml0ZSB9ID0gZGVmaW5pdGlvbjtcbiAgICByZXR1cm4ge1xuICAgICAgICBbbmFtZV06IHtcbiAgICAgICAgICAgIGdldCgpIHtcbiAgICAgICAgICAgICAgICBjb25zdCB2YWx1ZSA9IHRoaXMuZGF0YS5nZXQoa2V5KTtcbiAgICAgICAgICAgICAgICBpZiAodmFsdWUgIT09IG51bGwpIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIHJlYWQodmFsdWUpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBlbHNlIHtcbiAgICAgICAgICAgICAgICAgICAgcmV0dXJuIGRlZmluaXRpb24uZGVmYXVsdFZhbHVlO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgIH0sXG4gICAgICAgICAgICBzZXQodmFsdWUpIHtcbiAgICAgICAgICAgICAgICBpZiAodmFsdWUgPT09IHVuZGVmaW5lZCkge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRhdGEuZGVsZXRlKGtleSk7XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgIGVsc2Uge1xuICAgICAgICAgICAgICAgICAgICB0aGlzLmRhdGEuc2V0KGtleSwgd3JpdGUodmFsdWUpKTtcbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgICAgICBbYGhhcyR7Y2FwaXRhbGl6ZShuYW1lKX1gXToge1xuICAgICAgICAgICAgZ2V0KCkge1xuICAgICAgICAgICAgICAgIHJldHVybiB0aGlzLmRhdGEuaGFzKGtleSkgfHwgZGVmaW5pdGlvbi5oYXNDdXN0b21EZWZhdWx0VmFsdWU7XG4gICAgICAgICAgICB9LFxuICAgICAgICB9LFxuICAgIH07XG59XG5mdW5jdGlvbiBwYXJzZVZhbHVlRGVmaW5pdGlvblBhaXIoW3Rva2VuLCB0eXBlRGVmaW5pdGlvbl0sIGNvbnRyb2xsZXIpIHtcbiAgICByZXR1cm4gdmFsdWVEZXNjcmlwdG9yRm9yVG9rZW5BbmRUeXBlRGVmaW5pdGlvbih7XG4gICAgICAgIGNvbnRyb2xsZXIsXG4gICAgICAgIHRva2VuLFxuICAgICAgICB0eXBlRGVmaW5pdGlvbixcbiAgICB9KTtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQoY29uc3RhbnQpIHtcbiAgICBzd2l0Y2ggKGNvbnN0YW50KSB7XG4gICAgICAgIGNhc2UgQXJyYXk6XG4gICAgICAgICAgICByZXR1cm4gXCJhcnJheVwiO1xuICAgICAgICBjYXNlIEJvb2xlYW46XG4gICAgICAgICAgICByZXR1cm4gXCJib29sZWFuXCI7XG4gICAgICAgIGNhc2UgTnVtYmVyOlxuICAgICAgICAgICAgcmV0dXJuIFwibnVtYmVyXCI7XG4gICAgICAgIGNhc2UgT2JqZWN0OlxuICAgICAgICAgICAgcmV0dXJuIFwib2JqZWN0XCI7XG4gICAgICAgIGNhc2UgU3RyaW5nOlxuICAgICAgICAgICAgcmV0dXJuIFwic3RyaW5nXCI7XG4gICAgfVxufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KGRlZmF1bHRWYWx1ZSkge1xuICAgIHN3aXRjaCAodHlwZW9mIGRlZmF1bHRWYWx1ZSkge1xuICAgICAgICBjYXNlIFwiYm9vbGVhblwiOlxuICAgICAgICAgICAgcmV0dXJuIFwiYm9vbGVhblwiO1xuICAgICAgICBjYXNlIFwibnVtYmVyXCI6XG4gICAgICAgICAgICByZXR1cm4gXCJudW1iZXJcIjtcbiAgICAgICAgY2FzZSBcInN0cmluZ1wiOlxuICAgICAgICAgICAgcmV0dXJuIFwic3RyaW5nXCI7XG4gICAgfVxuICAgIGlmIChBcnJheS5pc0FycmF5KGRlZmF1bHRWYWx1ZSkpXG4gICAgICAgIHJldHVybiBcImFycmF5XCI7XG4gICAgaWYgKE9iamVjdC5wcm90b3R5cGUudG9TdHJpbmcuY2FsbChkZWZhdWx0VmFsdWUpID09PSBcIltvYmplY3QgT2JqZWN0XVwiKVxuICAgICAgICByZXR1cm4gXCJvYmplY3RcIjtcbn1cbmZ1bmN0aW9uIHBhcnNlVmFsdWVUeXBlT2JqZWN0KHBheWxvYWQpIHtcbiAgICBjb25zdCB7IGNvbnRyb2xsZXIsIHRva2VuLCB0eXBlT2JqZWN0IH0gPSBwYXlsb2FkO1xuICAgIGNvbnN0IGhhc1R5cGUgPSBpc1NvbWV0aGluZyh0eXBlT2JqZWN0LnR5cGUpO1xuICAgIGNvbnN0IGhhc0RlZmF1bHQgPSBpc1NvbWV0aGluZyh0eXBlT2JqZWN0LmRlZmF1bHQpO1xuICAgIGNvbnN0IGZ1bGxPYmplY3QgPSBoYXNUeXBlICYmIGhhc0RlZmF1bHQ7XG4gICAgY29uc3Qgb25seVR5cGUgPSBoYXNUeXBlICYmICFoYXNEZWZhdWx0O1xuICAgIGNvbnN0IG9ubHlEZWZhdWx0ID0gIWhhc1R5cGUgJiYgaGFzRGVmYXVsdDtcbiAgICBjb25zdCB0eXBlRnJvbU9iamVjdCA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZU9iamVjdC50eXBlKTtcbiAgICBjb25zdCB0eXBlRnJvbURlZmF1bHRWYWx1ZSA9IHBhcnNlVmFsdWVUeXBlRGVmYXVsdChwYXlsb2FkLnR5cGVPYmplY3QuZGVmYXVsdCk7XG4gICAgaWYgKG9ubHlUeXBlKVxuICAgICAgICByZXR1cm4gdHlwZUZyb21PYmplY3Q7XG4gICAgaWYgKG9ubHlEZWZhdWx0KVxuICAgICAgICByZXR1cm4gdHlwZUZyb21EZWZhdWx0VmFsdWU7XG4gICAgaWYgKHR5cGVGcm9tT2JqZWN0ICE9PSB0eXBlRnJvbURlZmF1bHRWYWx1ZSkge1xuICAgICAgICBjb25zdCBwcm9wZXJ0eVBhdGggPSBjb250cm9sbGVyID8gYCR7Y29udHJvbGxlcn0uJHt0b2tlbn1gIDogdG9rZW47XG4gICAgICAgIHRocm93IG5ldyBFcnJvcihgVGhlIHNwZWNpZmllZCBkZWZhdWx0IHZhbHVlIGZvciB0aGUgU3RpbXVsdXMgVmFsdWUgXCIke3Byb3BlcnR5UGF0aH1cIiBtdXN0IG1hdGNoIHRoZSBkZWZpbmVkIHR5cGUgXCIke3R5cGVGcm9tT2JqZWN0fVwiLiBUaGUgcHJvdmlkZWQgZGVmYXVsdCB2YWx1ZSBvZiBcIiR7dHlwZU9iamVjdC5kZWZhdWx0fVwiIGlzIG9mIHR5cGUgXCIke3R5cGVGcm9tRGVmYXVsdFZhbHVlfVwiLmApO1xuICAgIH1cbiAgICBpZiAoZnVsbE9iamVjdClcbiAgICAgICAgcmV0dXJuIHR5cGVGcm9tT2JqZWN0O1xufVxuZnVuY3Rpb24gcGFyc2VWYWx1ZVR5cGVEZWZpbml0aW9uKHBheWxvYWQpIHtcbiAgICBjb25zdCB7IGNvbnRyb2xsZXIsIHRva2VuLCB0eXBlRGVmaW5pdGlvbiB9ID0gcGF5bG9hZDtcbiAgICBjb25zdCB0eXBlT2JqZWN0ID0geyBjb250cm9sbGVyLCB0b2tlbiwgdHlwZU9iamVjdDogdHlwZURlZmluaXRpb24gfTtcbiAgICBjb25zdCB0eXBlRnJvbU9iamVjdCA9IHBhcnNlVmFsdWVUeXBlT2JqZWN0KHR5cGVPYmplY3QpO1xuICAgIGNvbnN0IHR5cGVGcm9tRGVmYXVsdFZhbHVlID0gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KHR5cGVEZWZpbml0aW9uKTtcbiAgICBjb25zdCB0eXBlRnJvbUNvbnN0YW50ID0gcGFyc2VWYWx1ZVR5cGVDb25zdGFudCh0eXBlRGVmaW5pdGlvbik7XG4gICAgY29uc3QgdHlwZSA9IHR5cGVGcm9tT2JqZWN0IHx8IHR5cGVGcm9tRGVmYXVsdFZhbHVlIHx8IHR5cGVGcm9tQ29uc3RhbnQ7XG4gICAgaWYgKHR5cGUpXG4gICAgICAgIHJldHVybiB0eXBlO1xuICAgIGNvbnN0IHByb3BlcnR5UGF0aCA9IGNvbnRyb2xsZXIgPyBgJHtjb250cm9sbGVyfS4ke3R5cGVEZWZpbml0aW9ufWAgOiB0b2tlbjtcbiAgICB0aHJvdyBuZXcgRXJyb3IoYFVua25vd24gdmFsdWUgdHlwZSBcIiR7cHJvcGVydHlQYXRofVwiIGZvciBcIiR7dG9rZW59XCIgdmFsdWVgKTtcbn1cbmZ1bmN0aW9uIGRlZmF1bHRWYWx1ZUZvckRlZmluaXRpb24odHlwZURlZmluaXRpb24pIHtcbiAgICBjb25zdCBjb25zdGFudCA9IHBhcnNlVmFsdWVUeXBlQ29uc3RhbnQodHlwZURlZmluaXRpb24pO1xuICAgIGlmIChjb25zdGFudClcbiAgICAgICAgcmV0dXJuIGRlZmF1bHRWYWx1ZXNCeVR5cGVbY29uc3RhbnRdO1xuICAgIGNvbnN0IGhhc0RlZmF1bHQgPSBoYXNQcm9wZXJ0eSh0eXBlRGVmaW5pdGlvbiwgXCJkZWZhdWx0XCIpO1xuICAgIGNvbnN0IGhhc1R5cGUgPSBoYXNQcm9wZXJ0eSh0eXBlRGVmaW5pdGlvbiwgXCJ0eXBlXCIpO1xuICAgIGNvbnN0IHR5cGVPYmplY3QgPSB0eXBlRGVmaW5pdGlvbjtcbiAgICBpZiAoaGFzRGVmYXVsdClcbiAgICAgICAgcmV0dXJuIHR5cGVPYmplY3QuZGVmYXVsdDtcbiAgICBpZiAoaGFzVHlwZSkge1xuICAgICAgICBjb25zdCB7IHR5cGUgfSA9IHR5cGVPYmplY3Q7XG4gICAgICAgIGNvbnN0IGNvbnN0YW50RnJvbVR5cGUgPSBwYXJzZVZhbHVlVHlwZUNvbnN0YW50KHR5cGUpO1xuICAgICAgICBpZiAoY29uc3RhbnRGcm9tVHlwZSlcbiAgICAgICAgICAgIHJldHVybiBkZWZhdWx0VmFsdWVzQnlUeXBlW2NvbnN0YW50RnJvbVR5cGVdO1xuICAgIH1cbiAgICByZXR1cm4gdHlwZURlZmluaXRpb247XG59XG5mdW5jdGlvbiB2YWx1ZURlc2NyaXB0b3JGb3JUb2tlbkFuZFR5cGVEZWZpbml0aW9uKHBheWxvYWQpIHtcbiAgICBjb25zdCB7IHRva2VuLCB0eXBlRGVmaW5pdGlvbiB9ID0gcGF5bG9hZDtcbiAgICBjb25zdCBrZXkgPSBgJHtkYXNoZXJpemUodG9rZW4pfS12YWx1ZWA7XG4gICAgY29uc3QgdHlwZSA9IHBhcnNlVmFsdWVUeXBlRGVmaW5pdGlvbihwYXlsb2FkKTtcbiAgICByZXR1cm4ge1xuICAgICAgICB0eXBlLFxuICAgICAgICBrZXksXG4gICAgICAgIG5hbWU6IGNhbWVsaXplKGtleSksXG4gICAgICAgIGdldCBkZWZhdWx0VmFsdWUoKSB7XG4gICAgICAgICAgICByZXR1cm4gZGVmYXVsdFZhbHVlRm9yRGVmaW5pdGlvbih0eXBlRGVmaW5pdGlvbik7XG4gICAgICAgIH0sXG4gICAgICAgIGdldCBoYXNDdXN0b21EZWZhdWx0VmFsdWUoKSB7XG4gICAgICAgICAgICByZXR1cm4gcGFyc2VWYWx1ZVR5cGVEZWZhdWx0KHR5cGVEZWZpbml0aW9uKSAhPT0gdW5kZWZpbmVkO1xuICAgICAgICB9LFxuICAgICAgICByZWFkZXI6IHJlYWRlcnNbdHlwZV0sXG4gICAgICAgIHdyaXRlcjogd3JpdGVyc1t0eXBlXSB8fCB3cml0ZXJzLmRlZmF1bHQsXG4gICAgfTtcbn1cbmNvbnN0IGRlZmF1bHRWYWx1ZXNCeVR5cGUgPSB7XG4gICAgZ2V0IGFycmF5KCkge1xuICAgICAgICByZXR1cm4gW107XG4gICAgfSxcbiAgICBib29sZWFuOiBmYWxzZSxcbiAgICBudW1iZXI6IDAsXG4gICAgZ2V0IG9iamVjdCgpIHtcbiAgICAgICAgcmV0dXJuIHt9O1xuICAgIH0sXG4gICAgc3RyaW5nOiBcIlwiLFxufTtcbmNvbnN0IHJlYWRlcnMgPSB7XG4gICAgYXJyYXkodmFsdWUpIHtcbiAgICAgICAgY29uc3QgYXJyYXkgPSBKU09OLnBhcnNlKHZhbHVlKTtcbiAgICAgICAgaWYgKCFBcnJheS5pc0FycmF5KGFycmF5KSkge1xuICAgICAgICAgICAgdGhyb3cgbmV3IFR5cGVFcnJvcihgZXhwZWN0ZWQgdmFsdWUgb2YgdHlwZSBcImFycmF5XCIgYnV0IGluc3RlYWQgZ290IHZhbHVlIFwiJHt2YWx1ZX1cIiBvZiB0eXBlIFwiJHtwYXJzZVZhbHVlVHlwZURlZmF1bHQoYXJyYXkpfVwiYCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIGFycmF5O1xuICAgIH0sXG4gICAgYm9vbGVhbih2YWx1ZSkge1xuICAgICAgICByZXR1cm4gISh2YWx1ZSA9PSBcIjBcIiB8fCBTdHJpbmcodmFsdWUpLnRvTG93ZXJDYXNlKCkgPT0gXCJmYWxzZVwiKTtcbiAgICB9LFxuICAgIG51bWJlcih2YWx1ZSkge1xuICAgICAgICByZXR1cm4gTnVtYmVyKHZhbHVlLnJlcGxhY2UoL18vZywgXCJcIikpO1xuICAgIH0sXG4gICAgb2JqZWN0KHZhbHVlKSB7XG4gICAgICAgIGNvbnN0IG9iamVjdCA9IEpTT04ucGFyc2UodmFsdWUpO1xuICAgICAgICBpZiAob2JqZWN0ID09PSBudWxsIHx8IHR5cGVvZiBvYmplY3QgIT0gXCJvYmplY3RcIiB8fCBBcnJheS5pc0FycmF5KG9iamVjdCkpIHtcbiAgICAgICAgICAgIHRocm93IG5ldyBUeXBlRXJyb3IoYGV4cGVjdGVkIHZhbHVlIG9mIHR5cGUgXCJvYmplY3RcIiBidXQgaW5zdGVhZCBnb3QgdmFsdWUgXCIke3ZhbHVlfVwiIG9mIHR5cGUgXCIke3BhcnNlVmFsdWVUeXBlRGVmYXVsdChvYmplY3QpfVwiYCk7XG4gICAgICAgIH1cbiAgICAgICAgcmV0dXJuIG9iamVjdDtcbiAgICB9LFxuICAgIHN0cmluZyh2YWx1ZSkge1xuICAgICAgICByZXR1cm4gdmFsdWU7XG4gICAgfSxcbn07XG5jb25zdCB3cml0ZXJzID0ge1xuICAgIGRlZmF1bHQ6IHdyaXRlU3RyaW5nLFxuICAgIGFycmF5OiB3cml0ZUpTT04sXG4gICAgb2JqZWN0OiB3cml0ZUpTT04sXG59O1xuZnVuY3Rpb24gd3JpdGVKU09OKHZhbHVlKSB7XG4gICAgcmV0dXJuIEpTT04uc3RyaW5naWZ5KHZhbHVlKTtcbn1cbmZ1bmN0aW9uIHdyaXRlU3RyaW5nKHZhbHVlKSB7XG4gICAgcmV0dXJuIGAke3ZhbHVlfWA7XG59XG5cbmNsYXNzIENvbnRyb2xsZXIge1xuICAgIGNvbnN0cnVjdG9yKGNvbnRleHQpIHtcbiAgICAgICAgdGhpcy5jb250ZXh0ID0gY29udGV4dDtcbiAgICB9XG4gICAgc3RhdGljIGdldCBzaG91bGRMb2FkKCkge1xuICAgICAgICByZXR1cm4gdHJ1ZTtcbiAgICB9XG4gICAgc3RhdGljIGFmdGVyTG9hZChfaWRlbnRpZmllciwgX2FwcGxpY2F0aW9uKSB7XG4gICAgICAgIHJldHVybjtcbiAgICB9XG4gICAgZ2V0IGFwcGxpY2F0aW9uKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5jb250ZXh0LmFwcGxpY2F0aW9uO1xuICAgIH1cbiAgICBnZXQgc2NvcGUoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLmNvbnRleHQuc2NvcGU7XG4gICAgfVxuICAgIGdldCBlbGVtZW50KCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5lbGVtZW50O1xuICAgIH1cbiAgICBnZXQgaWRlbnRpZmllcigpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuaWRlbnRpZmllcjtcbiAgICB9XG4gICAgZ2V0IHRhcmdldHMoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLnRhcmdldHM7XG4gICAgfVxuICAgIGdldCBvdXRsZXRzKCkge1xuICAgICAgICByZXR1cm4gdGhpcy5zY29wZS5vdXRsZXRzO1xuICAgIH1cbiAgICBnZXQgY2xhc3NlcygpIHtcbiAgICAgICAgcmV0dXJuIHRoaXMuc2NvcGUuY2xhc3NlcztcbiAgICB9XG4gICAgZ2V0IGRhdGEoKSB7XG4gICAgICAgIHJldHVybiB0aGlzLnNjb3BlLmRhdGE7XG4gICAgfVxuICAgIGluaXRpYWxpemUoKSB7XG4gICAgfVxuICAgIGNvbm5lY3QoKSB7XG4gICAgfVxuICAgIGRpc2Nvbm5lY3QoKSB7XG4gICAgfVxuICAgIGRpc3BhdGNoKGV2ZW50TmFtZSwgeyB0YXJnZXQgPSB0aGlzLmVsZW1lbnQsIGRldGFpbCA9IHt9LCBwcmVmaXggPSB0aGlzLmlkZW50aWZpZXIsIGJ1YmJsZXMgPSB0cnVlLCBjYW5jZWxhYmxlID0gdHJ1ZSwgfSA9IHt9KSB7XG4gICAgICAgIGNvbnN0IHR5cGUgPSBwcmVmaXggPyBgJHtwcmVmaXh9OiR7ZXZlbnROYW1lfWAgOiBldmVudE5hbWU7XG4gICAgICAgIGNvbnN0IGV2ZW50ID0gbmV3IEN1c3RvbUV2ZW50KHR5cGUsIHsgZGV0YWlsLCBidWJibGVzLCBjYW5jZWxhYmxlIH0pO1xuICAgICAgICB0YXJnZXQuZGlzcGF0Y2hFdmVudChldmVudCk7XG4gICAgICAgIHJldHVybiBldmVudDtcbiAgICB9XG59XG5Db250cm9sbGVyLmJsZXNzaW5ncyA9IFtcbiAgICBDbGFzc1Byb3BlcnRpZXNCbGVzc2luZyxcbiAgICBUYXJnZXRQcm9wZXJ0aWVzQmxlc3NpbmcsXG4gICAgVmFsdWVQcm9wZXJ0aWVzQmxlc3NpbmcsXG4gICAgT3V0bGV0UHJvcGVydGllc0JsZXNzaW5nLFxuXTtcbkNvbnRyb2xsZXIudGFyZ2V0cyA9IFtdO1xuQ29udHJvbGxlci5vdXRsZXRzID0gW107XG5Db250cm9sbGVyLnZhbHVlcyA9IHt9O1xuXG5leHBvcnQgeyBBcHBsaWNhdGlvbiwgQXR0cmlidXRlT2JzZXJ2ZXIsIENvbnRleHQsIENvbnRyb2xsZXIsIEVsZW1lbnRPYnNlcnZlciwgSW5kZXhlZE11bHRpbWFwLCBNdWx0aW1hcCwgU2VsZWN0b3JPYnNlcnZlciwgU3RyaW5nTWFwT2JzZXJ2ZXIsIFRva2VuTGlzdE9ic2VydmVyLCBWYWx1ZUxpc3RPYnNlcnZlciwgYWRkLCBkZWZhdWx0U2NoZW1hLCBkZWwsIGZldGNoLCBwcnVuZSB9O1xuIiwgImltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tIFwiQGhvdHdpcmVkL3N0aW11bHVzXCJcblxuLyoqXG4gKiBTdGltdWx1cyBDb250cm9sbGVyIGZvciBTdHJpcGUgUGF5bWVudCBFbGVtZW50IG9uIE9yZGVyIFBhZ2VcbiAqXG4gKiBIYW5kbGVzIFN0cmlwZSBwYXltZW50IGZvcm0gaW5pdGlhbGl6YXRpb24gYW5kIHN1Ym1pc3Npb24gb24gdGhlIG9yZGVyIGNvbmZpcm1hdGlvbiBwYWdlXG4gKlxuICogVXNhZ2UgaW4gVHdpZzpcbiAqIDxkaXYgZGF0YS1jb250cm9sbGVyPVwic3RyaXBlLW9yZGVyXCJcbiAqICAgICAgZGF0YS1zdHJpcGUtb3JkZXItcHVibGlzaGFibGUta2V5LXZhbHVlPVwicGtfLi4uXCJcbiAqICAgICAgZGF0YS1zdHJpcGUtb3JkZXItY2xpZW50LXNlY3JldC12YWx1ZT1cInBpXy4uLl9zZWNyZXRfLi4uXCI+XG4gKiAgIDxkaXYgaWQ9XCJwYXltZW50LWVsZW1lbnRcIj48L2Rpdj5cbiAqICAgPGRpdiBpZD1cInBheW1lbnQtZXJyb3JzXCIgc3R5bGU9XCJkaXNwbGF5Om5vbmVcIj5cbiAqICAgICA8c3BhbiBkYXRhLXN0cmlwZS1vcmRlci10YXJnZXQ9XCJlcnJvck1lc3NhZ2VcIj48L3NwYW4+XG4gKiAgIDwvZGl2PlxuICogPC9kaXY+XG4gKi9cbmV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgQ29udHJvbGxlciB7XG4gIHN0YXRpYyB2YWx1ZXMgPSB7XG4gICAgcHVibGlzaGFibGVLZXk6IFN0cmluZyxcbiAgICBjbGllbnRTZWNyZXQ6IFN0cmluZ1xuICB9XG5cbiAgc3RhdGljIHRhcmdldHMgPSBbXCJlcnJvck1lc3NhZ2VcIiwgXCJsb2FkaW5nXCJdXG5cbiAgY29ubmVjdCgpIHtcbiAgICBjb25zb2xlLmxvZygnU3RyaXBlIE9yZGVyIGNvbnRyb2xsZXIgY29ubmVjdGVkJywge1xuICAgICAgaGFzUHVibGlzaGFibGVLZXk6ICEhdGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlLFxuICAgICAgcHVibGlzaGFibGVLZXk6IHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSA/IHRoaXMucHVibGlzaGFibGVLZXlWYWx1ZS5zdWJzdHJpbmcoMCwgMTApICsgJy4uLicgOiAnbWlzc2luZycsXG4gICAgfSlcblxuICAgIC8vIEdldCBkZWJ1ZyBpbmZvIGZyb20gZWxlbWVudFxuICAgIGNvbnN0IGRlYnVnSW5mbyA9IHRoaXMuZWxlbWVudC5nZXRBdHRyaWJ1dGUoJ2RhdGEtZGVidWctaW5mbycpXG4gICAgaWYgKGRlYnVnSW5mbykge1xuICAgICAgY29uc29sZS5sb2coJ0RlYnVnIGluZm86JywgZGVidWdJbmZvKVxuICAgIH1cblxuICAgIC8vIFZhbGlkYXRlIHJlcXVpcmVkIGNvbmZpZ3VyYXRpb25cbiAgICBpZiAoIXRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSkge1xuICAgICAgY29uc29sZS5lcnJvcignU3RyaXBlIHB1Ymxpc2hhYmxlIGtleSBub3QgY29uZmlndXJlZCcpXG4gICAgICB0aGlzLnNob3dFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uQ09ORklHX0VSUk9SIHx8ICdTdHJpcGUgY29uZmlndXJhdGlvbiBlcnJvci4gUGxlYXNlIGNvbnRhY3Qgc3VwcG9ydC4nKVxuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgLy8gV2FpdCBmb3IgU3RyaXBlLmpzIHRvIGxvYWRcbiAgICB0aGlzLmluaXRpYWxpemVTdHJpcGUoKVxuICB9XG5cbiAgZGlzY29ubmVjdCgpIHtcbiAgICAvLyBDbGVhbnVwIGlmIG5lZWRlZFxuICAgIGlmICh0aGlzLnBheW1lbnRFbGVtZW50KSB7XG4gICAgICB0aGlzLnBheW1lbnRFbGVtZW50LnVubW91bnQoKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBJbml0aWFsaXplIFN0cmlwZSBhbmQgbW91bnQgUGF5bWVudCBFbGVtZW50XG4gICAqL1xuICBhc3luYyBpbml0aWFsaXplU3RyaXBlKCkge1xuICAgIC8vIFdhaXQgZm9yIFN0cmlwZS5qcyB0byBiZSBhdmFpbGFibGVcbiAgICBpZiAodHlwZW9mIFN0cmlwZSA9PT0gJ3VuZGVmaW5lZCcpIHtcbiAgICAgIGNvbnNvbGUubG9nKCdXYWl0aW5nIGZvciBTdHJpcGUuanMgdG8gbG9hZC4uLicpXG4gICAgICBhd2FpdCB0aGlzLndhaXRGb3JTdHJpcGUoKVxuICAgIH1cblxuICAgIHRyeSB7XG4gICAgICAvLyBJbml0aWFsaXplIFN0cmlwZVxuICAgICAgdGhpcy5zdHJpcGUgPSBTdHJpcGUodGhpcy5wdWJsaXNoYWJsZUtleVZhbHVlKVxuXG4gICAgICAvLyBDcmVhdGUgRWxlbWVudHMgd2l0aCBzdHlsaW5nXG4gICAgICBjb25zdCBhcHBlYXJhbmNlID0ge1xuICAgICAgICB0aGVtZTogJ3N0cmlwZScsXG4gICAgICAgIHZhcmlhYmxlczoge1xuICAgICAgICAgIGNvbG9yUHJpbWFyeTogJyMwNTcwZGUnLFxuICAgICAgICAgIGNvbG9yQmFja2dyb3VuZDogJyNmZmZmZmYnLFxuICAgICAgICAgIGNvbG9yVGV4dDogJyMzMDMxM2QnLFxuICAgICAgICAgIGZvbnRGYW1pbHk6ICdzeXN0ZW0tdWksIHNhbnMtc2VyaWYnLFxuICAgICAgICAgIGJvcmRlclJhZGl1czogJzRweCdcbiAgICAgICAgfVxuICAgICAgfVxuXG4gICAgICB0aGlzLmVsZW1lbnRzID0gdGhpcy5zdHJpcGUuZWxlbWVudHMoe1xuICAgICAgICBhcHBlYXJhbmNlOiBhcHBlYXJhbmNlXG4gICAgICB9KVxuXG4gICAgICB0aGlzLmNhcmQgPSB0aGlzLmVsZW1lbnRzLmNyZWF0ZSgnY2FyZCcpO1xuICAgICAgdGhpcy5jYXJkLm1vdW50KCcjY2FyZC1lbGVtZW50Jyk7XG5cbiAgICAgIGNvbnNvbGUubG9nKCdTdHJpcGUgUGF5bWVudCBFbGVtZW50IGluaXRpYWxpemVkIHN1Y2Nlc3NmdWxseScpXG5cbiAgICB9IGNhdGNoIChlcnJvcikge1xuICAgICAgY29uc29sZS5lcnJvcignRmFpbGVkIHRvIGluaXRpYWxpemUgU3RyaXBlOicsIGVycm9yKVxuICAgICAgdGhpcy5zaG93RXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LklOSVRfRkFJTEVEIHx8ICdGYWlsZWQgdG8gaW5pdGlhbGl6ZSBwYXltZW50IGZvcm0uIFBsZWFzZSByZWZyZXNoIHRoZSBwYWdlLicpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFdhaXQgZm9yIFN0cmlwZS5qcyBsaWJyYXJ5IHRvIGxvYWRcbiAgICogQHJldHVybnMge1Byb21pc2V9XG4gICAqL1xuICB3YWl0Rm9yU3RyaXBlKCkge1xuICAgIHJldHVybiBuZXcgUHJvbWlzZSgocmVzb2x2ZSkgPT4ge1xuICAgICAgY29uc3QgY2hlY2tTdHJpcGUgPSAoKSA9PiB7XG4gICAgICAgIGlmICh0eXBlb2YgU3RyaXBlICE9PSAndW5kZWZpbmVkJykge1xuICAgICAgICAgIHJlc29sdmUoKVxuICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgIHNldFRpbWVvdXQoY2hlY2tTdHJpcGUsIDEwMClcbiAgICAgICAgfVxuICAgICAgfVxuICAgICAgY2hlY2tTdHJpcGUoKVxuICAgIH0pXG4gIH1cblxuICAvKipcbiAgICogU2hvdyBsb2FkaW5nIGluZGljYXRvclxuICAgKi9cbiAgc2hvd0xvYWRpbmcoKSB7XG4gICAgaWYgKHRoaXMuaGFzTG9hZGluZ1RhcmdldCkge1xuICAgICAgdGhpcy5sb2FkaW5nVGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFNob3cgZXJyb3IgbWVzc2FnZVxuICAgKiBAcGFyYW0ge1N0cmluZ30gbWVzc2FnZVxuICAgKi9cbiAgc2hvd0Vycm9yKG1lc3NhZ2UpIHtcbiAgICBjb25zdCBlcnJvckRpdiA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdwYXltZW50LWVycm9ycycpXG4gICAgaWYgKGVycm9yRGl2ICYmIHRoaXMuaGFzRXJyb3JNZXNzYWdlVGFyZ2V0KSB7XG4gICAgICBlcnJvckRpdi5zdHlsZS5kaXNwbGF5ID0gJ2Jsb2NrJ1xuICAgICAgdGhpcy5lcnJvck1lc3NhZ2VUYXJnZXQudGV4dENvbnRlbnQgPSBtZXNzYWdlXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhpZGUgZXJyb3IgbWVzc2FnZVxuICAgKi9cbiAgaGlkZUVycm9yKCkge1xuICAgIGNvbnN0IGVycm9yRGl2ID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ3BheW1lbnQtZXJyb3JzJylcbiAgICBpZiAoZXJyb3JEaXYpIHtcbiAgICAgIGVycm9yRGl2LnN0eWxlLmRpc3BsYXkgPSAnbm9uZSdcbiAgICAgIGlmICh0aGlzLmhhc0Vycm9yTWVzc2FnZVRhcmdldCkge1xuICAgICAgICB0aGlzLmVycm9yTWVzc2FnZVRhcmdldC50ZXh0Q29udGVudCA9ICcnXG4gICAgICB9XG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhpZGUgbG9hZGluZyBpbmRpY2F0b3JcbiAgICovXG4gIGhpZGVMb2FkaW5nKCkge1xuICAgIGlmICh0aGlzLmhhc0xvYWRpbmdUYXJnZXQpIHtcbiAgICAgIHRoaXMubG9hZGluZ1RhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ25vbmUnXG4gICAgfVxuICB9XG5cbn1cbiIsICJpbXBvcnQgeyBDb250cm9sbGVyIH0gZnJvbSBcIkBob3R3aXJlZC9zdGltdWx1c1wiXG5cbi8qKlxuICogU3RpbXVsdXMgQ29udHJvbGxlciBmb3IgT3JkZXIgU3VibWl0IEJ1dHRvblxuICpcbiAqIEhhbmRsZXMgb3JkZXIgc3VibWlzc2lvbiBvbiB0aGUgY2hlY2tvdXQgb3JkZXIgcGFnZS5cbiAqIFN1cHBvcnRzIHR3byBwYXltZW50IGZsb3dzOlxuICogMS4gU3RyaXBlIENoZWNrb3V0IChob3N0ZWQgcGFnZSkgLSBmb3Igd2FsbGV0IHBheW1lbnRzXG4gKiAyLiBQYXltZW50IEludGVudCAoY2FyZCBlbGVtZW50KSAtIGZvciBjYXJkIHBheW1lbnRzXG4gKlxuICogVXNhZ2UgaW4gVHdpZzpcbiAqIDxidXR0b24gZGF0YS1jb250cm9sbGVyPVwib3JkZXItc3VibWl0XCJcbiAqICAgICAgICAgZGF0YS1hY3Rpb249XCJjbGljay0+b3JkZXItc3VibWl0I2hhbmRsZVN1Ym1pdFwiXG4gKiAgICAgICAgIGRhdGEtb3JkZXItc3VibWl0LXVybC12YWx1ZT1cIi4uLlwiXG4gKiAgICAgICAgIGRhdGEtb3JkZXItc3VibWl0LXBheW1lbnQtdHlwZS12YWx1ZT1cIndhbGxldHxjYXJkXCJcbiAqICAgICAgICAgdHlwZT1cImJ1dHRvblwiPlxuICogICBTdWJtaXQgT3JkZXJcbiAqIDwvYnV0dG9uPlxuICovXG5leHBvcnQgZGVmYXVsdCBjbGFzcyBleHRlbmRzIENvbnRyb2xsZXIge1xuICBzdGF0aWMgdGFyZ2V0cyA9IFtcInN0YXR1c1wiXVxuICBzdGF0aWMgdmFsdWVzID0ge1xuICAgIHVybDogU3RyaW5nLFxuICAgIHBheW1lbnRUeXBlOiBTdHJpbmcsXG4gICAgcHVibGlzaGFibGVLZXk6IFN0cmluZ1xuICB9XG5cbiAgLyoqXG4gICAqIENhbGxlZCB3aGVuIGNvbnRyb2xsZXIgaXMgY29ubmVjdGVkIHRvIERPTVxuICAgKi9cbiAgY29ubmVjdCgpIHtcbiAgICBjb25zb2xlLmxvZygnT3JkZXIgU3VibWl0IGNvbnRyb2xsZXIgY29ubmVjdGVkJylcbiAgICBjb25zb2xlLmxvZygnQnV0dG9uIGVsZW1lbnQ6JywgdGhpcy5lbGVtZW50KVxuICB9XG5cbiAgLyoqXG4gICAqIENhbGxlZCB3aGVuIGNvbnRyb2xsZXIgaXMgZGlzY29ubmVjdGVkIGZyb20gRE9NXG4gICAqL1xuICBkaXNjb25uZWN0KCkge1xuICAgIGNvbnNvbGUubG9nKCdPcmRlciBTdWJtaXQgY29udHJvbGxlciBkaXNjb25uZWN0ZWQnKVxuICB9XG5cbiAgLyoqXG4gICAqIEdldCB0aGUgc3RyaXBlLW9yZGVyIGNvbnRyb2xsZXIgaW5zdGFuY2VcbiAgICogQHJldHVybnMge0NvbnRyb2xsZXJ8bnVsbH1cbiAgICovXG4gIGdldFN0cmlwZU9yZGVyQ29udHJvbGxlcigpIHtcbiAgICBjb25zdCBjYXJkRWxlbWVudCA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdjYXJkLWVsZW1lbnQnKVxuICAgIGlmICghY2FyZEVsZW1lbnQpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ0NhcmQgZWxlbWVudCBub3QgZm91bmQnKVxuICAgICAgcmV0dXJuIG51bGxcbiAgICB9XG5cbiAgICBjb25zdCBjb250cm9sbGVyID0gdGhpcy5hcHBsaWNhdGlvbi5nZXRDb250cm9sbGVyRm9yRWxlbWVudEFuZElkZW50aWZpZXIoXG4gICAgICBjYXJkRWxlbWVudCxcbiAgICAgICdzdHJpcGUtb3JkZXInXG4gICAgKVxuXG4gICAgaWYgKCFjb250cm9sbGVyKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdTdHJpcGUgb3JkZXIgY29udHJvbGxlciBub3QgZm91bmQgb24gY2FyZCBlbGVtZW50JylcbiAgICAgIHJldHVybiBudWxsXG4gICAgfVxuXG4gICAgY29uc29sZS5sb2coJ0ZvdW5kIHN0cmlwZS1vcmRlciBjb250cm9sbGVyOicsIGNvbnRyb2xsZXIpXG4gICAgcmV0dXJuIGNvbnRyb2xsZXJcbiAgfVxuXG4gIC8qKlxuICAgKiBIYW5kbGUgb3JkZXIgc3VibWl0IGJ1dHRvbiBjbGlja1xuICAgKiBSb3V0ZXMgdG8gYXBwcm9wcmlhdGUgcGF5bWVudCBmbG93IGJhc2VkIG9uIHBheW1lbnQgdHlwZVxuICAgKiBAcGFyYW0ge0V2ZW50fSBldmVudCAtIFRoZSBjbGljayBldmVudFxuICAgKi9cbiAgYXN5bmMgaGFuZGxlU3VibWl0KGV2ZW50KSB7XG4gICAgZXZlbnQucHJldmVudERlZmF1bHQoKVxuXG4gICAgY29uc29sZS5sb2coJ09yZGVyIHN1Ym1pdCBidXR0b24gY2xpY2tlZCcsIHtcbiAgICAgIGJ1dHRvbklkOiB0aGlzLmVsZW1lbnQuaWQsXG4gICAgICBwYXltZW50VHlwZTogdGhpcy5wYXltZW50VHlwZVZhbHVlLFxuICAgICAgdGltZXN0YW1wOiBuZXcgRGF0ZSgpLnRvSVNPU3RyaW5nKClcbiAgICB9KVxuXG4gICAgdGhpcy5zaG93TG9hZGluZygpXG5cbiAgICB0cnkge1xuICAgICAgLy8gUm91dGUgdG8gYXBwcm9wcmlhdGUgcGF5bWVudCBmbG93XG4gICAgICBpZiAodGhpcy5wYXltZW50VHlwZVZhbHVlID09PSAnd2FsbGV0Jykge1xuICAgICAgICBhd2FpdCB0aGlzLmhhbmRsZVN0cmlwZUNoZWNrb3V0KClcbiAgICAgIH0gZWxzZSB7XG4gICAgICAgIGF3YWl0IHRoaXMuaGFuZGxlUGF5bWVudEludGVudCgpXG4gICAgICB9XG4gICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ09yZGVyIHN1Ym1pc3Npb24gZmFpbGVkJywgZXJyb3IpXG4gICAgICB0aGlzLnNob3dFcnJvcihlcnJvci5tZXNzYWdlIHx8IHdpbmRvdy5vU3RyaXBlPy5pMThuPy5QQVlNRU5UX0ZBSUxFRCB8fCAnUGF5bWVudCBwcm9jZXNzaW5nIGZhaWxlZCcpXG4gICAgfSBmaW5hbGx5IHtcbiAgICAgIHRoaXMuaGlkZUxvYWRpbmcoKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBIYW5kbGUgU3RyaXBlIENoZWNrb3V0IGZsb3cgKGhvc3RlZCBwYXltZW50IHBhZ2UpXG4gICAqIFVzZWQgZm9yIHdhbGxldCBwYXltZW50cyAoQXBwbGUgUGF5LCBHb29nbGUgUGF5KVxuICAgKi9cbiAgYXN5bmMgaGFuZGxlU3RyaXBlQ2hlY2tvdXQoKSB7XG4gICAgaWYgKCF3aW5kb3cuU3RyaXBlKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LkpTX05PVF9MT0FERUQgfHwgJ1N0cmlwZS5qcyBub3QgbG9hZGVkJylcbiAgICB9XG5cbiAgICAvLyBHZXQgU3RyaXBlIHB1Ymxpc2hhYmxlIGtleSBmcm9tIFN0aW11bHVzIHZhbHVlXG4gICAgaWYgKCF0aGlzLmhhc1B1Ymxpc2hhYmxlS2V5VmFsdWUgfHwgIXRoaXMucHVibGlzaGFibGVLZXlWYWx1ZSkge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5LRVlfTk9UX0NPTkZJR1VSRUQgfHwgJ1N0cmlwZSBwdWJsaXNoYWJsZSBrZXkgbm90IGNvbmZpZ3VyZWQnKVxuICAgIH1cblxuICAgIGNvbnN0IHN0cmlwZSA9IFN0cmlwZSh0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUpXG5cbiAgICB0aGlzLnNldFN0YXR1cyh3aW5kb3cub1N0cmlwZT8uaTE4bj8uQ1JFQVRJTkdfU0VTU0lPTiB8fCAnQ3JlYXRpbmcgY2hlY2tvdXQgc2Vzc2lvbi4uLicpXG5cbiAgICAvLyBDcmVhdGUgQ2hlY2tvdXQgU2Vzc2lvbiAoaW5jbHVkZSBzdG9rZW4gZm9yIENTUkYgcHJvdGVjdGlvbilcbiAgICBjb25zdCByZXNwb25zZSA9IGF3YWl0IGZldGNoKHRoaXMuYnVpbGRVcmxXaXRoQ3NyZlRva2VuKHRoaXMudXJsVmFsdWUpLCB7XG4gICAgICBtZXRob2Q6ICdQT1NUJyxcbiAgICAgIGhlYWRlcnM6IHtcbiAgICAgICAgJ0NvbnRlbnQtVHlwZSc6ICdhcHBsaWNhdGlvbi9qc29uJ1xuICAgICAgfSxcbiAgICAgIGJvZHk6IEpTT04uc3RyaW5naWZ5KHtcbiAgICAgICAgY2FwdHVyZTogJ2F1dG9tYXRpYycgLy8gQ2FuIGJlIG1hZGUgY29uZmlndXJhYmxlXG4gICAgICB9KSxcbiAgICAgIGNyZWRlbnRpYWxzOiAnc2FtZS1vcmlnaW4nXG4gICAgfSlcblxuICAgIGlmICghcmVzcG9uc2Uub2spIHtcbiAgICAgIGNvbnN0IGVycm9yRGF0YSA9IGF3YWl0IHJlc3BvbnNlLmpzb24oKS5jYXRjaCgoKSA9PiAoe30pKVxuICAgICAgdGhyb3cgbmV3IEVycm9yKGVycm9yRGF0YS5lcnJvciB8fCB3aW5kb3cub1N0cmlwZT8uaTE4bj8uU0VTU0lPTl9GQUlMRUQgfHwgJ0ZhaWxlZCB0byBjcmVhdGUgY2hlY2tvdXQgc2Vzc2lvbicpXG4gICAgfVxuXG4gICAgY29uc3QgZGF0YSA9IGF3YWl0IHJlc3BvbnNlLmpzb24oKVxuXG4gICAgaWYgKCFkYXRhLmlkKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LlNFU1NJT05fSU5WQUxJRCB8fCAnSW52YWxpZCBjaGVja291dCBzZXNzaW9uIHJlc3BvbnNlJylcbiAgICB9XG5cbiAgICBjb25zb2xlLmxvZygnQ2hlY2tvdXQgU2Vzc2lvbiBjcmVhdGVkOicsIGRhdGEuaWQsICdVUkw6JywgZGF0YS51cmwpXG4gICAgY29uc29sZS5sb2coJ0RlYnVnIGluZm86JywgZGF0YS5fZGVidWcpXG5cbiAgICAvLyBSZWRpcmVjdCB0byBTdHJpcGUgQ2hlY2tvdXQgdXNpbmcgZGlyZWN0IFVSTCAobW9yZSByZWxpYWJsZSlcbiAgICBpZiAoZGF0YS51cmwpIHtcbiAgICAgIHdpbmRvdy5sb2NhdGlvbi5ocmVmID0gZGF0YS51cmxcbiAgICAgIHJldHVyblxuICAgIH1cblxuICAgIC8vIEZhbGxiYWNrIHRvIHJlZGlyZWN0VG9DaGVja291dCBpZiBVUkwgbm90IGF2YWlsYWJsZVxuICAgIGNvbnN0IHsgZXJyb3IgfSA9IGF3YWl0IHN0cmlwZS5yZWRpcmVjdFRvQ2hlY2tvdXQoe1xuICAgICAgc2Vzc2lvbklkOiBkYXRhLmlkXG4gICAgfSlcblxuICAgIGlmIChlcnJvcikge1xuICAgICAgdGhyb3cgZXJyb3JcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIFBheW1lbnQgSW50ZW50IGZsb3cgKGNhcmQgZWxlbWVudClcbiAgICogVXNlZCBmb3IgY2FyZCBwYXltZW50c1xuICAgKi9cbiAgYXN5bmMgaGFuZGxlUGF5bWVudEludGVudCgpIHtcbiAgICAvLyBHZXQgc3RyaXBlLW9yZGVyIGNvbnRyb2xsZXIgaW5zdGFuY2VcbiAgICBjb25zdCBzdHJpcGVPcmRlckNvbnRyb2xsZXIgPSB0aGlzLmdldFN0cmlwZU9yZGVyQ29udHJvbGxlcigpXG5cbiAgICBpZiAoIXN0cmlwZU9yZGVyQ29udHJvbGxlcikge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5DT05UUk9MTEVSX05PVF9GT1VORCB8fCAnU3RyaXBlIHBheW1lbnQgY29udHJvbGxlciBub3QgZm91bmQuIFBsZWFzZSByZWZyZXNoIHRoZSBwYWdlLicpXG4gICAgfVxuXG4gICAgLy8gVmVyaWZ5IGNhcmQgZWxlbWVudCBhbmQgc3RyaXBlIGFyZSBhdmFpbGFibGVcbiAgICBpZiAoIXN0cmlwZU9yZGVyQ29udHJvbGxlci5jYXJkIHx8ICFzdHJpcGVPcmRlckNvbnRyb2xsZXIuc3RyaXBlKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdQYXltZW50IGZvcm0gbm90IHJlYWR5OicsIHtcbiAgICAgICAgaGFzQ2FyZDogISFzdHJpcGVPcmRlckNvbnRyb2xsZXIuY2FyZCxcbiAgICAgICAgaGFzU3RyaXBlOiAhIXN0cmlwZU9yZGVyQ29udHJvbGxlci5zdHJpcGVcbiAgICAgIH0pXG4gICAgICB0aHJvdyBuZXcgRXJyb3Iod2luZG93Lm9TdHJpcGU/LmkxOG4/LkZPUk1fTk9UX1JFQURZIHx8ICdQYXltZW50IGZvcm0gbm90IGluaXRpYWxpemVkLiBQbGVhc2UgcmVmcmVzaCB0aGUgcGFnZS4nKVxuICAgIH1cblxuICAgIGNvbnNvbGUubG9nKCdTdHJpcGUgY29udHJvbGxlciByZWFkeTonLCB7XG4gICAgICBoYXNDYXJkOiAhIXN0cmlwZU9yZGVyQ29udHJvbGxlci5jYXJkLFxuICAgICAgaGFzU3RyaXBlOiAhIXN0cmlwZU9yZGVyQ29udHJvbGxlci5zdHJpcGVcbiAgICB9KVxuXG4gICAgY29uc3QgcGF5bWVudEludGVudFJlc3BvbnNlID0gYXdhaXQgdGhpcy5oYW5kbGVQYXltZW50KClcbiAgICBjb25zdCBjbGllbnRTZWNyZXQgPSBwYXltZW50SW50ZW50UmVzcG9uc2UuY2xpZW50U2VjcmV0XG5cbiAgICBjb25zdCBjb25maXJtUGF5bWVudFJlc3BvbnNlID0gYXdhaXQgc3RyaXBlT3JkZXJDb250cm9sbGVyLnN0cmlwZS5jb25maXJtQ2FyZFBheW1lbnQoY2xpZW50U2VjcmV0LCB7XG4gICAgICBwYXltZW50X21ldGhvZDoge1xuICAgICAgICBjYXJkOiBzdHJpcGVPcmRlckNvbnRyb2xsZXIuY2FyZFxuICAgICAgfVxuICAgIH0pO1xuXG4gICAgaWYgKGNvbmZpcm1QYXltZW50UmVzcG9uc2UuZXJyb3IpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcihjb25maXJtUGF5bWVudFJlc3BvbnNlLmVycm9yLm1lc3NhZ2UpXG4gICAgfSBlbHNlIGlmIChjb25maXJtUGF5bWVudFJlc3BvbnNlLnBheW1lbnRJbnRlbnQgJiYgY29uZmlybVBheW1lbnRSZXNwb25zZS5wYXltZW50SW50ZW50LnN0YXR1cyA9PT0gJ3N1Y2NlZWRlZCcpIHtcbiAgICAgIGNvbnNvbGUubG9nKCdQYXltZW50IHN1Y2NlZWRlZCcsIGNvbmZpcm1QYXltZW50UmVzcG9uc2UucGF5bWVudEludGVudClcbiAgICAgIC8vIFRPRE86IFN1Ym1pdCBmaW5hbCBvcmRlciB0byBiYWNrZW5kXG4gICAgfSBlbHNlIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcih3aW5kb3cub1N0cmlwZT8uaTE4bj8uUEFZTUVOVF9OT1RfQ09NUExFVEVEIHx8ICdQYXltZW50IG5vdCBjb21wbGV0ZWQnKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBGZXRjaCBwYXltZW50IGludGVudCBjcmVhdGlvbiBVUkwgYW5kIHJldHVybiByZXNwb25zZVxuICAgKiBAcmV0dXJucyB7UHJvbWlzZTxPYmplY3Q+fSBQYXltZW50IGludGVudCByZXNwb25zZSB3aXRoIGNsaWVudFNlY3JldCwgYW1vdW50LCBjdXJyZW5jeVxuICAgKiBAdGhyb3dzIHtFcnJvcn0gSWYgZmV0Y2ggZmFpbHMgb3IgcmVzcG9uc2UgaXMgbm90IG9rXG4gICAqL1xuICBhc3luYyBoYW5kbGVQYXltZW50KCkge1xuICAgIGlmICghdGhpcy5oYXNVcmxWYWx1ZSkge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5VUkxfTk9UX0NPTkZJR1VSRUQgfHwgJ1BheW1lbnQgVVJMIGlzIG5vdCBjb25maWd1cmVkJylcbiAgICB9XG5cbiAgICBjb25zb2xlLmxvZygnQ3JlYXRpbmcgcGF5bWVudCBpbnRlbnQgdmlhIFVSTDonLCB0aGlzLnVybFZhbHVlKVxuXG4gICAgY29uc3QgcmVzcG9uc2UgPSBhd2FpdCBmZXRjaCh0aGlzLmJ1aWxkVXJsV2l0aENzcmZUb2tlbih0aGlzLnVybFZhbHVlKSwge1xuICAgICAgbWV0aG9kOiAnUE9TVCcsXG4gICAgICBoZWFkZXJzOiB7XG4gICAgICAgICdDb250ZW50LVR5cGUnOiAnYXBwbGljYXRpb24vanNvbidcbiAgICAgIH0sXG4gICAgICBjcmVkZW50aWFsczogJ3NhbWUtb3JpZ2luJ1xuICAgIH0pXG5cbiAgICBpZiAoIXJlc3BvbnNlLm9rKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IoYEhUVFAgZXJyb3IhIHN0YXR1czogJHtyZXNwb25zZS5zdGF0dXN9YClcbiAgICB9XG5cbiAgICBjb25zdCByZXNwb25zZURhdGEgPSBhd2FpdCByZXNwb25zZS5qc29uKClcblxuICAgIGlmIChyZXNwb25zZURhdGEuZXJyb3IpIHtcbiAgICAgIHRocm93IG5ldyBFcnJvcihyZXNwb25zZURhdGEuZXJyb3IpXG4gICAgfVxuXG4gICAgaWYgKCFyZXNwb25zZURhdGEuc3VjY2VzcyB8fCAhcmVzcG9uc2VEYXRhLmNsaWVudFNlY3JldCkge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKHdpbmRvdy5vU3RyaXBlPy5pMThuPy5JTlRFTlRfSU5WQUxJRCB8fCAnSW52YWxpZCBwYXltZW50IGludGVudCByZXNwb25zZScpXG4gICAgfVxuXG4gICAgcmV0dXJuIHJlc3BvbnNlRGF0YVxuICB9XG5cbiAgLyoqXG4gICAqIEFwcGVuZCBzdG9rZW4gKENTUkYgdG9rZW4pIHRvIFVSTCBmb3Igc2Vzc2lvbiBjaGFsbGVuZ2UgdmFsaWRhdGlvbi5cbiAgICogT1hJRCBpbmNsdWRlcyBzdG9rZW4gaW4gZm9ybXMgdmlhIG9WaWV3Q29uZi5nZXRTZXNzaW9uQ2hhbGxlbmdlVG9rZW4oKS5cbiAgICogQHBhcmFtIHtzdHJpbmd9IHVybCAtIFRoZSBiYXNlIFVSTFxuICAgKiBAcmV0dXJucyB7c3RyaW5nfSBVUkwgd2l0aCBzdG9rZW4gcGFyYW1ldGVyIGFwcGVuZGVkXG4gICAqL1xuICBidWlsZFVybFdpdGhDc3JmVG9rZW4odXJsKSB7XG4gICAgY29uc3Qgc3Rva2VuID0gZG9jdW1lbnQucXVlcnlTZWxlY3RvcignaW5wdXRbbmFtZT1cInN0b2tlblwiXScpPy52YWx1ZSB8fCAnJ1xuICAgIGlmICghc3Rva2VuKSB7XG4gICAgICBjb25zb2xlLndhcm4oJ0NTUkYgdG9rZW4gKHN0b2tlbikgbm90IGZvdW5kIGluIGZvcm0nKVxuICAgICAgcmV0dXJuIHVybFxuICAgIH1cbiAgICBjb25zdCBzZXBhcmF0b3IgPSB1cmwuaW5jbHVkZXMoJz8nKSA/ICcmJyA6ICc/J1xuICAgIHJldHVybiB1cmwgKyBzZXBhcmF0b3IgKyAnc3Rva2VuPScgKyBlbmNvZGVVUklDb21wb25lbnQoc3Rva2VuKVxuICB9XG5cbiAgLyoqXG4gICAqIFNob3cgbG9hZGluZyBzdGF0ZSBvbiBidXR0b25cbiAgICovXG4gIHNob3dMb2FkaW5nKCkge1xuICAgIHRoaXMuZWxlbWVudC5kaXNhYmxlZCA9IHRydWVcbiAgICB0aGlzLm9yaWdpbmFsVGV4dCA9IHRoaXMuZWxlbWVudC50ZXh0Q29udGVudFxuICAgIHRoaXMuZWxlbWVudC50ZXh0Q29udGVudCA9IHdpbmRvdy5vU3RyaXBlPy5pMThuPy5QUk9DRVNTSU5HIHx8ICdQcm9jZXNzaW5nLi4uJ1xuICB9XG5cbiAgLyoqXG4gICAqIEhpZGUgbG9hZGluZyBzdGF0ZSBvbiBidXR0b25cbiAgICovXG4gIGhpZGVMb2FkaW5nKCkge1xuICAgIHRoaXMuZWxlbWVudC5kaXNhYmxlZCA9IGZhbHNlXG4gICAgaWYgKHRoaXMub3JpZ2luYWxUZXh0KSB7XG4gICAgICB0aGlzLmVsZW1lbnQudGV4dENvbnRlbnQgPSB0aGlzLm9yaWdpbmFsVGV4dFxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBTZXQgc3RhdHVzIG1lc3NhZ2VcbiAgICogQHBhcmFtIHtzdHJpbmd9IG1lc3NhZ2UgLSBTdGF0dXMgbWVzc2FnZSB0byBkaXNwbGF5XG4gICAqL1xuICBzZXRTdGF0dXMobWVzc2FnZSkge1xuICAgIGlmICh0aGlzLmhhc1N0YXR1c1RhcmdldCkge1xuICAgICAgdGhpcy5zdGF0dXNUYXJnZXQudGV4dENvbnRlbnQgPSBtZXNzYWdlXG4gICAgICB0aGlzLnN0YXR1c1RhcmdldC5jbGFzc05hbWUgPSAnbXQtMiB0ZXh0LWNlbnRlciB0ZXh0LW11dGVkJ1xuICAgIH1cbiAgICBjb25zb2xlLmxvZygnU3RhdHVzOicsIG1lc3NhZ2UpXG4gIH1cblxuICAvKipcbiAgICogU2hvdyBlcnJvciBtZXNzYWdlXG4gICAqIEBwYXJhbSB7c3RyaW5nfSBtZXNzYWdlIC0gRXJyb3IgbWVzc2FnZSB0byBkaXNwbGF5XG4gICAqL1xuICBzaG93RXJyb3IobWVzc2FnZSkge1xuICAgIGlmICh0aGlzLmhhc1N0YXR1c1RhcmdldCkge1xuICAgICAgdGhpcy5zdGF0dXNUYXJnZXQudGV4dENvbnRlbnQgPSBtZXNzYWdlXG4gICAgICB0aGlzLnN0YXR1c1RhcmdldC5jbGFzc05hbWUgPSAnbXQtMiB0ZXh0LWNlbnRlciB0ZXh0LWRhbmdlcidcbiAgICB9IGVsc2Uge1xuICAgICAgYWxlcnQoJ0Vycm9yOiAnICsgbWVzc2FnZSlcbiAgICB9XG4gIH1cbn1cbiIsICJpbXBvcnQgeyBDb250cm9sbGVyIH0gZnJvbSAnQGhvdHdpcmVkL3N0aW11bHVzJ1xuXG4vKipcbiAqIFN0aW11bHVzIGNvbnRyb2xsZXIgZm9yIEFHQiAoVGVybXMgYW5kIENvbmRpdGlvbnMpIGNoZWNrYm94IHZhbGlkYXRpb25cbiAqXG4gKiBUaGlzIGNvbnRyb2xsZXIgaGFuZGxlcyB0aGUgdmFsaWRhdGlvbiBvZiB0aGUgQUdCIGNoZWNrYm94IG9uIHRoZSBvcmRlciBwYWdlLlxuICogV2hlbiBibENvbmZpcm1BR0IgaXMgZW5hYmxlZCwgaXQgcHJldmVudHMgb3JkZXIgc3VibWlzc2lvbiB1bnRpbCB0aGUgY2hlY2tib3ggaXMgY2hlY2tlZC5cbiAqXG4gKiBVc2FnZSBpbiB0ZW1wbGF0ZTpcbiAqIDxkaXYgZGF0YS1jb250cm9sbGVyPVwiYWdiLXZhbGlkYXRpb25cIiBkYXRhLWFnYi12YWxpZGF0aW9uLWVuYWJsZWQtdmFsdWU9XCJ0cnVlXCI+XG4gKiAgIDxpbnB1dCB0eXBlPVwiY2hlY2tib3hcIiBkYXRhLWFnYi12YWxpZGF0aW9uLXRhcmdldD1cImNoZWNrYm94XCIgLz5cbiAqICAgPGJ1dHRvbiBkYXRhLWFnYi12YWxpZGF0aW9uLXRhcmdldD1cInN1Ym1pdEJ1dHRvblwiPk9yZGVyPC9idXR0b24+XG4gKiA8L2Rpdj5cbiAqL1xuZXhwb3J0IGRlZmF1bHQgY2xhc3MgZXh0ZW5kcyBDb250cm9sbGVyIHtcbiAgc3RhdGljIHRhcmdldHMgPSBbJ2NoZWNrYm94JywgJ3N1Ym1pdEJ1dHRvbiddXG4gIHN0YXRpYyB2YWx1ZXMgPSB7XG4gICAgZW5hYmxlZDogQm9vbGVhblxuICB9XG5cbiAgLyoqXG4gICAqIEluaXRpYWxpemUgdGhlIGNvbnRyb2xsZXIgd2hlbiBjb25uZWN0ZWQgdG8gdGhlIERPTVxuICAgKi9cbiAgY29ubmVjdCgpIHtcbiAgICBjb25zb2xlLmxvZygnQUdCIFZhbGlkYXRpb24gY29udHJvbGxlciBjb25uZWN0ZWQnLCB7XG4gICAgICBlbmFibGVkOiB0aGlzLmVuYWJsZWRWYWx1ZSxcbiAgICAgIGhhc0NoZWNrYm94OiB0aGlzLmhhc0NoZWNrYm94VGFyZ2V0LFxuICAgICAgaGFzU3VibWl0QnV0dG9uczogdGhpcy5oYXNTdWJtaXRCdXR0b25UYXJnZXRcbiAgICB9KVxuXG4gICAgLy8gT25seSBhcHBseSB2YWxpZGF0aW9uIGlmIGJsQ29uZmlybUFHQiBpcyBlbmFibGVkXG4gICAgaWYgKHRoaXMuZW5hYmxlZFZhbHVlKSB7XG4gICAgICB0aGlzLnVwZGF0ZUJ1dHRvblN0YXRlcygpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBjaGVja2JveCBzdGF0ZSBjaGFuZ2VzXG4gICAqL1xuICBjaGVja2JveENoYW5nZWQoKSB7XG4gICAgaWYgKHRoaXMuZW5hYmxlZFZhbHVlKSB7XG4gICAgICB0aGlzLnVwZGF0ZUJ1dHRvblN0YXRlcygpXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFVwZGF0ZSB0aGUgZGlzYWJsZWQgc3RhdGUgb2YgYWxsIHN1Ym1pdCBidXR0b25zIGJhc2VkIG9uIGNoZWNrYm94IHN0YXRlXG4gICAqL1xuICB1cGRhdGVCdXR0b25TdGF0ZXMoKSB7XG4gICAgaWYgKCF0aGlzLmhhc0NoZWNrYm94VGFyZ2V0IHx8ICF0aGlzLmhhc1N1Ym1pdEJ1dHRvblRhcmdldCkge1xuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgY29uc3QgaXNDaGVja2VkID0gdGhpcy5jaGVja2JveFRhcmdldC5jaGVja2VkXG5cbiAgICAvLyBVcGRhdGUgYWxsIHN1Ym1pdCBidXR0b25zXG4gICAgdGhpcy5zdWJtaXRCdXR0b25UYXJnZXRzLmZvckVhY2goYnV0dG9uID0+IHtcbiAgICAgIGJ1dHRvbi5kaXNhYmxlZCA9ICFpc0NoZWNrZWRcblxuICAgICAgLy8gQWRkIHZpc3VhbCBmZWVkYmFja1xuICAgICAgaWYgKGlzQ2hlY2tlZCkge1xuICAgICAgICBidXR0b24uY2xhc3NMaXN0LnJlbW92ZSgnZGlzYWJsZWQnKVxuICAgICAgICBidXR0b24ucmVtb3ZlQXR0cmlidXRlKCd0aXRsZScpXG4gICAgICB9IGVsc2Uge1xuICAgICAgICBidXR0b24uY2xhc3NMaXN0LmFkZCgnZGlzYWJsZWQnKVxuICAgICAgICBidXR0b24uc2V0QXR0cmlidXRlKCd0aXRsZScsIHdpbmRvdy5vU3RyaXBlPy5pMThuPy5BR0JfUkVRVUlSRUQgfHwgJ1BsZWFzZSBhY2NlcHQgdGhlIHRlcm1zIGFuZCBjb25kaXRpb25zJylcbiAgICAgIH1cbiAgICB9KVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBmb3JtIHN1Ym1pc3Npb24gYXR0ZW1wdHNcbiAgICogQHBhcmFtIHtFdmVudH0gZXZlbnQgLSBUaGUgc3VibWl0IGV2ZW50XG4gICAqL1xuICBoYW5kbGVTdWJtaXQoZXZlbnQpIHtcbiAgICBpZiAoIXRoaXMuZW5hYmxlZFZhbHVlKSB7XG4gICAgICByZXR1cm4gdHJ1ZVxuICAgIH1cblxuICAgIGlmICghdGhpcy5oYXNDaGVja2JveFRhcmdldCB8fCAhdGhpcy5jaGVja2JveFRhcmdldC5jaGVja2VkKSB7XG4gICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpXG4gICAgICBldmVudC5zdG9wUHJvcGFnYXRpb24oKVxuXG4gICAgICAvLyBTaG93IHZpc3VhbCBmZWVkYmFja1xuICAgICAgaWYgKHRoaXMuaGFzQ2hlY2tib3hUYXJnZXQpIHtcbiAgICAgICAgY29uc3QgY2hlY2tib3hXcmFwcGVyID0gdGhpcy5jaGVja2JveFRhcmdldC5jbG9zZXN0KCcuZm9ybS1jaGVjaycpXG4gICAgICAgIGlmIChjaGVja2JveFdyYXBwZXIpIHtcbiAgICAgICAgICBjaGVja2JveFdyYXBwZXIuY2xhc3NMaXN0LmFkZCgnYm9yZGVyJywgJ2JvcmRlci1kYW5nZXInLCAncC0yJywgJ3JvdW5kZWQnKVxuXG4gICAgICAgICAgLy8gUmVtb3ZlIHRoZSBoaWdobGlnaHQgYWZ0ZXIgMyBzZWNvbmRzXG4gICAgICAgICAgc2V0VGltZW91dCgoKSA9PiB7XG4gICAgICAgICAgICBjaGVja2JveFdyYXBwZXIuY2xhc3NMaXN0LnJlbW92ZSgnYm9yZGVyJywgJ2JvcmRlci1kYW5nZXInLCAncC0yJywgJ3JvdW5kZWQnKVxuICAgICAgICAgIH0sIDMwMDApXG4gICAgICAgIH1cbiAgICAgIH1cblxuICAgICAgcmV0dXJuIGZhbHNlXG4gICAgfVxuXG4gICAgcmV0dXJuIHRydWVcbiAgfVxufVxuIiwgIi8qKlxuICogRXZlbnRCdXMgLSBDZW50cmFsbmEgc3p5bmEgZXZlbnRvd2EgZGxhIGFwbGlrYWNqaSBPbmUtUGFnZSBDaGVja291dFxuICpcbiAqIFByb2JsZW06XG4gKiBLb250cm9sZXJ5IGRpc3BhdGNodWpcdTAxMDUgZXZlbnR5IG5hIHJcdTAwRjNcdTAxN0NueWNoIHRhcmdldGFjaCAoZG9jdW1lbnQsIHRoaXMuZWxlbWVudCwgd2luZG93KSxcbiAqIGNvIHBvd29kdWplIHByb2JsZW15IHogdGltaW5nJ2llbSBpIHRydWRub1x1MDE1QmNpIHcgdGVzdG93YW5pdS5cbiAqXG4gKiBSb3p3aVx1MDEwNXphbmllOlxuICogU2luZ2xldG9uIEV2ZW50QnVzIHphcGV3bmlhIGplZGVuIGNlbnRyYWxueSBwdW5rdCBrb211bmlrYWNqaS5cbiAqIFdzenlzdGtpZSBldmVudHkgcHJ6ZWNob2R6XHUwMTA1IHByemV6IHRcdTAxMTkgc3p5blx1MDExOSwgY28gdVx1MDE0MmF0d2lhOlxuICogLSBEZWJ1Z293YW5pZSAod3N6eXN0a2llIGV2ZW50eSB3IGplZG55bSBtaWVqc2N1KVxuICogLSBUZXN0b3dhbmllIChtb1x1MDE3Q25hIG1vY2tvd2FcdTAxMDcgRXZlbnRCdXMpXG4gKiAtIEtvbnRyb2xcdTAxMTkgKG1vXHUwMTdDbmEgbG9nb3dhXHUwMTA3LCBmaWx0cm93YVx1MDEwNywgdHJhbnNmb3Jtb3dhXHUwMTA3IGV2ZW50eSlcbiAqXG4gKiBVXHUwMTdDeWNpZSB3IGtvbnRyb2xlcmFjaDpcbiAqXG4gKiBpbXBvcnQgeyBldmVudEJ1cyB9IGZyb20gJy4uL3V0aWxzL2V2ZW50X2J1cy5qcydcbiAqXG4gKiAvLyBOYXNcdTAxNDJ1Y2hpd2FuaWVcbiAqIGV2ZW50QnVzLm9uKCdvZTpiYXNrZXQ6dXBkYXRlZCcsIChkYXRhKSA9PiB7XG4gKiAgIGNvbnNvbGUubG9nKCdCYXNrZXQgdXBkYXRlZDonLCBkYXRhKVxuICogfSlcbiAqXG4gKiAvLyBFbWlzamFcbiAqIGV2ZW50QnVzLmVtaXQoJ29lOmJhc2tldDp1cGRhdGVkJywgeyBpdGVtczogWy4uLl0sIHRvdGFsOiAxMDAgfSlcbiAqXG4gKiAvLyBKZWRub3Jhem93ZSBuYXNcdTAxNDJ1Y2hpd2FuaWVcbiAqIGV2ZW50QnVzLm9uY2UoJ29lOmNoZWNrb3V0OmNvbXBsZXRlJywgKGRhdGEpID0+IHtcbiAqICAgY29uc29sZS5sb2coJ0NoZWNrb3V0IGNvbXBsZXRlOicsIGRhdGEpXG4gKiB9KVxuICpcbiAqIC8vIFVzdW5pXHUwMTE5Y2llIGxpc3RlbmVyYSAod2FcdTAxN0NuZSBkbGEgY2xlYW51cCEpXG4gKiBjb25zdCBoYW5kbGVyID0gKGRhdGEpID0+IGNvbnNvbGUubG9nKGRhdGEpXG4gKiBldmVudEJ1cy5vbignZXZlbnQnLCBoYW5kbGVyKVxuICogZXZlbnRCdXMub2ZmKCdldmVudCcsIGhhbmRsZXIpXG4gKi9cblxuY2xhc3MgRXZlbnRCdXMge1xuICBjb25zdHJ1Y3RvcigpIHtcbiAgICAvLyBTaW5nbGV0b24gcGF0dGVyblxuICAgIGlmIChFdmVudEJ1cy5pbnN0YW5jZSkge1xuICAgICAgcmV0dXJuIEV2ZW50QnVzLmluc3RhbmNlXG4gICAgfVxuXG4gICAgdGhpcy5saXN0ZW5lcnMgPSBuZXcgTWFwKCkgLy8gZXZlbnROYW1lIC0+IFNldCBvZiBoYW5kbGVyc1xuICAgIHRoaXMuZGVidWcgPSBmYWxzZVxuICAgIHRoaXMuZXZlbnRIaXN0b3J5ID0gW10gLy8gRm9yIGRlYnVnZ2luZ1xuICAgIHRoaXMubWF4SGlzdG9yeVNpemUgPSAxMDBcblxuICAgIEV2ZW50QnVzLmluc3RhbmNlID0gdGhpc1xuICB9XG5cbiAgLyoqXG4gICAqIFdcdTAxNDJcdTAxMDVjei93eVx1MDE0Mlx1MDEwNWN6IHRyeWIgZGVidWdcbiAgICovXG4gIHNldERlYnVnKGVuYWJsZWQpIHtcbiAgICB0aGlzLmRlYnVnID0gZW5hYmxlZFxuICB9XG5cbiAgLyoqXG4gICAqIFphcmVqZXN0cnVqIGxpc3RlbmVyIGRsYSBldmVudHVcbiAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dSAobnAuICdvZTpiYXNrZXQ6dXBkYXRlZCcpXG4gICAqIEBwYXJhbSB7ZnVuY3Rpb259IGhhbmRsZXIgLSBGdW5rY2phIGhhbmRsZXJhIChkYXRhKSA9PiB2b2lkXG4gICAqIEByZXR1cm5zIHtmdW5jdGlvbn0gRnVua2NqYSBkbyB1c3VuaVx1MDExOWNpYSBsaXN0ZW5lcmFcbiAgICovXG4gIG9uKGV2ZW50TmFtZSwgaGFuZGxlcikge1xuICAgIGlmICghdGhpcy5saXN0ZW5lcnMuaGFzKGV2ZW50TmFtZSkpIHtcbiAgICAgIHRoaXMubGlzdGVuZXJzLnNldChldmVudE5hbWUsIG5ldyBTZXQoKSlcbiAgICB9XG5cbiAgICB0aGlzLmxpc3RlbmVycy5nZXQoZXZlbnROYW1lKS5hZGQoaGFuZGxlcilcblxuICAgIGlmICh0aGlzLmRlYnVnKSB7XG4gICAgICBjb25zb2xlLmxvZyhgW0V2ZW50QnVzXSBSZWdpc3RlcmVkIGxpc3RlbmVyIGZvciBcIiR7ZXZlbnROYW1lfVwiYCwge1xuICAgICAgICBsaXN0ZW5lcnNDb3VudDogdGhpcy5saXN0ZW5lcnMuZ2V0KGV2ZW50TmFtZSkuc2l6ZVxuICAgICAgfSlcbiAgICB9XG5cbiAgICAvLyBad3JcdTAwRjNcdTAxMDcgZnVua2NqXHUwMTE5IGRvIHVzdW5pXHUwMTE5Y2lhIGxpc3RlbmVyYVxuICAgIHJldHVybiAoKSA9PiB0aGlzLm9mZihldmVudE5hbWUsIGhhbmRsZXIpXG4gIH1cblxuICAvKipcbiAgICogWmFyZWplc3RydWogbGlzdGVuZXIsIGt0XHUwMEYzcnkgd3lrb25hIHNpXHUwMTE5IHR5bGtvIHJhelxuICAgKiBAcGFyYW0ge3N0cmluZ30gZXZlbnROYW1lIC0gTmF6d2EgZXZlbnR1XG4gICAqIEBwYXJhbSB7ZnVuY3Rpb259IGhhbmRsZXIgLSBGdW5rY2phIGhhbmRsZXJhXG4gICAqIEByZXR1cm5zIHtmdW5jdGlvbn0gRnVua2NqYSBkbyB1c3VuaVx1MDExOWNpYSBsaXN0ZW5lcmFcbiAgICovXG4gIG9uY2UoZXZlbnROYW1lLCBoYW5kbGVyKSB7XG4gICAgY29uc3Qgb25jZUhhbmRsZXIgPSAoZGF0YSkgPT4ge1xuICAgICAgaGFuZGxlcihkYXRhKVxuICAgICAgdGhpcy5vZmYoZXZlbnROYW1lLCBvbmNlSGFuZGxlcilcbiAgICB9XG5cbiAgICByZXR1cm4gdGhpcy5vbihldmVudE5hbWUsIG9uY2VIYW5kbGVyKVxuICB9XG5cbiAgLyoqXG4gICAqIFVzdVx1MDE0NCBsaXN0ZW5lclxuICAgKiBAcGFyYW0ge3N0cmluZ30gZXZlbnROYW1lIC0gTmF6d2EgZXZlbnR1XG4gICAqIEBwYXJhbSB7ZnVuY3Rpb259IGhhbmRsZXIgLSBGdW5rY2phIGhhbmRsZXJhIGRvIHVzdW5pXHUwMTE5Y2lhXG4gICAqL1xuICBvZmYoZXZlbnROYW1lLCBoYW5kbGVyKSB7XG4gICAgaWYgKCF0aGlzLmxpc3RlbmVycy5oYXMoZXZlbnROYW1lKSkge1xuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgY29uc3QgaGFuZGxlcnMgPSB0aGlzLmxpc3RlbmVycy5nZXQoZXZlbnROYW1lKVxuICAgIGhhbmRsZXJzLmRlbGV0ZShoYW5kbGVyKVxuXG4gICAgLy8gVXN1XHUwMTQ0IGV2ZW50IHogbWFweSBqZVx1MDE1QmxpIG5pZSBtYSBqdVx1MDE3QyBsaXN0ZW5lclx1MDBGM3dcbiAgICBpZiAoaGFuZGxlcnMuc2l6ZSA9PT0gMCkge1xuICAgICAgdGhpcy5saXN0ZW5lcnMuZGVsZXRlKGV2ZW50TmFtZSlcbiAgICB9XG5cbiAgICBpZiAodGhpcy5kZWJ1Zykge1xuICAgICAgY29uc29sZS5sb2coYFtFdmVudEJ1c10gUmVtb3ZlZCBsaXN0ZW5lciBmb3IgXCIke2V2ZW50TmFtZX1cImAsIHtcbiAgICAgICAgbGlzdGVuZXJzQ291bnQ6IGhhbmRsZXJzLnNpemVcbiAgICAgIH0pXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFVzdVx1MDE0NCB3c3p5c3RraWUgbGlzdGVuZXJ5IGRsYSBkYW5lZ28gZXZlbnR1XG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICovXG4gIG9mZkFsbChldmVudE5hbWUpIHtcbiAgICBpZiAodGhpcy5saXN0ZW5lcnMuaGFzKGV2ZW50TmFtZSkpIHtcbiAgICAgIHRoaXMubGlzdGVuZXJzLmRlbGV0ZShldmVudE5hbWUpXG5cbiAgICAgIGlmICh0aGlzLmRlYnVnKSB7XG4gICAgICAgIGNvbnNvbGUubG9nKGBbRXZlbnRCdXNdIFJlbW92ZWQgYWxsIGxpc3RlbmVycyBmb3IgXCIke2V2ZW50TmFtZX1cImApXG4gICAgICB9XG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFd5ZW1pdHVqIGV2ZW50XG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICogQHBhcmFtIHsqfSBkYXRhIC0gRGFuZSBkbyBwcnpla2F6YW5pYVxuICAgKi9cbiAgZW1pdChldmVudE5hbWUsIGRhdGEgPSB7fSkge1xuICAgIGNvbnN0IHRpbWVzdGFtcCA9IERhdGUubm93KClcblxuICAgIC8vIFphcGlzeiBkbyBoaXN0b3JpaVxuICAgIHRoaXMuZXZlbnRIaXN0b3J5LnB1c2goe1xuICAgICAgZXZlbnROYW1lLFxuICAgICAgZGF0YSxcbiAgICAgIHRpbWVzdGFtcCxcbiAgICAgIGxpc3RlbmVyc0NvdW50OiB0aGlzLmxpc3RlbmVycy5oYXMoZXZlbnROYW1lKSA/IHRoaXMubGlzdGVuZXJzLmdldChldmVudE5hbWUpLnNpemUgOiAwXG4gICAgfSlcblxuICAgIC8vIE9ncmFuaWN6IHJvem1pYXIgaGlzdG9yaWlcbiAgICBpZiAodGhpcy5ldmVudEhpc3RvcnkubGVuZ3RoID4gdGhpcy5tYXhIaXN0b3J5U2l6ZSkge1xuICAgICAgdGhpcy5ldmVudEhpc3Rvcnkuc2hpZnQoKVxuICAgIH1cblxuICAgIGlmICh0aGlzLmRlYnVnKSB7XG4gICAgICBjb25zb2xlLmxvZyhgW0V2ZW50QnVzXSBFdmVudCBlbWl0dGVkOiBcIiR7ZXZlbnROYW1lfVwiYCwge1xuICAgICAgICBkYXRhLFxuICAgICAgICBsaXN0ZW5lcnNDb3VudDogdGhpcy5saXN0ZW5lcnMuaGFzKGV2ZW50TmFtZSkgPyB0aGlzLmxpc3RlbmVycy5nZXQoZXZlbnROYW1lKS5zaXplIDogMCxcbiAgICAgICAgdGltZXN0YW1wOiBuZXcgRGF0ZSh0aW1lc3RhbXApLnRvSVNPU3RyaW5nKClcbiAgICAgIH0pXG4gICAgfVxuXG4gICAgLy8gV3l3b1x1MDE0MmFqIHdzenlzdGtpZSBsaXN0ZW5lcnlcbiAgICBpZiAodGhpcy5saXN0ZW5lcnMuaGFzKGV2ZW50TmFtZSkpIHtcbiAgICAgIGNvbnN0IGhhbmRsZXJzID0gQXJyYXkuZnJvbSh0aGlzLmxpc3RlbmVycy5nZXQoZXZlbnROYW1lKSlcblxuICAgICAgaGFuZGxlcnMuZm9yRWFjaChoYW5kbGVyID0+IHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICBoYW5kbGVyKGRhdGEpXG4gICAgICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgY29uc29sZS5lcnJvcihgW0V2ZW50QnVzXSBFcnJvciBpbiBoYW5kbGVyIGZvciBcIiR7ZXZlbnROYW1lfVwiOmAsIGVycm9yKVxuICAgICAgICAgIC8vIE5pZSBwcnplcnl3YWogd3lrb255d2FuaWEgaW5ueWNoIGhhbmRsZXJcdTAwRjN3XG4gICAgICAgIH1cbiAgICAgIH0pXG4gICAgfSBlbHNlIGlmICh0aGlzLmRlYnVnKSB7XG4gICAgICBjb25zb2xlLndhcm4oYFtFdmVudEJ1c10gTm8gbGlzdGVuZXJzIGZvciBcIiR7ZXZlbnROYW1lfVwiYClcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogV3llbWl0dWogZXZlbnQgYXN5bmNocm9uaWN6bmllIChuYXN0XHUwMTE5cG55IHRpY2spXG4gICAqIFByenlkYXRuZSBnZHkgY2hjZW15IHBvendvbGlcdTAxMDcgVUkgc2lcdTAxMTkgd3lyZW5kZXJvd2FcdTAxMDcgcHJ6ZWQgaGFuZGxlcmVtXG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICogQHBhcmFtIHsqfSBkYXRhIC0gRGFuZSBkbyBwcnpla2F6YW5pYVxuICAgKiBAcmV0dXJucyB7UHJvbWlzZX0gUHJvbWlzZSBrdFx1MDBGM3J5IHJlc29sdmUndWplIHNpXHUwMTE5IHBvIGVtaXNqaVxuICAgKi9cbiAgYXN5bmMgZW1pdEFzeW5jKGV2ZW50TmFtZSwgZGF0YSA9IHt9KSB7XG4gICAgcmV0dXJuIG5ldyBQcm9taXNlKChyZXNvbHZlKSA9PiB7XG4gICAgICBzZXRUaW1lb3V0KCgpID0+IHtcbiAgICAgICAgdGhpcy5lbWl0KGV2ZW50TmFtZSwgZGF0YSlcbiAgICAgICAgcmVzb2x2ZSgpXG4gICAgICB9LCAwKVxuICAgIH0pXG4gIH1cblxuICAvKipcbiAgICogV3llbWl0dWogZXZlbnQgeiBvcFx1MDBGM1x1MDE3QW5pZW5pZW1cbiAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgKiBAcGFyYW0geyp9IGRhdGEgLSBEYW5lIGRvIHByemVrYXphbmlhXG4gICAqIEBwYXJhbSB7bnVtYmVyfSBkZWxheSAtIE9wXHUwMEYzXHUwMTdBbmllbmllIHcgbXNcbiAgICogQHJldHVybnMge251bWJlcn0gVGltZXIgSUQgKGRvIGNsZWFyVGltZW91dClcbiAgICovXG4gIGVtaXREZWxheWVkKGV2ZW50TmFtZSwgZGF0YSA9IHt9LCBkZWxheSA9IDApIHtcbiAgICByZXR1cm4gc2V0VGltZW91dCgoKSA9PiB7XG4gICAgICB0aGlzLmVtaXQoZXZlbnROYW1lLCBkYXRhKVxuICAgIH0sIGRlbGF5KVxuICB9XG5cbiAgLyoqXG4gICAqIFBvY3pla2FqIG5hIGV2ZW50ICh6d3JhY2EgUHJvbWlzZSlcbiAgICogUHJ6eWRhdG5lIHcgdGVzdGFjaCBpIGFzeW5jIGZsb3dzXG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICogQHBhcmFtIHtudW1iZXJ9IHRpbWVvdXQgLSBUaW1lb3V0IHcgbXMgKG9wY2pvbmFsbnkpXG4gICAqIEByZXR1cm5zIHtQcm9taXNlfSBQcm9taXNlIGt0XHUwMEYzcnkgcmVzb2x2ZSd1amUgc2lcdTAxMTkgeiBkYW55bWkgZXZlbnR1XG4gICAqL1xuICB3YWl0Rm9yKGV2ZW50TmFtZSwgdGltZW91dCA9IDUwMDApIHtcbiAgICByZXR1cm4gbmV3IFByb21pc2UoKHJlc29sdmUsIHJlamVjdCkgPT4ge1xuICAgICAgY29uc3QgdGltZXIgPSB0aW1lb3V0ID4gMCA/IHNldFRpbWVvdXQoKCkgPT4ge1xuICAgICAgICB0aGlzLm9mZihldmVudE5hbWUsIGhhbmRsZXIpXG4gICAgICAgIHJlamVjdChuZXcgRXJyb3IoYFtFdmVudEJ1c10gVGltZW91dCB3YWl0aW5nIGZvciBldmVudCBcIiR7ZXZlbnROYW1lfVwiYCkpXG4gICAgICB9LCB0aW1lb3V0KSA6IG51bGxcblxuICAgICAgY29uc3QgaGFuZGxlciA9IChkYXRhKSA9PiB7XG4gICAgICAgIGlmICh0aW1lcikgY2xlYXJUaW1lb3V0KHRpbWVyKVxuICAgICAgICByZXNvbHZlKGRhdGEpXG4gICAgICB9XG5cbiAgICAgIHRoaXMub25jZShldmVudE5hbWUsIGhhbmRsZXIpXG4gICAgfSlcbiAgfVxuXG4gIC8qKlxuICAgKiBTcHJhd2RcdTAxN0EgY3p5IHNcdTAxMDUgbGlzdGVuZXJ5IGRsYSBkYW5lZ28gZXZlbnR1XG4gICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICogQHJldHVybnMge2Jvb2xlYW59XG4gICAqL1xuICBoYXNMaXN0ZW5lcnMoZXZlbnROYW1lKSB7XG4gICAgcmV0dXJuIHRoaXMubGlzdGVuZXJzLmhhcyhldmVudE5hbWUpICYmIHRoaXMubGlzdGVuZXJzLmdldChldmVudE5hbWUpLnNpemUgPiAwXG4gIH1cblxuICAvKipcbiAgICogUG9iaWVyeiBsaWN6Ylx1MDExOSBsaXN0ZW5lclx1MDBGM3cgZGxhIGV2ZW50dVxuICAgKiBAcGFyYW0ge3N0cmluZ30gZXZlbnROYW1lIC0gTmF6d2EgZXZlbnR1XG4gICAqIEByZXR1cm5zIHtudW1iZXJ9XG4gICAqL1xuICBnZXRMaXN0ZW5lcnNDb3VudChldmVudE5hbWUpIHtcbiAgICByZXR1cm4gdGhpcy5saXN0ZW5lcnMuaGFzKGV2ZW50TmFtZSkgPyB0aGlzLmxpc3RlbmVycy5nZXQoZXZlbnROYW1lKS5zaXplIDogMFxuICB9XG5cbiAgLyoqXG4gICAqIFBvYmllcnogd3N6eXN0a2llIHphcmVqZXN0cm93YW5lIGV2ZW50eVxuICAgKiBAcmV0dXJucyB7c3RyaW5nW119XG4gICAqL1xuICBnZXRSZWdpc3RlcmVkRXZlbnRzKCkge1xuICAgIHJldHVybiBBcnJheS5mcm9tKHRoaXMubGlzdGVuZXJzLmtleXMoKSlcbiAgfVxuXG4gIC8qKlxuICAgKiBQb2JpZXJ6IGhpc3RvcmlcdTAxMTkgZXZlbnRcdTAwRjN3XG4gICAqIEBwYXJhbSB7bnVtYmVyfSBsaW1pdCAtIExpbWl0IGV2ZW50XHUwMEYzdyBkbyB6d3JcdTAwRjNjZW5pYSAob3Bjam9uYWxueSlcbiAgICogQHJldHVybnMge0FycmF5fVxuICAgKi9cbiAgZ2V0RXZlbnRIaXN0b3J5KGxpbWl0ID0gNTApIHtcbiAgICByZXR1cm4gdGhpcy5ldmVudEhpc3Rvcnkuc2xpY2UoLWxpbWl0KVxuICB9XG5cbiAgLyoqXG4gICAqIFd5Y3p5XHUwMTVCXHUwMTA3IGhpc3RvcmlcdTAxMTkgZXZlbnRcdTAwRjN3XG4gICAqL1xuICBjbGVhckhpc3RvcnkoKSB7XG4gICAgdGhpcy5ldmVudEhpc3RvcnkgPSBbXVxuICB9XG5cbiAgLyoqXG4gICAqIFd5Y3p5XHUwMTVCXHUwMTA3IHdzenlzdGtpZSBsaXN0ZW5lcnkgKHVcdTAxN0N5aiBvc3Ryb1x1MDE3Q25pZSEpXG4gICAqL1xuICBjbGVhckFsbCgpIHtcbiAgICB0aGlzLmxpc3RlbmVycy5jbGVhcigpXG5cbiAgICBpZiAodGhpcy5kZWJ1Zykge1xuICAgICAgY29uc29sZS5sb2coJ1tFdmVudEJ1c10gQWxsIGxpc3RlbmVycyBjbGVhcmVkJylcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogV3lwaXN6IHN0YXR5c3R5a2kgRXZlbnRCdXNcbiAgICovXG4gIHByaW50U3RhdHMoKSB7XG4gICAgY29uc29sZS5ncm91cCgnW0V2ZW50QnVzXSBTdGF0aXN0aWNzJylcbiAgICBjb25zb2xlLmxvZygnUmVnaXN0ZXJlZCBldmVudHM6JywgdGhpcy5nZXRSZWdpc3RlcmVkRXZlbnRzKCkpXG4gICAgY29uc29sZS5sb2coJ1RvdGFsIGxpc3RlbmVyczonLCBBcnJheS5mcm9tKHRoaXMubGlzdGVuZXJzLnZhbHVlcygpKS5yZWR1Y2UoKHN1bSwgc2V0KSA9PiBzdW0gKyBzZXQuc2l6ZSwgMCkpXG4gICAgY29uc29sZS5sb2coJ0V2ZW50IGhpc3Rvcnkgc2l6ZTonLCB0aGlzLmV2ZW50SGlzdG9yeS5sZW5ndGgpXG5cbiAgICBjb25zb2xlLmdyb3VwKCdMaXN0ZW5lcnMgcGVyIGV2ZW50OicpXG4gICAgdGhpcy5saXN0ZW5lcnMuZm9yRWFjaCgoaGFuZGxlcnMsIGV2ZW50TmFtZSkgPT4ge1xuICAgICAgY29uc29sZS5sb2coYCAgJHtldmVudE5hbWV9OiAke2hhbmRsZXJzLnNpemV9YClcbiAgICB9KVxuICAgIGNvbnNvbGUuZ3JvdXBFbmQoKVxuXG4gICAgY29uc29sZS5ncm91cCgnUmVjZW50IGV2ZW50czonKVxuICAgIHRoaXMuZ2V0RXZlbnRIaXN0b3J5KDEwKS5mb3JFYWNoKGV2ZW50ID0+IHtcbiAgICAgIGNvbnNvbGUubG9nKGAgICR7ZXZlbnQuZXZlbnROYW1lfSAoJHtldmVudC5saXN0ZW5lcnNDb3VudH0gbGlzdGVuZXJzKSAtICR7bmV3IERhdGUoZXZlbnQudGltZXN0YW1wKS50b0xvY2FsZVRpbWVTdHJpbmcoKX1gKVxuICAgIH0pXG4gICAgY29uc29sZS5ncm91cEVuZCgpXG5cbiAgICBjb25zb2xlLmdyb3VwRW5kKClcbiAgfVxufVxuXG4vLyBFa3Nwb3J0dWogc2luZ2xldG9uIGluc3RhbmNlIC0gdVx1MDE3Q3l3YWogZ2xvYmFsbmVnbyBqZVx1MDE1QmxpIGlzdG5pZWplIVxuLy8gV0FcdTAxN0JORTogTW9kdVx1MDE0MiBTdHJpcGUgXHUwMTQyYWR1amUgc2lcdTAxMTkgcG8gb25lcGFnZS1jaGVja291dCwgd2lcdTAxMTljIG11c2lteSB1XHUwMTdDeVx1MDEwN1xuLy8gaXN0bmllalx1MDEwNWNlaiBpbnN0YW5jamkgRXZlbnRCdXMgeiB3aW5kb3cuZXZlbnRCdXMgemFtaWFzdCB0d29yenlcdTAxMDcgbm93XHUwMTA1LlxubGV0IGV2ZW50QnVzXG5cbmlmICh0eXBlb2Ygd2luZG93ICE9PSAndW5kZWZpbmVkJyAmJiB3aW5kb3cuZXZlbnRCdXMpIHtcbiAgLy8gVVx1MDE3Q3lqIGlzdG5pZWpcdTAxMDVjZWogZ2xvYmFsbmVqIGluc3RhbmNqaVxuICBjb25zb2xlLmxvZygnW1N0cmlwZSBFdmVudEJ1c10gVXNpbmcgZXhpc3RpbmcgZ2xvYmFsIEV2ZW50QnVzIGZyb20gd2luZG93LmV2ZW50QnVzJylcbiAgZXZlbnRCdXMgPSB3aW5kb3cuZXZlbnRCdXNcbn0gZWxzZSB7XG4gIC8vIFV0d1x1MDBGM3J6IG5vd1x1MDEwNSBpbnN0YW5jalx1MDExOSAoZmFsbGJhY2spXG4gIGNvbnNvbGUubG9nKCdbU3RyaXBlIEV2ZW50QnVzXSBDcmVhdGluZyBuZXcgRXZlbnRCdXMgaW5zdGFuY2UnKVxuICBldmVudEJ1cyA9IG5ldyBFdmVudEJ1cygpXG5cbiAgLy8gT3Bjam9uYWxuaWU6IHdcdTAxNDJcdTAxMDVjeiBkZWJ1ZyB3IGRldiBtb2RlXG4gIGlmICh0eXBlb2Ygd2luZG93ICE9PSAndW5kZWZpbmVkJyAmJiB3aW5kb3cubG9jYXRpb24/Lmhvc3RuYW1lID09PSAnbG9jYWxob3N0Jykge1xuICAgIGV2ZW50QnVzLnNldERlYnVnKHRydWUpXG4gIH1cblxuICAvLyBVZG9zdFx1MDExOXBuaWogZ2xvYmFsbmllIGRsYSBcdTAxNDJhdHdlZ28gZGVidWdvd2FuaWEgdyBrb25zb2xpXG4gIGlmICh0eXBlb2Ygd2luZG93ICE9PSAndW5kZWZpbmVkJykge1xuICAgIHdpbmRvdy5ldmVudEJ1cyA9IGV2ZW50QnVzXG4gIH1cbn1cblxuZXhwb3J0IHsgZXZlbnRCdXMgfVxuZXhwb3J0IGRlZmF1bHQgZXZlbnRCdXNcbiIsICIvKipcbiAqIEV2ZW50QnVzIE1peGluIGRsYSBTdGltdWx1cyBDb250cm9sbGVyc1xuICpcbiAqIERvZGFqZSBtZXRvZHkgZG8gXHUwMTQyYXR3ZWdvIGtvcnp5c3RhbmlhIHogRXZlbnRCdXMgdyBrb250cm9sZXJhY2ggU3RpbXVsdXMuXG4gKlxuICogVVx1MDE3Q3ljaWU6XG4gKlxuICogaW1wb3J0IHsgQ29udHJvbGxlciB9IGZyb20gXCJAaG90d2lyZWQvc3RpbXVsdXNcIlxuICogaW1wb3J0IHsgd2l0aEV2ZW50QnVzIH0gZnJvbSBcIi4uL21peGlucy9ldmVudF9idXNfbWl4aW4uanNcIlxuICpcbiAqIGV4cG9ydCBkZWZhdWx0IGNsYXNzIGV4dGVuZHMgd2l0aEV2ZW50QnVzKENvbnRyb2xsZXIpIHtcbiAqICAgY29ubmVjdCgpIHtcbiAqICAgICAvLyBOYXNcdTAxNDJ1Y2h1aiBldmVudHVcbiAqICAgICB0aGlzLmxpc3Rlbignb2U6YmFza2V0OnVwZGF0ZWQnLCB0aGlzLmhhbmRsZUJhc2tldFVwZGF0ZSlcbiAqXG4gKiAgICAgLy8gbHViIHogYXV0by1jbGVhbnVwOlxuICogICAgIHRoaXMubGlzdGVuKCdvZTpiYXNrZXQ6dXBkYXRlZCcsIChkYXRhKSA9PiB7XG4gKiAgICAgICBjb25zb2xlLmxvZygnQmFza2V0IHVwZGF0ZWQ6JywgZGF0YSlcbiAqICAgICB9KVxuICogICB9XG4gKlxuICogICBoYW5kbGVCYXNrZXRVcGRhdGUoZGF0YSkge1xuICogICAgIGNvbnNvbGUubG9nKCdCYXNrZXQgdXBkYXRlZDonLCBkYXRhKVxuICogICB9XG4gKlxuICogICBzb21lQWN0aW9uKCkge1xuICogICAgIC8vIFd5ZW1pdHVqIGV2ZW50XG4gKiAgICAgdGhpcy5icm9hZGNhc3QoJ29lOmJhc2tldDppdGVtLWFkZGVkJywgeyBpdGVtSWQ6IDEyMyB9KVxuICogICB9XG4gKlxuICogICAvLyBkaXNjb25uZWN0KCkgYXV0b21hdHljem5pZSBjenlcdTAxNUJjaSB3c3p5c3RraWUgbGlzdGVuZXJ5IVxuICogfVxuICpcbiAqIEtvcnp5XHUwMTVCY2k6XG4gKiAtIEF1dG9tYXR5Y3puZSBjenlzemN6ZW5pZSBsaXN0ZW5lclx1MDBGM3cgdyBkaXNjb25uZWN0KClcbiAqIC0gS3JcdTAwRjN0c3plIEFQSTogbGlzdGVuKCksIGJyb2FkY2FzdCgpXG4gKiAtIFphY2hvd2FuaWUga29udGVrc3R1ICh0aGlzKSB3IGhhbmRsZXJhY2hcbiAqIC0gRGVidWcgaW5mbyB6IG5hendcdTAxMDUga29udHJvbGVyYVxuICovXG5cbmltcG9ydCB7IGV2ZW50QnVzIH0gZnJvbSAnLi4vdXRpbHMvZXZlbnRfYnVzLmpzJ1xuXG5leHBvcnQgZnVuY3Rpb24gd2l0aEV2ZW50QnVzKEJhc2VDb250cm9sbGVyKSB7XG4gIHJldHVybiBjbGFzcyBleHRlbmRzIEJhc2VDb250cm9sbGVyIHtcbiAgICBjb25zdHJ1Y3RvciguLi5hcmdzKSB7XG4gICAgICBzdXBlciguLi5hcmdzKVxuXG4gICAgICAvLyBQcnplY2hvd3VqIHJlZmVyZW5jamUgZG8gbGlzdGVuZXJcdTAwRjN3IGRsYSBjbGVhbnVwXG4gICAgICB0aGlzLl9ldmVudEJ1c0xpc3RlbmVycyA9IFtdXG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogTmFzXHUwMTQydWNodWogZXZlbnR1IHByemV6IEV2ZW50QnVzXG4gICAgICogQXV0b21hdHljem5pZSB1c3V3YSBsaXN0ZW5lcmEgdyBkaXNjb25uZWN0KClcbiAgICAgKlxuICAgICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICAgKiBAcGFyYW0ge2Z1bmN0aW9ufSBoYW5kbGVyIC0gSGFuZGxlciBmdW5jdGlvblxuICAgICAqIEBwYXJhbSB7b2JqZWN0fSBvcHRpb25zIC0gT3BjamVcbiAgICAgKiBAcGFyYW0ge2Jvb2xlYW59IG9wdGlvbnMub25jZSAtIEN6eSB3eWtvbmFcdTAxMDcgdHlsa28gcmF6IChkZWZhdWx0OiBmYWxzZSlcbiAgICAgKiBAcmV0dXJucyB7ZnVuY3Rpb259IEZ1bmtjamEgZG8gbWFudWFsbmVnbyB1c3VuaVx1MDExOWNpYSBsaXN0ZW5lcmFcbiAgICAgKi9cbiAgICBsaXN0ZW4oZXZlbnROYW1lLCBoYW5kbGVyLCBvcHRpb25zID0ge30pIHtcbiAgICAgIGNvbnN0IHsgb25jZSA9IGZhbHNlIH0gPSBvcHRpb25zXG5cbiAgICAgIC8vIEJpbmQgaGFuZGxlciBkbyB0aGlzIGtvbnRyb2xlcmFcbiAgICAgIGNvbnN0IGJvdW5kSGFuZGxlciA9IGhhbmRsZXIuYmluZCh0aGlzKVxuXG4gICAgICAvLyBEb2RhaiBwcmVmaXggeiBuYXp3XHUwMTA1IGtvbnRyb2xlcmEgZGxhIGRlYnVnb3dhbmlhXG4gICAgICBjb25zdCBjb250cm9sbGVyTmFtZSA9IHRoaXMuaWRlbnRpZmllciB8fCB0aGlzLmNvbnN0cnVjdG9yLm5hbWVcbiAgICAgIGNvbnN0IGRlYnVnSGFuZGxlciA9IChkYXRhKSA9PiB7XG4gICAgICAgIGlmIChldmVudEJ1cy5kZWJ1Zykge1xuICAgICAgICAgIGNvbnNvbGUubG9nKGBbJHtjb250cm9sbGVyTmFtZX1dIFJlY2VpdmVkIGV2ZW50IFwiJHtldmVudE5hbWV9XCJgLCBkYXRhKVxuICAgICAgICB9XG4gICAgICAgIGJvdW5kSGFuZGxlcihkYXRhKVxuICAgICAgfVxuXG4gICAgICAvLyBaYXJlamVzdHJ1aiBsaXN0ZW5lclxuICAgICAgY29uc3QgcmVtb3ZlTGlzdGVuZXIgPSBvbmNlXG4gICAgICAgID8gZXZlbnRCdXMub25jZShldmVudE5hbWUsIGRlYnVnSGFuZGxlcilcbiAgICAgICAgOiBldmVudEJ1cy5vbihldmVudE5hbWUsIGRlYnVnSGFuZGxlcilcblxuICAgICAgLy8gWmFjaG93YWogcmVmZXJlbmNqXHUwMTE5IGRvIGNsZWFudXBcbiAgICAgIHRoaXMuX2V2ZW50QnVzTGlzdGVuZXJzLnB1c2goeyBldmVudE5hbWUsIGhhbmRsZXI6IGRlYnVnSGFuZGxlciwgcmVtb3ZlTGlzdGVuZXIgfSlcblxuICAgICAgLy8gWndyXHUwMEYzXHUwMTA3IGZ1bmtjalx1MDExOSBkbyBtYW51YWxuZWdvIHVzdW5pXHUwMTE5Y2lhXG4gICAgICByZXR1cm4gcmVtb3ZlTGlzdGVuZXJcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBOYXNcdTAxNDJ1Y2h1aiBldmVudHUgdHlsa28gcmF6XG4gICAgICogU2hvcnRoYW5kIGRsYSBsaXN0ZW4oZXZlbnROYW1lLCBoYW5kbGVyLCB7IG9uY2U6IHRydWUgfSlcbiAgICAgKlxuICAgICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICAgKiBAcGFyYW0ge2Z1bmN0aW9ufSBoYW5kbGVyIC0gSGFuZGxlciBmdW5jdGlvblxuICAgICAqIEByZXR1cm5zIHtmdW5jdGlvbn0gRnVua2NqYSBkbyBtYW51YWxuZWdvIHVzdW5pXHUwMTE5Y2lhIGxpc3RlbmVyYVxuICAgICAqL1xuICAgIGxpc3Rlbk9uY2UoZXZlbnROYW1lLCBoYW5kbGVyKSB7XG4gICAgICByZXR1cm4gdGhpcy5saXN0ZW4oZXZlbnROYW1lLCBoYW5kbGVyLCB7IG9uY2U6IHRydWUgfSlcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBXeWVtaXR1aiBldmVudCBwcnpleiBFdmVudEJ1c1xuICAgICAqXG4gICAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgICAqIEBwYXJhbSB7Kn0gZGF0YSAtIERhbmUgZG8gcHJ6ZWthemFuaWFcbiAgICAgKi9cbiAgICBicm9hZGNhc3QoZXZlbnROYW1lLCBkYXRhID0ge30pIHtcbiAgICAgIGNvbnN0IGNvbnRyb2xsZXJOYW1lID0gdGhpcy5pZGVudGlmaWVyIHx8IHRoaXMuY29uc3RydWN0b3IubmFtZVxuXG4gICAgICBpZiAoZXZlbnRCdXMuZGVidWcpIHtcbiAgICAgICAgY29uc29sZS5sb2coYFske2NvbnRyb2xsZXJOYW1lfV0gQnJvYWRjYXN0aW5nIGV2ZW50IFwiJHtldmVudE5hbWV9XCJgLCBkYXRhKVxuICAgICAgfVxuXG4gICAgICBldmVudEJ1cy5lbWl0KGV2ZW50TmFtZSwgZGF0YSlcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBXeWVtaXR1aiBldmVudCBhc3luY2hyb25pY3puaWVcbiAgICAgKlxuICAgICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICAgKiBAcGFyYW0geyp9IGRhdGEgLSBEYW5lIGRvIHByemVrYXphbmlhXG4gICAgICogQHJldHVybnMge1Byb21pc2V9XG4gICAgICovXG4gICAgYXN5bmMgYnJvYWRjYXN0QXN5bmMoZXZlbnROYW1lLCBkYXRhID0ge30pIHtcbiAgICAgIHJldHVybiBldmVudEJ1cy5lbWl0QXN5bmMoZXZlbnROYW1lLCBkYXRhKVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIFBvY3pla2FqIG5hIGV2ZW50XG4gICAgICogUHJ6eWRhdG5lIHcgYXN5bmMgZmxvd3NcbiAgICAgKlxuICAgICAqIEBwYXJhbSB7c3RyaW5nfSBldmVudE5hbWUgLSBOYXp3YSBldmVudHVcbiAgICAgKiBAcGFyYW0ge251bWJlcn0gdGltZW91dCAtIFRpbWVvdXQgdyBtc1xuICAgICAqIEByZXR1cm5zIHtQcm9taXNlfVxuICAgICAqL1xuICAgIGFzeW5jIHdhaXRGb3JFdmVudChldmVudE5hbWUsIHRpbWVvdXQgPSA1MDAwKSB7XG4gICAgICByZXR1cm4gZXZlbnRCdXMud2FpdEZvcihldmVudE5hbWUsIHRpbWVvdXQpXG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogVXN1XHUwMTQ0IGtvbmtyZXRueSBsaXN0ZW5lclxuICAgICAqXG4gICAgICogQHBhcmFtIHtzdHJpbmd9IGV2ZW50TmFtZSAtIE5hendhIGV2ZW50dVxuICAgICAqIEBwYXJhbSB7ZnVuY3Rpb259IGhhbmRsZXIgLSBIYW5kbGVyIGRvIHVzdW5pXHUwMTE5Y2lhXG4gICAgICovXG4gICAgc3RvcExpc3RlbmluZyhldmVudE5hbWUsIGhhbmRsZXIpIHtcbiAgICAgIGV2ZW50QnVzLm9mZihldmVudE5hbWUsIGhhbmRsZXIpXG5cbiAgICAgIC8vIFVzdVx1MDE0NCB6IG5hc3plaiBsaXN0eVxuICAgICAgdGhpcy5fZXZlbnRCdXNMaXN0ZW5lcnMgPSB0aGlzLl9ldmVudEJ1c0xpc3RlbmVycy5maWx0ZXIoXG4gICAgICAgIGxpc3RlbmVyID0+ICEobGlzdGVuZXIuZXZlbnROYW1lID09PSBldmVudE5hbWUgJiYgbGlzdGVuZXIuaGFuZGxlciA9PT0gaGFuZGxlcilcbiAgICAgIClcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBVc3VcdTAxNDQgd3N6eXN0a2llIGxpc3RlbmVyeSB0ZWdvIGtvbnRyb2xlcmFcbiAgICAgKiBBdXRvbWF0eWN6bmllIHd5d29cdTAxNDJ5d2FuZSB3IGRpc2Nvbm5lY3QoKVxuICAgICAqL1xuICAgIHN0b3BMaXN0ZW5pbmdBbGwoKSB7XG4gICAgICB0aGlzLl9ldmVudEJ1c0xpc3RlbmVycy5mb3JFYWNoKCh7IHJlbW92ZUxpc3RlbmVyIH0pID0+IHtcbiAgICAgICAgcmVtb3ZlTGlzdGVuZXIoKVxuICAgICAgfSlcblxuICAgICAgdGhpcy5fZXZlbnRCdXNMaXN0ZW5lcnMgPSBbXVxuXG4gICAgICBpZiAoZXZlbnRCdXMuZGVidWcpIHtcbiAgICAgICAgY29uc3QgY29udHJvbGxlck5hbWUgPSB0aGlzLmlkZW50aWZpZXIgfHwgdGhpcy5jb25zdHJ1Y3Rvci5uYW1lXG4gICAgICAgIGNvbnNvbGUubG9nKGBbJHtjb250cm9sbGVyTmFtZX1dIEFsbCBFdmVudEJ1cyBsaXN0ZW5lcnMgcmVtb3ZlZGApXG4gICAgICB9XG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogT3ZlcnJpZGUgZGlzY29ubmVjdCgpIFx1MDE3Q2VieSBhdXRvbWF0eWN6bmllIGN6eVx1MDE1QmNpXHUwMTA3IGxpc3RlbmVyeVxuICAgICAqL1xuICAgIGRpc2Nvbm5lY3QoKSB7XG4gICAgICB0aGlzLnN0b3BMaXN0ZW5pbmdBbGwoKVxuXG4gICAgICAvLyBXeXdvXHUwMTQyYWogb3J5Z2luYWxueSBkaXNjb25uZWN0IGplXHUwMTVCbGkgaXN0bmllamVcbiAgICAgIGlmIChzdXBlci5kaXNjb25uZWN0KSB7XG4gICAgICAgIHN1cGVyLmRpc2Nvbm5lY3QoKVxuICAgICAgfVxuICAgIH1cbiAgfVxufVxuXG5leHBvcnQgZGVmYXVsdCB3aXRoRXZlbnRCdXNcbiIsICIvKipcbiAqIE9uZS1QYWdlIENoZWNrb3V0IFN0cmlwZSBJbnRlZ3JhdGlvbiBDb250cm9sbGVyXG4gKlxuICogSW50ZWdyYXRlcyBTdHJpcGUgcGF5bWVudHMgd2l0aCB0aGUgb25lLXBhZ2UgY2hlY2tvdXQgbW9kdWxlIHZpYSBFdmVudEJ1cy5cbiAqIEltcGxlbWVudHMgdGhlIGV2ZW50IGNvbnRyYWN0IGRlZmluZWQgaW4gb25lLXBhZ2UgY2hlY2tvdXQgZG9jdW1lbnRhdGlvbi5cbiAqXG4gKiBSZXF1aXJlZCBFdmVudHMgdG8gTGlzdGVuOlxuICogLSBvZTpwYXltZW50Om1ldGhvZC1zZWxlY3RlZCAtIFVzZXIgc2VsZWN0cyBwYXltZW50IG1ldGhvZFxuICogLSBvZTpwYXltZW50OmNvbmZpcm0tcmVxdWVzdGVkIC0gQ29yZSByZXF1ZXN0cyBwYXltZW50IGNvbmZpcm1hdGlvblxuICpcbiAqIFJlcXVpcmVkIEV2ZW50cyB0byBFbWl0OlxuICogLSBvZTpwYXltZW50OmNvbmZpcm1lZCAtIFBheW1lbnQgc3VjY2Vzc2Z1bGx5IGNvbmZpcm1lZFxuICogLSBvZTpwYXltZW50OmZhaWxlZCAtIFBheW1lbnQgZmFpbGVkXG4gKlxuICogQHNlZSBkb2NzL1BBWU1FTlRfUFJPVklERVJfSU5URUdSQVRJT05fR1VJREUubWRcbiAqIEBzZWUgZG9jcy9kaWFncmFtcy9wYXltZW50LXByb3ZpZGVyLWludGVncmF0aW9uLzAzLWV2ZW50LWNvbnRyYWN0LWRldGFpbHMucHVtbFxuICovXG5cbmltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tIFwiQGhvdHdpcmVkL3N0aW11bHVzXCJcbmltcG9ydCB7IHdpdGhFdmVudEJ1cyB9IGZyb20gXCIuLi9taXhpbnMvZXZlbnRfYnVzX21peGluLmpzXCJcblxuZXhwb3J0IGRlZmF1bHQgY2xhc3MgZXh0ZW5kcyB3aXRoRXZlbnRCdXMoQ29udHJvbGxlcikge1xuICBzdGF0aWMgdmFsdWVzID0ge1xuICAgIHB1Ymxpc2hhYmxlS2V5OiBTdHJpbmcsXG4gICAgbW9kZTogU3RyaW5nLFxuICAgIHJldHVyblVybDogU3RyaW5nLFxuICAgIGFwaVVybDogU3RyaW5nLCAgLy8gQVBJIGJhc2UgVVJMIChlLmcuLCAvaW5kZXgucGhwP2NsPU9lQ2hlY2tvdXRBcGkpXG4gIH1cblxuICBzdGF0aWMgdGFyZ2V0cyA9IFtcImVsZW1lbnRcIiwgXCJsb2FkZXJcIiwgXCJlcnJvclwiXVxuXG4gIC8qKlxuICAgKiBTdGltdWx1cyBsaWZlY3ljbGU6IENvbnRyb2xsZXIgY29ubmVjdGVkIHRvIERPTVxuICAgKi9cbiAgY29ubmVjdCgpIHtcbiAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBDb25uZWN0ZWQnKVxuXG4gICAgLy8gUmVnaXN0ZXIgRXZlbnRCdXMgbGlzdGVuZXJzIChhdXRvbWF0aWMgY2xlYW51cCB2aWEgd2l0aEV2ZW50QnVzIG1peGluKVxuICAgIHRoaXMubGlzdGVuKCdvZTpwYXltZW50Om1ldGhvZC1zZWxlY3RlZCcsIHRoaXMuaGFuZGxlTWV0aG9kU2VsZWN0ZWQuYmluZCh0aGlzKSlcbiAgICB0aGlzLmxpc3Rlbignb2U6cGF5bWVudDpjb25maXJtLXJlcXVlc3RlZCcsIHRoaXMuaGFuZGxlQ29uZmlybVJlcXVlc3QuYmluZCh0aGlzKSlcbiAgICB0aGlzLmxpc3Rlbignb2U6Zm9vdGVyOnN1Ym1pdC1jbGlja2VkJywgdGhpcy5oYW5kbGVGb290ZXJTdWJtaXQuYmluZCh0aGlzKSlcblxuICAgIC8vIEluaXRpYWxpemUgc3RhdGVcbiAgICB0aGlzLnN0cmlwZSA9IG51bGxcbiAgICB0aGlzLmVsZW1lbnRzID0gbnVsbFxuICAgIHRoaXMucGF5bWVudEVsZW1lbnQgPSBudWxsXG4gICAgdGhpcy5jdXJyZW50Q29udHJhY3RJZCA9IG51bGxcbiAgICB0aGlzLmN1cnJlbnRPcmRlcklkID0gbnVsbFxuICB9XG5cbiAgLyoqXG4gICAqIFN0aW11bHVzIGxpZmVjeWNsZTogQ29udHJvbGxlciBkaXNjb25uZWN0ZWQgZnJvbSBET01cbiAgICovXG4gIGRpc2Nvbm5lY3QoKSB7XG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gRGlzY29ubmVjdGVkJylcblxuICAgIC8vIEV2ZW50QnVzIGxpc3RlbmVycyBhcmUgYXV0b21hdGljYWxseSBjbGVhbmVkIHVwIGJ5IHdpdGhFdmVudEJ1cyBtaXhpblxuXG4gICAgLy8gQ2xlYW51cCBTdHJpcGUgcmVzb3VyY2VzXG4gICAgaWYgKHRoaXMucGF5bWVudEVsZW1lbnQpIHtcbiAgICAgIHRoaXMucGF5bWVudEVsZW1lbnQuZGVzdHJveSgpXG4gICAgICB0aGlzLnBheW1lbnRFbGVtZW50ID0gbnVsbFxuICAgIH1cbiAgICB0aGlzLmVsZW1lbnRzID0gbnVsbFxuICAgIHRoaXMuc3RyaXBlID0gbnVsbFxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBvZTpwYXltZW50Om1ldGhvZC1zZWxlY3RlZCBldmVudFxuICAgKlxuICAgKiBFdmVudCBEYXRhOlxuICAgKiB7XG4gICAqICAgcGF5bWVudE1ldGhvZElkOiBzdHJpbmcsICAvLyBlLmcuLCAnb3hpZHN0cmlwZScsICdwYXlwYWwnXG4gICAqICAgcGF5bWVudE1ldGhvZFRpdGxlOiBzdHJpbmcgLy8gZS5nLiwgJ0NyZWRpdCBDYXJkIChTdHJpcGUpJ1xuICAgKiB9XG4gICAqXG4gICAqIFJlc3BvbnNpYmlsaXR5OlxuICAgKiAtIENoZWNrIGlmIHBheW1lbnRNZXRob2RJZCBtYXRjaGVzIFN0cmlwZVxuICAgKiAtIFNob3cgU3RyaXBlIFVJIGlmIG1hdGNoXG4gICAqIC0gSGlkZSBTdHJpcGUgVUkgaWYgbm8gbWF0Y2hcbiAgICovXG4gIGFzeW5jIGhhbmRsZU1ldGhvZFNlbGVjdGVkKGRhdGEpIHtcbiAgICBjb25zdCB7IHBheW1lbnRNZXRob2RJZCB9ID0gZGF0YVxuXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gUGF5bWVudCBtZXRob2Qgc2VsZWN0ZWQ6JywgcGF5bWVudE1ldGhvZElkKVxuXG4gICAgaWYgKCF0aGlzLmlzU3RyaXBlTWV0aG9kKHBheW1lbnRNZXRob2RJZCkpIHtcbiAgICAgIHRoaXMuaGlkZVN0cmlwZVVJKClcbiAgICAgIHJldHVyblxuICAgIH1cblxuICAgIC8vIFNob3cgU3RyaXBlIFVJXG4gICAgdGhpcy5zaG93U3RyaXBlVUkoKVxuXG4gICAgLy8gTG9hZCBTdHJpcGUuanMgU0RLIGlmIG5vdCBsb2FkZWRcbiAgICBpZiAoIXRoaXMuc3RyaXBlKSB7XG4gICAgICBhd2FpdCB0aGlzLmxvYWRTdHJpcGVTREsoKVxuICAgIH1cblxuICAgIC8vIEluaXRpYWxpemUgUGF5bWVudCBFbGVtZW50XG4gICAgYXdhaXQgdGhpcy5pbml0aWFsaXplUGF5bWVudEVsZW1lbnQoKVxuICB9XG5cbiAgLyoqXG4gICAqIEhhbmRsZSBvZTpmb290ZXI6c3VibWl0LWNsaWNrZWQgZXZlbnRcbiAgICpcbiAgICogRXZlbnQgRGF0YTpcbiAgICoge1xuICAgKiAgIHBheW1lbnRNZXRob2Q6IHN0cmluZyxcbiAgICogICBiYXNrZXRJZDogc3RyaW5nLFxuICAgKiAgIHRvdGFsUHJpY2U6IG51bWJlcixcbiAgICogICBjdXJyZW5jeTogc3RyaW5nLFxuICAgKiAgIGNvbmZpcm1lZDogYm9vbGVhblxuICAgKiB9XG4gICAqXG4gICAqIFJlc3BvbnNpYmlsaXR5OlxuICAgKiAtIFByb2Nlc3MgZnVsbCBwYXltZW50IGZsb3c6IGNyZWF0ZSBjb250cmFjdCBcdTIxOTIgY29uZmlybSBwYXltZW50IFx1MjE5MiBwbGFjZSBvcmRlclxuICAgKi9cbiAgYXN5bmMgaGFuZGxlRm9vdGVyU3VibWl0KGRhdGEpIHtcbiAgICBjb25zdCB7IHBheW1lbnRNZXRob2QsIGJhc2tldElkLCB0b3RhbFByaWNlLCBjdXJyZW5jeSB9ID0gZGF0YVxuXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gRm9vdGVyIHN1Ym1pdCBjbGlja2VkOicsIHtcbiAgICAgIHBheW1lbnRNZXRob2QsXG4gICAgICBiYXNrZXRJZCxcbiAgICAgIHRvdGFsUHJpY2UsXG4gICAgICBjdXJyZW5jeVxuICAgIH0pXG5cbiAgICBpZiAoIXRoaXMuaXNTdHJpcGVNZXRob2QocGF5bWVudE1ldGhvZCkpIHtcbiAgICAgIHJldHVybiAvLyBOb3QgU3RyaXBlIHBheW1lbnRcbiAgICB9XG5cbiAgICAvLyBTaG93IFN0cmlwZSBVSSAod3JhcHBlciBhbmQgZWxlbWVudCBjb250YWluZXIpXG4gICAgdGhpcy5zaG93U3RyaXBlVUkoKVxuXG4gICAgLy8gU2hvdyBsb2FkZXJcbiAgICB0aGlzLnNob3dMb2FkZXIoKVxuICAgIHRoaXMuaGlkZUVycm9yKClcblxuICAgIC8vIEJyb2FkY2FzdCBwcm9jZXNzaW5nIGV2ZW50XG4gICAgdGhpcy5icm9hZGNhc3QoJ29lOnBheW1lbnQ6cHJvY2Vzc2luZycsIHtcbiAgICAgIHBheW1lbnRNZXRob2Q6IHBheW1lbnRNZXRob2RcbiAgICB9KVxuXG4gICAgdHJ5IHtcbiAgICAgIC8vIFN0ZXAgMTogQ3JlYXRlIGNvbnRyYWN0IHZpYSBPUEMgQVBJICh3aGljaCBjcmVhdGVzIENoZWNrb3V0IFNlc3Npb24pXG4gICAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBTdGVwIDE6IENyZWF0aW5nIHBheW1lbnQgY29udHJhY3QuLi4nKVxuICAgICAgY29uc3QgY29udHJhY3RSZXN1bHQgPSBhd2FpdCB0aGlzLmNyZWF0ZUNvbnRyYWN0KHBheW1lbnRNZXRob2QpXG5cbiAgICAgIGlmICghY29udHJhY3RSZXN1bHQuc3VjY2Vzcykge1xuICAgICAgICB0aHJvdyBuZXcgRXJyb3IoY29udHJhY3RSZXN1bHQuZXJyb3JNZXNzYWdlIHx8ICdGYWlsZWQgdG8gY3JlYXRlIHBheW1lbnQgY29udHJhY3QnKVxuICAgICAgfVxuXG4gICAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBDb250cmFjdCBjcmVhdGVkOicsIHtcbiAgICAgICAgY29udHJhY3RJZDogY29udHJhY3RSZXN1bHQuY29udHJhY3RJZCxcbiAgICAgICAgbWV0YWRhdGE6IGNvbnRyYWN0UmVzdWx0Lm1ldGFkYXRhXG4gICAgICB9KVxuXG4gICAgICAvLyBTdGVwIDI6IENoZWNrIGlmIHdlIGhhdmUgcmVkaXJlY3QgVVJMIChDaGVja291dCBTZXNzaW9uKVxuICAgICAgY29uc3QgcmVkaXJlY3RVcmwgPSBjb250cmFjdFJlc3VsdC5tZXRhZGF0YT8ucmVkaXJlY3RVcmwgfHwgY29udHJhY3RSZXN1bHQubWV0YWRhdGE/LmNoZWNrb3V0VXJsXG5cbiAgICAgIGlmICghcmVkaXJlY3RVcmwpIHtcbiAgICAgICAgdGhyb3cgbmV3IEVycm9yKCdObyByZWRpcmVjdCBVUkwgcHJvdmlkZWQgYnkgcGF5bWVudCBoYW5kbGVyJylcbiAgICAgIH1cblxuICAgICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gUmVkaXJlY3RpbmcgdG8gU3RyaXBlIENoZWNrb3V0OicsIHJlZGlyZWN0VXJsKVxuXG4gICAgICAvLyBCcm9hZGNhc3QgcHJvY2Vzc2luZyBldmVudFxuICAgICAgdGhpcy5icm9hZGNhc3QoJ29lOnBheW1lbnQ6cmVkaXJlY3QnLCB7XG4gICAgICAgIHByb3ZpZGVyOiAnc3RyaXBlJyxcbiAgICAgICAgY29udHJhY3RJZDogY29udHJhY3RSZXN1bHQuY29udHJhY3RJZCxcbiAgICAgICAgcmVkaXJlY3RVcmw6IHJlZGlyZWN0VXJsXG4gICAgICB9KVxuXG4gICAgICAvLyBSZWRpcmVjdCB0byBTdHJpcGUgQ2hlY2tvdXRcbiAgICAgIHdpbmRvdy5sb2NhdGlvbi5ocmVmID0gcmVkaXJlY3RVcmxcblxuICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIFBheW1lbnQgcHJvY2Vzc2luZyBmYWlsZWQ6JywgZXJyb3IpXG5cbiAgICAgIC8vIFNob3cgZXJyb3JcbiAgICAgIHRoaXMuc2hvd0Vycm9yKGVycm9yLm1lc3NhZ2UgfHwgJ1BheW1lbnQgcHJvY2Vzc2luZyBmYWlsZWQnKVxuXG4gICAgICAvLyBCcm9hZGNhc3QgZXJyb3JcbiAgICAgIHRoaXMuYnJvYWRjYXN0KCdvZTpwYXltZW50OmVycm9yJywge1xuICAgICAgICBlcnJvcjogZXJyb3IubWVzc2FnZSxcbiAgICAgICAgcGF5bWVudE1ldGhvZDogcGF5bWVudE1ldGhvZFxuICAgICAgfSlcbiAgICB9IGZpbmFsbHkge1xuICAgICAgdGhpcy5oaWRlTG9hZGVyKClcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogSGFuZGxlIG9lOnBheW1lbnQ6Y29uZmlybS1yZXF1ZXN0ZWQgZXZlbnRcbiAgICpcbiAgICogRXZlbnQgRGF0YTpcbiAgICoge1xuICAgKiAgIGNvbnRyYWN0SWQ6IHN0cmluZywgICAgICAgLy8gUGF5bWVudENvbnRyYWN0IElEXG4gICAqICAgY2xpZW50U2VjcmV0OiBzdHJpbmcsICAgICAvLyBTdHJpcGUgY2xpZW50IHNlY3JldCAoZnJvbSBQYXltZW50SW50ZW50KVxuICAgKiAgIHBheW1lbnRNZXRob2RJZDogc3RyaW5nLCAgLy8gZS5nLiwgJ294aWRzdHJpcGUnXG4gICAqICAgcmV0dXJuVXJsOiBzdHJpbmcgICAgICAgICAvLyBVUkwgdG8gcmVkaXJlY3QgYWZ0ZXIgU0NBXG4gICAqIH1cbiAgICpcbiAgICogUmVzcG9uc2liaWxpdHk6XG4gICAqIC0gQ2hlY2sgaWYgcGF5bWVudE1ldGhvZElkIG1hdGNoZXMgU3RyaXBlXG4gICAqIC0gUHJvY2VzcyBwYXltZW50IHdpdGggU3RyaXBlIFNES1xuICAgKiAtIEVtaXQgb2U6cGF5bWVudDpjb25maXJtZWQgb3Igb2U6cGF5bWVudDpmYWlsZWRcbiAgICovXG4gIGFzeW5jIGhhbmRsZUNvbmZpcm1SZXF1ZXN0KGRhdGEpIHtcbiAgICBjb25zdCB7IHBheW1lbnRNZXRob2RJZCwgY2xpZW50U2VjcmV0LCBjb250cmFjdElkLCBvcmRlcklkIH0gPSBkYXRhXG5cbiAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBDb25maXJtIHJlcXVlc3Q6Jywge1xuICAgICAgcGF5bWVudE1ldGhvZElkLFxuICAgICAgY2xpZW50U2VjcmV0OiBjbGllbnRTZWNyZXQgPyAnKioqJyA6ICdtaXNzaW5nJyxcbiAgICAgIGNvbnRyYWN0SWQsXG4gICAgICBvcmRlcklkXG4gICAgfSlcblxuICAgIGlmICghdGhpcy5pc1N0cmlwZU1ldGhvZChwYXltZW50TWV0aG9kSWQpKSB7XG4gICAgICByZXR1cm4gLy8gTm90IG15IHJlc3BvbnNpYmlsaXR5XG4gICAgfVxuXG4gICAgLy8gU2F2ZSBzdGF0ZVxuICAgIHRoaXMuY3VycmVudENvbnRyYWN0SWQgPSBjb250cmFjdElkXG4gICAgdGhpcy5jdXJyZW50T3JkZXJJZCA9IG9yZGVySWRcblxuICAgIC8vIFNob3cgbG9hZGVyXG4gICAgdGhpcy5zaG93TG9hZGVyKClcbiAgICB0aGlzLmhpZGVFcnJvcigpXG5cbiAgICB0cnkge1xuICAgICAgLy8gQ29uZmlybSBwYXltZW50IHdpdGggU3RyaXBlXG4gICAgICBjb25zdCByZXN1bHQgPSBhd2FpdCB0aGlzLmNvbmZpcm1QYXltZW50KGNsaWVudFNlY3JldClcblxuICAgICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gUGF5bWVudCBjb25maXJtZWQ6JywgcmVzdWx0KVxuXG4gICAgICAvLyBFbWl0IHN1Y2Nlc3MgZXZlbnRcbiAgICAgIHRoaXMuYnJvYWRjYXN0UGF5bWVudENvbmZpcm1lZChyZXN1bHQpXG4gICAgfSBjYXRjaCAoZXJyb3IpIHtcbiAgICAgIGNvbnNvbGUuZXJyb3IoJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gUGF5bWVudCBmYWlsZWQ6JywgZXJyb3IpXG5cbiAgICAgIC8vIFNob3cgZXJyb3JcbiAgICAgIHRoaXMuc2hvd0Vycm9yKGVycm9yLm1lc3NhZ2UpXG5cbiAgICAgIC8vIEVtaXQgZmFpbHVyZSBldmVudFxuICAgICAgdGhpcy5icm9hZGNhc3RQYXltZW50RmFpbGVkKGVycm9yKVxuICAgIH0gZmluYWxseSB7XG4gICAgICB0aGlzLmhpZGVMb2FkZXIoKVxuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBDaGVjayBpZiBwYXltZW50IG1ldGhvZCBJRCBiZWxvbmdzIHRvIFN0cmlwZVxuICAgKi9cbiAgaXNTdHJpcGVNZXRob2QocGF5bWVudE1ldGhvZElkKSB7XG4gICAgaWYgKCFwYXltZW50TWV0aG9kSWQpIHtcbiAgICAgIHJldHVybiBmYWxzZVxuICAgIH1cblxuICAgIGNvbnN0IHN0cmlwZVBheW1lbnRNZXRob2RzID0gW1xuICAgICAgJ294aWRzdHJpcGUnLFxuICAgICAgJ294aWRzdHJpcGVfY2FyZCcsXG4gICAgICAnb3hpZHN0cmlwZV93YWxsZXQnLFxuICAgICAgJ29lX3BheW1lbnRzX3N0cmlwZV93YWxsZXQnLCAgLy8gTW9kdWxlIElEXG4gICAgICAnc3RyaXBlJyxcbiAgICBdXG5cbiAgICByZXR1cm4gc3RyaXBlUGF5bWVudE1ldGhvZHMuc29tZShtZXRob2QgPT5cbiAgICAgIHBheW1lbnRNZXRob2RJZC50b0xvd2VyQ2FzZSgpLmluY2x1ZGVzKG1ldGhvZC50b0xvd2VyQ2FzZSgpKVxuICAgIClcbiAgfVxuXG4gIC8qKlxuICAgKiBMb2FkIFN0cmlwZS5qcyBTREsgZHluYW1pY2FsbHlcbiAgICovXG4gIGFzeW5jIGxvYWRTdHJpcGVTREsoKSB7XG4gICAgaWYgKHdpbmRvdy5TdHJpcGUpIHtcbiAgICAgIHRoaXMuc3RyaXBlID0gd2luZG93LlN0cmlwZSh0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUpXG4gICAgICByZXR1cm5cbiAgICB9XG5cbiAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBMb2FkaW5nIFN0cmlwZS5qcyBTREsuLi4nKVxuXG4gICAgLy8gTG9hZCBTdHJpcGUuanMgc2NyaXB0XG4gICAgYXdhaXQgbmV3IFByb21pc2UoKHJlc29sdmUsIHJlamVjdCkgPT4ge1xuICAgICAgY29uc3Qgc2NyaXB0ID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnc2NyaXB0JylcbiAgICAgIHNjcmlwdC5zcmMgPSAnaHR0cHM6Ly9qcy5zdHJpcGUuY29tL3YzLydcbiAgICAgIHNjcmlwdC5hc3luYyA9IHRydWVcbiAgICAgIHNjcmlwdC5vbmxvYWQgPSByZXNvbHZlXG4gICAgICBzY3JpcHQub25lcnJvciA9IHJlamVjdFxuICAgICAgZG9jdW1lbnQuaGVhZC5hcHBlbmRDaGlsZChzY3JpcHQpXG4gICAgfSlcblxuICAgIHRoaXMuc3RyaXBlID0gd2luZG93LlN0cmlwZSh0aGlzLnB1Ymxpc2hhYmxlS2V5VmFsdWUpXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gU3RyaXBlLmpzIFNESyBsb2FkZWQnKVxuICB9XG5cbiAgLyoqXG4gICAqIEluaXRpYWxpemUgU3RyaXBlIFBheW1lbnQgRWxlbWVudCB3aXRoIGNsaWVudCBzZWNyZXRcbiAgICpcbiAgICogQHBhcmFtIHtzdHJpbmd9IGNsaWVudFNlY3JldCAtIFN0cmlwZSBQYXltZW50SW50ZW50IGNsaWVudCBzZWNyZXRcbiAgICovXG4gIGFzeW5jIGluaXRpYWxpemVQYXltZW50RWxlbWVudChjbGllbnRTZWNyZXQgPSBudWxsKSB7XG4gICAgaWYgKCF0aGlzLnN0cmlwZSkge1xuICAgICAgY29uc29sZS5lcnJvcignW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBTdHJpcGUgU0RLIG5vdCBsb2FkZWQnKVxuICAgICAgcmV0dXJuXG4gICAgfVxuXG4gICAgaWYgKHRoaXMucGF5bWVudEVsZW1lbnQpIHtcbiAgICAgIC8vIEFscmVhZHkgaW5pdGlhbGl6ZWQgLSBkZXN0cm95IGFuZCByZWNyZWF0ZSB3aXRoIG5ldyBjbGllbnQgc2VjcmV0XG4gICAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBEZXN0cm95aW5nIGV4aXN0aW5nIFBheW1lbnQgRWxlbWVudC4uLicpXG4gICAgICB0aGlzLnBheW1lbnRFbGVtZW50LmRlc3Ryb3koKVxuICAgICAgdGhpcy5wYXltZW50RWxlbWVudCA9IG51bGxcbiAgICAgIHRoaXMuZWxlbWVudHMgPSBudWxsXG4gICAgfVxuXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gSW5pdGlhbGl6aW5nIFBheW1lbnQgRWxlbWVudC4uLicsIHtcbiAgICAgIGhhc0NsaWVudFNlY3JldDogISFjbGllbnRTZWNyZXRcbiAgICB9KVxuXG4gICAgLy8gTWFrZSBzdXJlIHRoZSBlbGVtZW50IGNvbnRhaW5lciBpcyB2aXNpYmxlIGJlZm9yZSBtb3VudGluZ1xuICAgIGlmICh0aGlzLmhhc0VsZW1lbnRUYXJnZXQpIHtcbiAgICAgIHRoaXMuZWxlbWVudFRhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ2Jsb2NrJ1xuICAgIH1cblxuICAgIC8vIENyZWF0ZSBFbGVtZW50cyBpbnN0YW5jZVxuICAgIGNvbnN0IGVsZW1lbnRzT3B0aW9ucyA9IHtcbiAgICAgIGFwcGVhcmFuY2U6IHtcbiAgICAgICAgdGhlbWU6ICdzdHJpcGUnLFxuICAgICAgfSxcbiAgICB9XG5cbiAgICAvLyBJZiB3ZSBoYXZlIGEgY2xpZW50IHNlY3JldCwgdXNlIGl0LiBPdGhlcndpc2UgdXNlIHBsYWNlaG9sZGVyIG1vZGUuXG4gICAgaWYgKGNsaWVudFNlY3JldCkge1xuICAgICAgZWxlbWVudHNPcHRpb25zLmNsaWVudFNlY3JldCA9IGNsaWVudFNlY3JldFxuICAgIH0gZWxzZSB7XG4gICAgICAvLyBQbGFjZWhvbGRlciBtb2RlIGZvciBpbml0aWFsIFVJIHJlbmRlcmluZ1xuICAgICAgZWxlbWVudHNPcHRpb25zLm1vZGUgPSAncGF5bWVudCdcbiAgICAgIGVsZW1lbnRzT3B0aW9ucy5hbW91bnQgPSAxMDAwXG4gICAgICBlbGVtZW50c09wdGlvbnMuY3VycmVuY3kgPSAnZXVyJ1xuICAgIH1cblxuICAgIHRoaXMuZWxlbWVudHMgPSB0aGlzLnN0cmlwZS5lbGVtZW50cyhlbGVtZW50c09wdGlvbnMpXG5cbiAgICAvLyBDcmVhdGUgYW5kIG1vdW50IFBheW1lbnQgRWxlbWVudFxuICAgIHRoaXMucGF5bWVudEVsZW1lbnQgPSB0aGlzLmVsZW1lbnRzLmNyZWF0ZSgncGF5bWVudCcpXG5cbiAgICAvLyBFbnN1cmUgdGFyZ2V0IGV4aXN0cyBhbmQgaXMgdmlzaWJsZVxuICAgIGlmICghdGhpcy5oYXNFbGVtZW50VGFyZ2V0KSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IoJ1BheW1lbnQgRWxlbWVudCB0YXJnZXQgbm90IGZvdW5kJylcbiAgICB9XG5cbiAgICB0cnkge1xuICAgICAgdGhpcy5wYXltZW50RWxlbWVudC5tb3VudCh0aGlzLmVsZW1lbnRUYXJnZXQpXG4gICAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBQYXltZW50IEVsZW1lbnQgbW91bnRlZCBzdWNjZXNzZnVsbHknKVxuICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICBjb25zb2xlLmVycm9yKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIEZhaWxlZCB0byBtb3VudCBQYXltZW50IEVsZW1lbnQ6JywgZXJyb3IpXG4gICAgICB0aHJvdyBlcnJvclxuICAgIH1cblxuICAgIGNvbnNvbGUubG9nKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIFBheW1lbnQgRWxlbWVudCBpbml0aWFsaXplZCcpXG4gIH1cblxuICAvKipcbiAgICogQ29uZmlybSBwYXltZW50IHdpdGggU3RyaXBlIFNES1xuICAgKlxuICAgKiBAcGFyYW0ge3N0cmluZ30gY2xpZW50U2VjcmV0IC0gU3RyaXBlIFBheW1lbnRJbnRlbnQgY2xpZW50IHNlY3JldCAobm90IHVzZWQgLSBFbGVtZW50cyBhbHJlYWR5IGhhcyBpdClcbiAgICogQHJldHVybnMge1Byb21pc2U8T2JqZWN0Pn0gLSBQYXltZW50IHJlc3VsdFxuICAgKi9cbiAgYXN5bmMgY29uZmlybVBheW1lbnQoY2xpZW50U2VjcmV0KSB7XG4gICAgaWYgKCF0aGlzLnN0cmlwZSB8fCAhdGhpcy5lbGVtZW50cykge1xuICAgICAgdGhyb3cgbmV3IEVycm9yKCdTdHJpcGUgU0RLIG5vdCBpbml0aWFsaXplZCcpXG4gICAgfVxuXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gQ29uZmlybWluZyBwYXltZW50IHdpdGggU3RyaXBlLi4uJywge1xuICAgICAgaGFzQ2xpZW50U2VjcmV0OiAhIWNsaWVudFNlY3JldFxuICAgIH0pXG5cbiAgICAvLyBDb25maXJtIHBheW1lbnQgKEVsZW1lbnRzIGluc3RhbmNlIGFscmVhZHkgaGFzIHRoZSBjbGllbnQgc2VjcmV0KVxuICAgIGNvbnN0IHJlc3VsdCA9IGF3YWl0IHRoaXMuc3RyaXBlLmNvbmZpcm1QYXltZW50KHtcbiAgICAgIGVsZW1lbnRzOiB0aGlzLmVsZW1lbnRzLFxuICAgICAgY29uZmlybVBhcmFtczoge1xuICAgICAgICByZXR1cm5fdXJsOiB0aGlzLnJldHVyblVybFZhbHVlIHx8IHdpbmRvdy5sb2NhdGlvbi5vcmlnaW4gKyAnL29yZGVyJyxcbiAgICAgIH0sXG4gICAgICByZWRpcmVjdDogJ2lmX3JlcXVpcmVkJywgLy8gT25seSByZWRpcmVjdCBpZiAzRCBTZWN1cmUgaXMgbmVlZGVkXG4gICAgfSlcblxuICAgIC8vIEhhbmRsZSByZXN1bHRcbiAgICBpZiAocmVzdWx0LmVycm9yKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IocmVzdWx0LmVycm9yLm1lc3NhZ2UgfHwgJ1BheW1lbnQgY29uZmlybWF0aW9uIGZhaWxlZCcpXG4gICAgfVxuXG4gICAgaWYgKHJlc3VsdC5wYXltZW50SW50ZW50Py5zdGF0dXMgPT09ICdzdWNjZWVkZWQnKSB7XG4gICAgICByZXR1cm4ge1xuICAgICAgICBwYXltZW50SW50ZW50SWQ6IHJlc3VsdC5wYXltZW50SW50ZW50LmlkLFxuICAgICAgICBzdGF0dXM6IHJlc3VsdC5wYXltZW50SW50ZW50LnN0YXR1cyxcbiAgICAgICAgYW1vdW50OiByZXN1bHQucGF5bWVudEludGVudC5hbW91bnQsXG4gICAgICAgIGN1cnJlbmN5OiByZXN1bHQucGF5bWVudEludGVudC5jdXJyZW5jeSxcbiAgICAgIH1cbiAgICB9XG5cbiAgICAvLyBQYXltZW50IG5vdCBzdWNjZWVkZWQgeWV0IChlLmcuLCByZXF1aXJlcyBhY3Rpb24pXG4gICAgdGhyb3cgbmV3IEVycm9yKGBQYXltZW50IG5vdCBjb25maXJtZWQuIFN0YXR1czogJHtyZXN1bHQucGF5bWVudEludGVudD8uc3RhdHVzIHx8ICd1bmtub3duJ31gKVxuICB9XG5cbiAgLyoqXG4gICAqIEJyb2FkY2FzdCBvZTpwYXltZW50OmNvbmZpcm1lZCBldmVudFxuICAgKi9cbiAgYnJvYWRjYXN0UGF5bWVudENvbmZpcm1lZChwYXltZW50UmVzdWx0KSB7XG4gICAgdGhpcy5icm9hZGNhc3QoJ29lOnBheW1lbnQ6Y29uZmlybWVkJywge1xuICAgICAgcHJvdmlkZXI6ICdzdHJpcGUnLFxuICAgICAgY29udHJhY3RJZDogdGhpcy5jdXJyZW50Q29udHJhY3RJZCxcbiAgICAgIG9yZGVySWQ6IHRoaXMuY3VycmVudE9yZGVySWQsXG4gICAgICB0cmFuc2FjdGlvbklkOiBwYXltZW50UmVzdWx0LnBheW1lbnRJbnRlbnRJZCxcbiAgICAgIG1ldGFkYXRhOiBwYXltZW50UmVzdWx0LFxuICAgIH0pXG5cbiAgICBjb25zb2xlLmxvZygnW09uZVBhZ2VTdHJpcGVDb250cm9sbGVyXSBQYXltZW50IGNvbmZpcm1lZCBldmVudCBkaXNwYXRjaGVkJylcbiAgfVxuXG4gIC8qKlxuICAgKiBCcm9hZGNhc3Qgb2U6cGF5bWVudDpmYWlsZWQgZXZlbnRcbiAgICovXG4gIGJyb2FkY2FzdFBheW1lbnRGYWlsZWQoZXJyb3IpIHtcbiAgICB0aGlzLmJyb2FkY2FzdCgnb2U6cGF5bWVudDpmYWlsZWQnLCB7XG4gICAgICBwcm92aWRlcjogJ3N0cmlwZScsXG4gICAgICBjb250cmFjdElkOiB0aGlzLmN1cnJlbnRDb250cmFjdElkLFxuICAgICAgb3JkZXJJZDogdGhpcy5jdXJyZW50T3JkZXJJZCxcbiAgICAgIGVycm9yOiBlcnJvci5tZXNzYWdlIHx8ICdQYXltZW50IGZhaWxlZCcsXG4gICAgICBlcnJvckNvZGU6IGVycm9yLmNvZGUgfHwgJ1NUUklQRV9FUlJPUicsXG4gICAgfSlcblxuICAgIGNvbnNvbGUubG9nKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIFBheW1lbnQgZmFpbGVkIGV2ZW50IGRpc3BhdGNoZWQnKVxuICB9XG5cbiAgLyoqXG4gICAqIFVJIEhlbHBlcjogU2hvdyBTdHJpcGUgVUlcbiAgICogU2hvd3MgdGhlIGVudGlyZSBTdHJpcGUgcHJvdmlkZXIgd3JhcHBlciAobm90IGp1c3QgdGhlIHBheW1lbnQgZWxlbWVudClcbiAgICovXG4gIHNob3dTdHJpcGVVSSgpIHtcbiAgICAvLyBTaG93IHRoZSB3cmFwcGVyIChjb250cm9sbGVyIGVsZW1lbnQpXG4gICAgdGhpcy5lbGVtZW50LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG5cbiAgICAvLyBBbHNvIHNob3cgdGhlIHBheW1lbnQgZWxlbWVudCBjb250YWluZXIgaWYgaXQgZXhpc3RzXG4gICAgaWYgKHRoaXMuaGFzRWxlbWVudFRhcmdldCkge1xuICAgICAgdGhpcy5lbGVtZW50VGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnYmxvY2snXG4gICAgfVxuICB9XG5cbiAgLyoqXG4gICAqIFVJIEhlbHBlcjogSGlkZSBTdHJpcGUgVUlcbiAgICogSGlkZXMgdGhlIGVudGlyZSBTdHJpcGUgcHJvdmlkZXIgd3JhcHBlclxuICAgKi9cbiAgaGlkZVN0cmlwZVVJKCkge1xuICAgIC8vIEhpZGUgdGhlIHdyYXBwZXIgKGNvbnRyb2xsZXIgZWxlbWVudClcbiAgICB0aGlzLmVsZW1lbnQuc3R5bGUuZGlzcGxheSA9ICdub25lJ1xuICB9XG5cbiAgLyoqXG4gICAqIFVJIEhlbHBlcjogU2hvdyBsb2FkZXJcbiAgICovXG4gIHNob3dMb2FkZXIoKSB7XG4gICAgaWYgKHRoaXMuaGFzTG9hZGVyVGFyZ2V0KSB7XG4gICAgICB0aGlzLmxvYWRlclRhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ2Jsb2NrJ1xuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBVSSBIZWxwZXI6IEhpZGUgbG9hZGVyXG4gICAqL1xuICBoaWRlTG9hZGVyKCkge1xuICAgIGlmICh0aGlzLmhhc0xvYWRlclRhcmdldCkge1xuICAgICAgdGhpcy5sb2FkZXJUYXJnZXQuc3R5bGUuZGlzcGxheSA9ICdub25lJ1xuICAgIH1cbiAgfVxuXG4gIC8qKlxuICAgKiBVSSBIZWxwZXI6IFNob3cgZXJyb3IgbWVzc2FnZVxuICAgKi9cbiAgc2hvd0Vycm9yKG1lc3NhZ2UpIHtcbiAgICBpZiAodGhpcy5oYXNFcnJvclRhcmdldCkge1xuICAgICAgdGhpcy5lcnJvclRhcmdldC50ZXh0Q29udGVudCA9IG1lc3NhZ2VcbiAgICAgIHRoaXMuZXJyb3JUYXJnZXQuc3R5bGUuZGlzcGxheSA9ICdibG9jaydcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogVUkgSGVscGVyOiBIaWRlIGVycm9yIG1lc3NhZ2VcbiAgICovXG4gIGhpZGVFcnJvcigpIHtcbiAgICBpZiAodGhpcy5oYXNFcnJvclRhcmdldCkge1xuICAgICAgdGhpcy5lcnJvclRhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ25vbmUnXG4gICAgICB0aGlzLmVycm9yVGFyZ2V0LnRleHRDb250ZW50ID0gJydcbiAgICB9XG4gIH1cblxuICAvKipcbiAgICogQVBJOiBDcmVhdGUgcGF5bWVudCBjb250cmFjdFxuICAgKlxuICAgKiBAcGFyYW0ge3N0cmluZ30gcGF5bWVudE1ldGhvZElkIC0gUGF5bWVudCBtZXRob2QgSURcbiAgICogQHJldHVybnMge1Byb21pc2U8T2JqZWN0Pn0gLSBDb250cmFjdCByZXN1bHQgd2l0aCBjbGllbnRTZWNyZXRcbiAgICovXG4gIGFzeW5jIGNyZWF0ZUNvbnRyYWN0KHBheW1lbnRNZXRob2RJZCkge1xuICAgIGNvbnN0IGFwaVVybCA9IHRoaXMuYXBpVXJsVmFsdWUgfHwgJy9pbmRleC5waHA/Y2w9T2VDaGVja291dEFwaSdcblxuICAgIGNvbnNvbGUubG9nKCdbT25lUGFnZVN0cmlwZUNvbnRyb2xsZXJdIENyZWF0aW5nIGNvbnRyYWN0IHZpYSBBUEk6JywgYXBpVXJsKVxuXG4gICAgY29uc3QgcmVzcG9uc2UgPSBhd2FpdCBmZXRjaChgJHthcGlVcmx9JmZuYz1wcm9jZXNzQ2hlY2tvdXRgLCB7XG4gICAgICBtZXRob2Q6ICdQT1NUJyxcbiAgICAgIGhlYWRlcnM6IHtcbiAgICAgICAgJ0NvbnRlbnQtVHlwZSc6ICdhcHBsaWNhdGlvbi9qc29uJyxcbiAgICAgIH0sXG4gICAgICBib2R5OiBKU09OLnN0cmluZ2lmeSh7XG4gICAgICAgIHBheW1lbnRNZXRob2RJZDogcGF5bWVudE1ldGhvZElkLFxuICAgICAgICByZXR1cm5Vcmw6IHRoaXMucmV0dXJuVXJsVmFsdWUsXG4gICAgICAgIGNhbmNlbFVybDogd2luZG93LmxvY2F0aW9uLmhyZWYsXG4gICAgICB9KVxuICAgIH0pXG5cbiAgICBpZiAoIXJlc3BvbnNlLm9rKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IoYEhUVFAgZXJyb3IhIHN0YXR1czogJHtyZXNwb25zZS5zdGF0dXN9YClcbiAgICB9XG5cbiAgICBjb25zdCBkYXRhID0gYXdhaXQgcmVzcG9uc2UuanNvbigpXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gQ29udHJhY3QgQVBJIHJlc3BvbnNlOicsIGRhdGEpXG5cbiAgICByZXR1cm4gZGF0YVxuICB9XG5cbiAgLyoqXG4gICAqIEFQSTogUGxhY2Ugb3JkZXJcbiAgICpcbiAgICogQHBhcmFtIHtzdHJpbmd9IGNvbnRyYWN0SWQgLSBDb250cmFjdCBJRFxuICAgKiBAcmV0dXJucyB7UHJvbWlzZTxPYmplY3Q+fSAtIE9yZGVyIHJlc3VsdFxuICAgKi9cbiAgYXN5bmMgcGxhY2VPcmRlcihjb250cmFjdElkKSB7XG4gICAgY29uc3QgYXBpVXJsID0gdGhpcy5hcGlVcmxWYWx1ZSB8fCAnL2luZGV4LnBocD9jbD1PZUNoZWNrb3V0QXBpJ1xuXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gUGxhY2luZyBvcmRlciB2aWEgQVBJOicsIGFwaVVybClcblxuICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2goYCR7YXBpVXJsfSZmbmM9cGxhY2VPcmRlcmAsIHtcbiAgICAgIG1ldGhvZDogJ1BPU1QnLFxuICAgICAgaGVhZGVyczoge1xuICAgICAgICAnQ29udGVudC1UeXBlJzogJ2FwcGxpY2F0aW9uL2pzb24nLFxuICAgICAgfSxcbiAgICAgIGJvZHk6IEpTT04uc3RyaW5naWZ5KHtcbiAgICAgICAgY29udHJhY3RJZDogY29udHJhY3RJZCxcbiAgICAgICAgY29uZmlybVRlcm1zQW5kQ29uZGl0aW9uczogdHJ1ZSwgIC8vIEFscmVhZHkgY29uZmlybWVkIGJ5IGZvb3RlclxuICAgICAgICByZW1hcms6ICcnXG4gICAgICB9KVxuICAgIH0pXG5cbiAgICBpZiAoIXJlc3BvbnNlLm9rKSB7XG4gICAgICB0aHJvdyBuZXcgRXJyb3IoYEhUVFAgZXJyb3IhIHN0YXR1czogJHtyZXNwb25zZS5zdGF0dXN9YClcbiAgICB9XG5cbiAgICBjb25zdCBkYXRhID0gYXdhaXQgcmVzcG9uc2UuanNvbigpXG4gICAgY29uc29sZS5sb2coJ1tPbmVQYWdlU3RyaXBlQ29udHJvbGxlcl0gT3JkZXIgQVBJIHJlc3BvbnNlOicsIGRhdGEpXG5cbiAgICByZXR1cm4gZGF0YVxuICB9XG59IiwgImltcG9ydCB7IENvbnRyb2xsZXIgfSBmcm9tIFwiQGhvdHdpcmVkL3N0aW11bHVzXCJcbmltcG9ydCB7IHdpdGhFdmVudEJ1cyB9IGZyb20gXCIuLi9taXhpbnMvZXZlbnRfYnVzX21peGluLmpzXCJcblxuLyoqXG4gKiBTdHJpcGUgQ2hlY2tvdXQgRm9vdGVyIENvbnRyb2xsZXJcbiAqXG4gKiBNYW5hZ2VzIFN0cmlwZS1zcGVjaWZpYyBmb290ZXIgYmVoYXZpb3IgaW4gb25lLXBhZ2UgY2hlY2tvdXQ6XG4gKiAtIFRlcm1zIHZhbGlkYXRpb24gYW5kIHN0YXRlIG1hbmFnZW1lbnRcbiAqIC0gUGF5bWVudCBwcm9jZXNzaW5nIGNvb3JkaW5hdGlvbiB3aXRoIHBheW1lbnQgY29udHJvbGxlclxuICogLSBFdmVudEJ1cyBpbnRlZ3JhdGlvbiBmb3Igc3RhdGUgc3luY2hyb25pemF0aW9uXG4gKiAtIExvYWRpbmcgc3RhdGVzIGFuZCBlcnJvciBoYW5kbGluZ1xuICogLSBEeW5hbWljIHRvdGFsIHByaWNlIHVwZGF0ZXNcbiAqXG4gKiBJbnRlZ3JhdGlvbjpcbiAqIC0gVXNlcyBFdmVudEJ1cyBtaXhpbiBmb3IgYXV0b21hdGljIGxpc3RlbmVyIGNsZWFudXBcbiAqIC0gQ29vcmRpbmF0ZXMgd2l0aCBwYXltZW50IGNvbnRyb2xsZXIgZm9yIGFjdHVhbCBwYXltZW50IHByb2Nlc3NpbmdcbiAqIC0gUmVzcG9uZHMgdG8gYmFza2V0IGFuZCBwYXltZW50IHN0YXRlIGNoYW5nZXNcbiAqXG4gKiBFbWl0dGVkIEV2ZW50czpcbiAqIC0gb2U6Zm9vdGVyOnRlcm1zLWFjY2VwdGVkIC0gV2hlbiB0ZXJtcyBjaGVja2JveCBpcyBjaGVja2VkXG4gKiAtIG9lOmZvb3RlcjpzdWJtaXQtY2xpY2tlZCAtIFdoZW4gc3VibWl0IGJ1dHRvbiBpcyBjbGlja2VkXG4gKlxuICogTGlzdGVuZWQgRXZlbnRzOlxuICogLSBvZTpiYXNrZXQ6dXBkYXRlZCAtIEJhc2tldCBjb250ZW50cyBjaGFuZ2VkXG4gKiAtIG9lOnBheW1lbnQ6cHJvY2Vzc2luZyAtIFBheW1lbnQgcHJvY2Vzc2luZyBzdGFydGVkXG4gKiAtIG9lOnBheW1lbnQ6Y29tcGxldGUgLSBQYXltZW50IGNvbXBsZXRlZCBzdWNjZXNzZnVsbHlcbiAqIC0gb2U6cGF5bWVudDplcnJvciAtIFBheW1lbnQgcHJvY2Vzc2luZyBmYWlsZWRcbiAqXG4gKiBAc2VlIGRvY3MvRk9PVEVSX1dJREdFVF9BUkNISVRFQ1RVUkUubWRcbiAqIEBzZWUgZG9jcy9FVkVOVF9CVVNfR1VJREUubWRcbiAqL1xuZXhwb3J0IGRlZmF1bHQgY2xhc3MgZXh0ZW5kcyB3aXRoRXZlbnRCdXMoQ29udHJvbGxlcikge1xuICAgIHN0YXRpYyB0YXJnZXRzID0gW1xuICAgICAgICBcInN1Ym1pdEJ1dHRvblwiLCAgICAgLy8gTWFpbiBzdWJtaXQgYnV0dG9uXG4gICAgICAgIFwibG9hZGVyXCIsICAgICAgICAgICAvLyBMb2FkaW5nIG92ZXJsYXlcbiAgICAgICAgXCJlcnJvclwiLCAgICAgICAgICAgIC8vIEVycm9yIG1lc3NhZ2UgY29udGFpbmVyXG4gICAgICAgIFwiZXJyb3JNZXNzYWdlXCIgICAgICAvLyBFcnJvciBtZXNzYWdlIHRleHQgZWxlbWVudFxuICAgIF1cblxuICAgIHN0YXRpYyB2YWx1ZXMgPSB7XG4gICAgICAgIGJhc2tldElkOiBTdHJpbmcsICAgICAgICAgICAvLyBDdXJyZW50IGJhc2tldCBJRFxuICAgICAgICBwYXltZW50TWV0aG9kOiBTdHJpbmcsICAgICAgLy8gUGF5bWVudCBtZXRob2QgSUQgKGUuZy4sICdveGlkc3RyaXBlJylcbiAgICAgICAgdG90YWxQcmljZTogTnVtYmVyLCAgICAgICAgIC8vIFRvdGFsIG9yZGVyIGFtb3VudFxuICAgICAgICBjdXJyZW5jeTogU3RyaW5nLCAgICAgICAgICAgLy8gQ3VycmVuY3kgY29kZSAoZS5nLiwgJ0VVUicpXG4gICAgICAgIGNzcmZUb2tlbjogU3RyaW5nICAgICAgICAgIC8vIENTUkYgdG9rZW4gZm9yIEFQSSBjYWxsc1xuICAgIH1cblxuICAgIC8qKlxuICAgICAqIENvbnRyb2xsZXIgaW5pdGlhbGl6YXRpb25cbiAgICAgKi9cbiAgICBjb25uZWN0KCkge1xuICAgICAgICBjb25zb2xlLmxvZygnW1N0cmlwZUNoZWNrb3V0Rm9vdGVyXSBDb25uZWN0ZWQnLCB7XG4gICAgICAgICAgICBiYXNrZXRJZDogdGhpcy5iYXNrZXRJZFZhbHVlLFxuICAgICAgICAgICAgcGF5bWVudE1ldGhvZDogdGhpcy5wYXltZW50TWV0aG9kVmFsdWUsXG4gICAgICAgICAgICB0b3RhbFByaWNlOiB0aGlzLnRvdGFsUHJpY2VWYWx1ZSxcbiAgICAgICAgICAgIGN1cnJlbmN5OiB0aGlzLmN1cnJlbmN5VmFsdWVcbiAgICAgICAgfSlcblxuICAgICAgICAvLyBSZWdpc3RlciBFdmVudEJ1cyBsaXN0ZW5lcnMgKGF1dG9tYXRpYyBjbGVhbnVwIG9uIGRpc2Nvbm5lY3QhKVxuICAgICAgICB0aGlzLnNldHVwRXZlbnRMaXN0ZW5lcnMoKVxuXG4gICAgICAgIC8vIEluaXRpYWxpemUgYnV0dG9uIHN0YXRlXG4gICAgICAgIHRoaXMudXBkYXRlQnV0dG9uU3RhdGUoKVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIFNldHVwIEV2ZW50QnVzIGV2ZW50IGxpc3RlbmVyc1xuICAgICAqXG4gICAgICogVXNlcyBFdmVudEJ1cyBtaXhpbidzIGxpc3RlbigpIG1ldGhvZCBmb3IgYXV0b21hdGljIGNsZWFudXBcbiAgICAgKi9cbiAgICBzZXR1cEV2ZW50TGlzdGVuZXJzKCkge1xuICAgICAgICAvLyBMaXN0ZW4gdG8gYmFza2V0IHVwZGF0ZXNcbiAgICAgICAgdGhpcy5saXN0ZW4oJ29lOmJhc2tldDp1cGRhdGVkJywgKGRhdGEpID0+IHtcbiAgICAgICAgICAgIGNvbnNvbGUubG9nKCdbU3RyaXBlQ2hlY2tvdXRGb290ZXJdIEJhc2tldCB1cGRhdGVkJywgZGF0YSlcbiAgICAgICAgICAgIHRoaXMuaGFuZGxlQmFza2V0VXBkYXRlKGRhdGEpXG4gICAgICAgIH0pXG5cbiAgICAgICAgLy8gTGlzdGVuIHRvIHBheW1lbnQgbGlmZWN5Y2xlIGV2ZW50c1xuICAgICAgICB0aGlzLmxpc3Rlbignb2U6cGF5bWVudDpwcm9jZXNzaW5nJywgKGRhdGEpID0+IHtcbiAgICAgICAgICAgIGNvbnNvbGUubG9nKCdbU3RyaXBlQ2hlY2tvdXRGb290ZXJdIFBheW1lbnQgcHJvY2Vzc2luZycsIGRhdGEpXG4gICAgICAgICAgICB0aGlzLnNob3dMb2FkZXIoKVxuICAgICAgICB9KVxuXG4gICAgICAgIHRoaXMubGlzdGVuKCdvZTpwYXltZW50OmNvbXBsZXRlJywgKGRhdGEpID0+IHtcbiAgICAgICAgICAgIGNvbnNvbGUubG9nKCdbU3RyaXBlQ2hlY2tvdXRGb290ZXJdIFBheW1lbnQgY29tcGxldGUnLCBkYXRhKVxuICAgICAgICAgICAgdGhpcy5oaWRlTG9hZGVyKClcbiAgICAgICAgICAgIHRoaXMuc2hvd1N1Y2Nlc3MoKVxuICAgICAgICB9KVxuXG4gICAgICAgIHRoaXMubGlzdGVuKCdvZTpwYXltZW50OmVycm9yJywgKGRhdGEpID0+IHtcbiAgICAgICAgICAgIGNvbnNvbGUubG9nKCdbU3RyaXBlQ2hlY2tvdXRGb290ZXJdIFBheW1lbnQgZXJyb3InLCBkYXRhKVxuICAgICAgICAgICAgdGhpcy5oaWRlTG9hZGVyKClcbiAgICAgICAgICAgIHRoaXMuc2hvd0Vycm9yKGRhdGEubWVzc2FnZSB8fCAnUGF5bWVudCBwcm9jZXNzaW5nIGZhaWxlZCcpXG4gICAgICAgIH0pXG5cbiAgICAgICAgLy8gTGlzdGVuIHRvIHBheW1lbnQgbWV0aG9kIHNlbGVjdGlvbiBjaGFuZ2VzXG4gICAgICAgIHRoaXMubGlzdGVuKCdvZTpwYXltZW50Om1ldGhvZC1zZWxlY3RlZCcsIChkYXRhKSA9PiB7XG4gICAgICAgICAgICBjb25zb2xlLmxvZygnW1N0cmlwZUNoZWNrb3V0Rm9vdGVyXSBQYXltZW50IG1ldGhvZCBzZWxlY3RlZCcsIGRhdGEpXG4gICAgICAgICAgICB0aGlzLmhhbmRsZVBheW1lbnRNZXRob2RDaGFuZ2UoZGF0YSlcbiAgICAgICAgfSlcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBOT1RFOiBUZXJtcyB2YWxpZGF0aW9uIHJlbW92ZWQgLSBoYW5kbGVkIGJ5IGNoZWNrb3V0LWZvb3Rlci1tYW5hZ2VyXG4gICAgICpcbiAgICAgKiBUZXJtcyBjaGVja2JveCBpcyBub3cgaW4gUGFydCAxIChzdGFuZGFyZCBjb25zZW50cykgb2YgZm9vdGVyIGFyY2hpdGVjdHVyZS5cbiAgICAgKiBjaGVja291dC1mb290ZXItbWFuYWdlciBjb250cm9sbGVyIGhhbmRsZXMgYWxsIHRlcm1zIHZhbGlkYXRpb24uXG4gICAgICovXG5cbiAgICAvKipcbiAgICAgKiBIYW5kbGUgc3VibWl0IGJ1dHRvbiBjbGlja1xuICAgICAqXG4gICAgICogSU1QT1JUQU5UOiBGb290ZXIgd2lkZ2V0IGRvZXMgTk9UIHByb2Nlc3MgcGF5bWVudCBkaXJlY3RseSFcbiAgICAgKiBJdCBvbmx5IGJyb2FkY2FzdHMgb2U6Zm9vdGVyOnN1Ym1pdC1jbGlja2VkIGV2ZW50LlxuICAgICAqIFBheW1lbnQgcHJvY2Vzc2luZyBpcyBoYW5kbGVkIGJ5OlxuICAgICAqIDEuIGNoZWNrb3V0LWxpZmVjeWNsZS1jb250cm9sbGVyIFx1MjE5MiBicm9hZGNhc3RzIG9lOnBheW1lbnQ6Y29uZmlybS1yZXF1ZXN0ZWRcbiAgICAgKiAyLiBvbmVwYWdlLXN0cmlwZS1jb250cm9sbGVyIFx1MjE5MiBjb25maXJtcyBwYXltZW50IHdpdGggU3RyaXBlXG4gICAgICogMy4gY2hlY2tvdXQtbGlmZWN5Y2xlLWNvbnRyb2xsZXIgXHUyMTkyIHBsYWNlcyBvcmRlciB2aWEgQVBJXG4gICAgICpcbiAgICAgKiBUaGlzIHNlcGFyYXRpb24gYWxsb3dzIHBheW1lbnQgcHJvdmlkZXJzIHRvIGhhbmRsZSB0aGVpciBvd24gcGF5bWVudCBsb2dpY1xuICAgICAqIHdoaWxlIGZvb3RlciB3aWRnZXQgcmVtYWlucyBnZW5lcmljIGFuZCByZXVzYWJsZS5cbiAgICAgKi9cbiAgICBhc3luYyBwcm9jZXNzUGF5bWVudChldmVudCkge1xuICAgICAgICBldmVudC5wcmV2ZW50RGVmYXVsdCgpXG5cbiAgICAgICAgY29uc29sZS5sb2coJ1tTdHJpcGVDaGVja291dEZvb3Rlcl0gU3VibWl0IGJ1dHRvbiBjbGlja2VkIC0gYnJvYWRjYXN0aW5nIGV2ZW50JylcblxuICAgICAgICAvLyBCcm9hZGNhc3QgZm9vdGVyIHN1Ym1pdCBldmVudFxuICAgICAgICAvLyBjaGVja291dC1saWZlY3ljbGUtY29udHJvbGxlciB3aWxsIG9yY2hlc3RyYXRlIHRoZSBwYXltZW50IGZsb3dcbiAgICAgICAgdGhpcy5icm9hZGNhc3QoJ29lOmZvb3RlcjpzdWJtaXQtY2xpY2tlZCcsIHtcbiAgICAgICAgICAgIHBheW1lbnRNZXRob2Q6IHRoaXMucGF5bWVudE1ldGhvZFZhbHVlLFxuICAgICAgICAgICAgYmFza2V0SWQ6IHRoaXMuYmFza2V0SWRWYWx1ZSxcbiAgICAgICAgICAgIHRvdGFsUHJpY2U6IHRoaXMudG90YWxQcmljZVZhbHVlLFxuICAgICAgICAgICAgY3VycmVuY3k6IHRoaXMuY3VycmVuY3lWYWx1ZSxcbiAgICAgICAgICAgIGNvbmZpcm1lZDogdHJ1ZSAvLyBUZXJtcyBhbHJlYWR5IGNvbmZpcm1lZCBieSBjaGVja291dC1mb290ZXItbWFuYWdlclxuICAgICAgICB9KVxuXG4gICAgICAgIGNvbnNvbGUubG9nKCdbU3RyaXBlQ2hlY2tvdXRGb290ZXJdIEV2ZW50IGJyb2FkY2FzdGVkIC0gd2FpdGluZyBmb3IgY2hlY2tvdXQgbGlmZWN5Y2xlJylcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBIYW5kbGUgYmFza2V0IHVwZGF0ZSBldmVudFxuICAgICAqXG4gICAgICogVXBkYXRlcyB0b3RhbCBwcmljZSBkaXNwbGF5IGFuZCB2YWxpZGF0ZXMgc3RhdGVcbiAgICAgKi9cbiAgICBoYW5kbGVCYXNrZXRVcGRhdGUoZGF0YSkge1xuICAgICAgICAvLyBVcGRhdGUgdG90YWwgcHJpY2UgaWYgcHJvdmlkZWRcbiAgICAgICAgaWYgKGRhdGEudG90YWxQcmljZSAhPT0gdW5kZWZpbmVkKSB7XG4gICAgICAgICAgICB0aGlzLnRvdGFsUHJpY2VWYWx1ZSA9IGRhdGEudG90YWxQcmljZVxuICAgICAgICAgICAgdGhpcy51cGRhdGVUb3RhbERpc3BsYXkoZGF0YS50b3RhbFByaWNlLCBkYXRhLmN1cnJlbmN5IHx8IHRoaXMuY3VycmVuY3lWYWx1ZSlcbiAgICAgICAgfVxuXG4gICAgICAgIC8vIFVwZGF0ZSBiYXNrZXQgSUQgaWYgY2hhbmdlZFxuICAgICAgICBpZiAoZGF0YS5iYXNrZXRJZCkge1xuICAgICAgICAgICAgdGhpcy5iYXNrZXRJZFZhbHVlID0gZGF0YS5iYXNrZXRJZFxuICAgICAgICB9XG5cbiAgICAgICAgLy8gUmUtdmFsaWRhdGUgYnV0dG9uIHN0YXRlXG4gICAgICAgIHRoaXMudXBkYXRlQnV0dG9uU3RhdGUoKVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIEhhbmRsZSBwYXltZW50IG1ldGhvZCBjaGFuZ2UgZXZlbnRcbiAgICAgKlxuICAgICAqIFNob3cvaGlkZSBmb290ZXIgYmFzZWQgb24gcGF5bWVudCBtZXRob2Qgc2VsZWN0aW9uXG4gICAgICovXG4gICAgaGFuZGxlUGF5bWVudE1ldGhvZENoYW5nZShkYXRhKSB7XG4gICAgICAgIGNvbnN0IGlzU3RyaXBlID0gZGF0YS5wYXltZW50TWV0aG9kSWQgPT09IHRoaXMucGF5bWVudE1ldGhvZFZhbHVlXG5cbiAgICAgICAgaWYgKGlzU3RyaXBlKSB7XG4gICAgICAgICAgICAvLyBTaG93IFN0cmlwZSBmb290ZXJcbiAgICAgICAgICAgIHRoaXMuZWxlbWVudC5zdHlsZS5kaXNwbGF5ID0gJ2Jsb2NrJ1xuICAgICAgICB9IGVsc2Uge1xuICAgICAgICAgICAgLy8gSGlkZSBTdHJpcGUgZm9vdGVyIGlmIGRpZmZlcmVudCBwYXltZW50IG1ldGhvZCBzZWxlY3RlZFxuICAgICAgICAgICAgdGhpcy5lbGVtZW50LnN0eWxlLmRpc3BsYXkgPSAnbm9uZSdcbiAgICAgICAgfVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIFVwZGF0ZSB0b3RhbCBwcmljZSBkaXNwbGF5IGluIHN1Ym1pdCBidXR0b25cbiAgICAgKi9cbiAgICB1cGRhdGVUb3RhbERpc3BsYXkodG90YWxQcmljZSwgY3VycmVuY3kpIHtcbiAgICAgICAgY29uc3QgYW1vdW50RWxlbWVudCA9IHRoaXMuc3VibWl0QnV0dG9uVGFyZ2V0LnF1ZXJ5U2VsZWN0b3IoJy5idXR0b24tYW1vdW50JylcbiAgICAgICAgaWYgKGFtb3VudEVsZW1lbnQpIHtcbiAgICAgICAgICAgIGNvbnN0IGZvcm1hdHRlZFByaWNlID0gdGhpcy5mb3JtYXRQcmljZSh0b3RhbFByaWNlKVxuICAgICAgICAgICAgYW1vdW50RWxlbWVudC50ZXh0Q29udGVudCA9IGAke2Zvcm1hdHRlZFByaWNlfSAke2N1cnJlbmN5fWBcbiAgICAgICAgfVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIEZvcm1hdCBwcmljZSB3aXRoIHByb3BlciBkZWNpbWFsIHBsYWNlc1xuICAgICAqL1xuICAgIGZvcm1hdFByaWNlKHByaWNlKSB7XG4gICAgICAgIHJldHVybiBwYXJzZUZsb2F0KHByaWNlKS50b0ZpeGVkKDIpLnJlcGxhY2UoJy4nLCAnLCcpXG4gICAgfVxuXG4gICAgLyoqXG4gICAgICogVXBkYXRlIHN1Ym1pdCBidXR0b24gc3RhdGVcbiAgICAgKlxuICAgICAqIEJ1dHRvbiBpcyBlbmFibGVkIGJ5IGRlZmF1bHQuIGNoZWNrb3V0LWZvb3Rlci1tYW5hZ2VyIGhhbmRsZXMgdGVybXMgdmFsaWRhdGlvbi5cbiAgICAgKi9cbiAgICB1cGRhdGVCdXR0b25TdGF0ZSgpIHtcbiAgICAgICAgLy8gQnV0dG9uIHN0YXRlIGlzIG5vdyBjb250cm9sbGVkIGJ5IGNoZWNrb3V0LWZvb3Rlci1tYW5hZ2VyIChQYXJ0IDEpXG4gICAgICAgIC8vIFRoaXMgd2lkZ2V0IGp1c3QgaGFuZGxlcyBwYXltZW50LXNwZWNpZmljIFVJIHN0YXRlc1xuICAgICAgICAvLyBLZWVwIGJ1dHRvbiBlbmFibGVkIHVubGVzcyBleHBsaWNpdGx5IGRpc2FibGVkIGJ5IGxvYWRpbmcgc3RhdGVcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBTaG93IGxvYWRpbmcgb3ZlcmxheVxuICAgICAqL1xuICAgIHNob3dMb2FkZXIoKSB7XG4gICAgICAgIGNvbnNvbGUubG9nKCdbU3RyaXBlQ2hlY2tvdXRGb290ZXJdIFNob3dpbmcgbG9hZGVyJylcblxuICAgICAgICAvLyBTaG93IHNwaW5uZXIgaW4gYnV0dG9uXG4gICAgICAgIGNvbnN0IGJ1dHRvbkNvbnRlbnQgPSB0aGlzLnN1Ym1pdEJ1dHRvblRhcmdldC5xdWVyeVNlbGVjdG9yKCcuYnV0dG9uLWNvbnRlbnQnKVxuICAgICAgICBjb25zdCBidXR0b25TcGlubmVyID0gdGhpcy5zdWJtaXRCdXR0b25UYXJnZXQucXVlcnlTZWxlY3RvcignLmJ1dHRvbi1zcGlubmVyJylcblxuICAgICAgICBpZiAoYnV0dG9uQ29udGVudCkgYnV0dG9uQ29udGVudC5jbGFzc0xpc3QuYWRkKCdkLW5vbmUnKVxuICAgICAgICBpZiAoYnV0dG9uU3Bpbm5lcikgYnV0dG9uU3Bpbm5lci5jbGFzc0xpc3QucmVtb3ZlKCdkLW5vbmUnKVxuXG4gICAgICAgIC8vIFNob3cgZnVsbC1zY3JlZW4gb3ZlcmxheVxuICAgICAgICB0aGlzLmxvYWRlclRhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ2ZsZXgnXG5cbiAgICAgICAgLy8gRGlzYWJsZSBidXR0b25cbiAgICAgICAgdGhpcy5zdWJtaXRCdXR0b25UYXJnZXQuZGlzYWJsZWQgPSB0cnVlXG5cbiAgICAgICAgLy8gSGlkZSBhbnkgZXJyb3JzXG4gICAgICAgIHRoaXMuaGlkZUVycm9yKClcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBIaWRlIGxvYWRpbmcgb3ZlcmxheVxuICAgICAqL1xuICAgIGhpZGVMb2FkZXIoKSB7XG4gICAgICAgIGNvbnNvbGUubG9nKCdbU3RyaXBlQ2hlY2tvdXRGb290ZXJdIEhpZGluZyBsb2FkZXInKVxuXG4gICAgICAgIC8vIEhpZGUgc3Bpbm5lciBpbiBidXR0b25cbiAgICAgICAgY29uc3QgYnV0dG9uQ29udGVudCA9IHRoaXMuc3VibWl0QnV0dG9uVGFyZ2V0LnF1ZXJ5U2VsZWN0b3IoJy5idXR0b24tY29udGVudCcpXG4gICAgICAgIGNvbnN0IGJ1dHRvblNwaW5uZXIgPSB0aGlzLnN1Ym1pdEJ1dHRvblRhcmdldC5xdWVyeVNlbGVjdG9yKCcuYnV0dG9uLXNwaW5uZXInKVxuXG4gICAgICAgIGlmIChidXR0b25Db250ZW50KSBidXR0b25Db250ZW50LmNsYXNzTGlzdC5yZW1vdmUoJ2Qtbm9uZScpXG4gICAgICAgIGlmIChidXR0b25TcGlubmVyKSBidXR0b25TcGlubmVyLmNsYXNzTGlzdC5hZGQoJ2Qtbm9uZScpXG5cbiAgICAgICAgLy8gSGlkZSBmdWxsLXNjcmVlbiBvdmVybGF5XG4gICAgICAgIHRoaXMubG9hZGVyVGFyZ2V0LnN0eWxlLmRpc3BsYXkgPSAnbm9uZSdcblxuICAgICAgICAvLyBSZS1lbmFibGUgYnV0dG9uIGJhc2VkIG9uIHRlcm1zXG4gICAgICAgIHRoaXMudXBkYXRlQnV0dG9uU3RhdGUoKVxuICAgIH1cblxuICAgIC8qKlxuICAgICAqIFNob3cgZXJyb3IgbWVzc2FnZVxuICAgICAqL1xuICAgIHNob3dFcnJvcihtZXNzYWdlKSB7XG4gICAgICAgIGNvbnNvbGUuZXJyb3IoJ1tTdHJpcGVDaGVja291dEZvb3Rlcl0gRXJyb3I6JywgbWVzc2FnZSlcblxuICAgICAgICBpZiAodGhpcy5oYXNFcnJvck1lc3NhZ2VUYXJnZXQpIHtcbiAgICAgICAgICAgIHRoaXMuZXJyb3JNZXNzYWdlVGFyZ2V0LnRleHRDb250ZW50ID0gbWVzc2FnZVxuICAgICAgICB9XG5cbiAgICAgICAgdGhpcy5lcnJvclRhcmdldC5zdHlsZS5kaXNwbGF5ID0gJ2Jsb2NrJ1xuXG4gICAgICAgIC8vIFNjcm9sbCBlcnJvciBpbnRvIHZpZXdcbiAgICAgICAgdGhpcy5lcnJvclRhcmdldC5zY3JvbGxJbnRvVmlldyh7XG4gICAgICAgICAgICBiZWhhdmlvcjogJ3Ntb290aCcsXG4gICAgICAgICAgICBibG9jazogJ2NlbnRlcidcbiAgICAgICAgfSlcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBIaWRlIGVycm9yIG1lc3NhZ2VcbiAgICAgKi9cbiAgICBoaWRlRXJyb3IoKSB7XG4gICAgICAgIHRoaXMuZXJyb3JUYXJnZXQuc3R5bGUuZGlzcGxheSA9ICdub25lJ1xuICAgIH1cblxuICAgIC8qKlxuICAgICAqIFNob3cgc3VjY2VzcyBzdGF0ZSAoYnJpZWZseSBiZWZvcmUgcmVkaXJlY3QpXG4gICAgICovXG4gICAgc2hvd1N1Y2Nlc3MoKSB7XG4gICAgICAgIGNvbnNvbGUubG9nKCdbU3RyaXBlQ2hlY2tvdXRGb290ZXJdIFBheW1lbnQgc3VjY2Vzc2Z1bCcpXG5cbiAgICAgICAgLy8gVXBkYXRlIGJ1dHRvbiB0byBzaG93IHN1Y2Nlc3NcbiAgICAgICAgY29uc3QgYnV0dG9uVGV4dCA9IHRoaXMuc3VibWl0QnV0dG9uVGFyZ2V0LnF1ZXJ5U2VsZWN0b3IoJy5idXR0b24tdGV4dCcpXG4gICAgICAgIGlmIChidXR0b25UZXh0KSB7XG4gICAgICAgICAgICBidXR0b25UZXh0LmlubmVySFRNTCA9ICc8aSBjbGFzcz1cImZhcyBmYS1jaGVjayBtZS0yXCI+PC9pPlBheW1lbnQgU3VjY2Vzc2Z1bCdcbiAgICAgICAgfVxuXG4gICAgICAgIHRoaXMuc3VibWl0QnV0dG9uVGFyZ2V0LmNsYXNzTGlzdC5yZW1vdmUoJ2J0bi1wcmltYXJ5JylcbiAgICAgICAgdGhpcy5zdWJtaXRCdXR0b25UYXJnZXQuY2xhc3NMaXN0LmFkZCgnYnRuLXN1Y2Nlc3MnKVxuXG4gICAgICAgIC8vIFN1Y2Nlc3MgbWVzc2FnZSB3aWxsIGJlIHNob3duIGJ5IHBheW1lbnQgY29udHJvbGxlclxuICAgICAgICAvLyBUaGlzIGNvbnRyb2xsZXIganVzdCB1cGRhdGVzIHRoZSBidXR0b24gc3RhdGVcbiAgICB9XG5cbiAgICAvKipcbiAgICAgKiBDb250cm9sbGVyIGNsZWFudXBcbiAgICAgKlxuICAgICAqIEV2ZW50QnVzIGxpc3RlbmVycyBhcmUgYXV0b21hdGljYWxseSBjbGVhbmVkIHVwIGJ5IHdpdGhFdmVudEJ1cyBtaXhpblxuICAgICAqL1xuICAgIGRpc2Nvbm5lY3QoKSB7XG4gICAgICAgIGNvbnNvbGUubG9nKCdbU3RyaXBlQ2hlY2tvdXRGb290ZXJdIERpc2Nvbm5lY3RlZCcpXG5cbiAgICAgICAgLy8gTWl4aW4gaGFuZGxlcyBFdmVudEJ1cyBjbGVhbnVwIGF1dG9tYXRpY2FsbHlcbiAgICAgICAgLy8gTm8gbWFudWFsIHJlbW92ZUV2ZW50TGlzdGVuZXIoKSBuZWVkZWQhXG4gICAgfVxufSIsICIvKipcbiAqIFN0cmlwZSBNb2R1bGUgLSBKYXZhU2NyaXB0IEVudHJ5IFBvaW50XG4gKlxuICogSW5pdGlhbGl6ZXMgU3RpbXVsdXMuanMgYW5kIHJlZ2lzdGVycyBhbGwgY29udHJvbGxlcnNcbiAqL1xuXG5pbXBvcnQgeyBBcHBsaWNhdGlvbiB9IGZyb20gXCJAaG90d2lyZWQvc3RpbXVsdXNcIlxuXG4vLyBJbXBvcnQgY29udHJvbGxlcnNcbmltcG9ydCBTdHJpcGVPcmRlckNvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvc3RyaXBlX29yZGVyX2NvbnRyb2xsZXJcIlxuaW1wb3J0IE9yZGVyU3VibWl0Q29udHJvbGxlciBmcm9tIFwiLi9jb250cm9sbGVycy9vcmRlcl9zdWJtaXRfY29udHJvbGxlclwiXG5pbXBvcnQgQWdiVmFsaWRhdGlvbkNvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvYWdiX3ZhbGlkYXRpb25fY29udHJvbGxlclwiXG5pbXBvcnQgT25lUGFnZVN0cmlwZUNvbnRyb2xsZXIgZnJvbSBcIi4vY29udHJvbGxlcnMvb25lcGFnZV9zdHJpcGVfY29udHJvbGxlclwiXG5pbXBvcnQgU3RyaXBlQ2hlY2tvdXRGb290ZXJDb250cm9sbGVyIGZyb20gXCIuL2NvbnRyb2xsZXJzL3N0cmlwZV9jaGVja291dF9mb290ZXJfY29udHJvbGxlclwiXG5cbi8vIFN0YXJ0IFN0aW11bHVzIGFwcGxpY2F0aW9uXG53aW5kb3cuU3RpbXVsdXMgPSBBcHBsaWNhdGlvbi5zdGFydCgpXG5cbi8vIFJlZ2lzdGVyIGNvbnRyb2xsZXJzXG5TdGltdWx1cy5yZWdpc3RlcihcInN0cmlwZS1vcmRlclwiLCBTdHJpcGVPcmRlckNvbnRyb2xsZXIpXG5TdGltdWx1cy5yZWdpc3RlcihcIm9yZGVyLXN1Ym1pdFwiLCBPcmRlclN1Ym1pdENvbnRyb2xsZXIpXG5TdGltdWx1cy5yZWdpc3RlcihcImFnYi12YWxpZGF0aW9uXCIsIEFnYlZhbGlkYXRpb25Db250cm9sbGVyKVxuU3RpbXVsdXMucmVnaXN0ZXIoXCJvbmVwYWdlLXN0cmlwZVwiLCBPbmVQYWdlU3RyaXBlQ29udHJvbGxlcilcblN0aW11bHVzLnJlZ2lzdGVyKFwic3RyaXBlLWNoZWNrb3V0LWZvb3RlclwiLCBTdHJpcGVDaGVja291dEZvb3RlckNvbnRyb2xsZXIpXG5cbi8vIERlYnVnIG1vZGUgaW4gZGV2ZWxvcG1lbnRcbmlmIChwcm9jZXNzLmVudi5OT0RFX0VOViA9PT0gJ2RldmVsb3BtZW50Jykge1xuICBTdGltdWx1cy5kZWJ1ZyA9IHRydWVcbiAgY29uc29sZS5sb2coJ1N0cmlwZSBNb2R1bGU6IFN0aW11bHVzIGluaXRpYWxpemVkIHdpdGggY29udHJvbGxlcnM6JywgU3RpbXVsdXMucm91dGVyLm1vZHVsZXNCeUlkZW50aWZpZXIpXG59XG5cbmNvbnNvbGUubG9nKCdTdHJpcGUgTW9kdWxlOiBKYXZhU2NyaXB0IGxvYWRlZCBhbmQgcmVhZHknKVxuIl0sCiAgIm1hcHBpbmdzIjogIjs7Ozs7Ozs7O0FBSUEsTUFBTSxnQkFBTixNQUFvQjtBQUFBLElBQ2hCLFlBQVksYUFBYSxXQUFXLGNBQWM7QUFDOUMsV0FBSyxjQUFjO0FBQ25CLFdBQUssWUFBWTtBQUNqQixXQUFLLGVBQWU7QUFDcEIsV0FBSyxvQkFBb0Isb0JBQUksSUFBSTtBQUFBLElBQ3JDO0FBQUEsSUFDQSxVQUFVO0FBQ04sV0FBSyxZQUFZLGlCQUFpQixLQUFLLFdBQVcsTUFBTSxLQUFLLFlBQVk7QUFBQSxJQUM3RTtBQUFBLElBQ0EsYUFBYTtBQUNULFdBQUssWUFBWSxvQkFBb0IsS0FBSyxXQUFXLE1BQU0sS0FBSyxZQUFZO0FBQUEsSUFDaEY7QUFBQSxJQUNBLGlCQUFpQixTQUFTO0FBQ3RCLFdBQUssa0JBQWtCLElBQUksT0FBTztBQUFBLElBQ3RDO0FBQUEsSUFDQSxvQkFBb0IsU0FBUztBQUN6QixXQUFLLGtCQUFrQixPQUFPLE9BQU87QUFBQSxJQUN6QztBQUFBLElBQ0EsWUFBWSxPQUFPO0FBQ2YsWUFBTSxn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QUFrQjtBQUFBLElBQzNCO0FBQUE7QUFBQTtBQUFBO0FBQUEsSUFLQSxVQUFVLFNBQVM7QUFDZixjQUFRLE1BQU0saUNBQWlDLE9BQU87QUFFdEQsVUFBSSxLQUFLLHVCQUF1QjtBQUM1QixhQUFLLG1CQUFtQixjQUFjO0FBQUEsTUFDMUM7QUFFQSxXQUFLLFlBQVksTUFBTSxVQUFVO0FBR2pDLFdBQUssWUFBWSxlQUFlO0FBQUEsUUFDNUIsVUFBVTtBQUFBLFFBQ1YsT0FBTztBQUFBLE1BQ1gsQ0FBQztBQUFBLElBQ0w7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLFlBQVk7QUFDUixXQUFLLFlBQVksTUFBTSxVQUFVO0FBQUEsSUFDckM7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQUtBLGNBQWM7QUFDVixjQUFRLElBQUksMkNBQTJDO0FBR3ZELFlBQU0sYUFBYSxLQUFLLG1CQUFtQixjQUFjLGNBQWM7QUFDdkUsVUFBSSxZQUFZO0FBQ1osbUJBQVcsWUFBWTtBQUFBLE1BQzNCO0FBRUEsV0FBSyxtQkFBbUIsVUFBVSxPQUFPLGFBQWE7QUFDdEQsV0FBSyxtQkFBbUIsVUFBVSxJQUFJLGFBQWE7QUFBQSxJQUl2RDtBQUFBO0FBQUE7QUFBQTtBQUFBO0FBQUE7QUFBQSxJQU9BLGFBQWE7QUFDVCxjQUFRLElBQUkscUNBQXFDO0FBQUEsSUFJckQ7QUFBQSxFQUNKO0FBbFJJLGdCQURHLDJDQUNJLFdBQVU7QUFBQSxJQUNiO0FBQUE7QUFBQSxJQUNBO0FBQUE7QUFBQSxJQUNBO0FBQUE7QUFBQSxJQUNBO0FBQUE7QUFBQSxFQUNKO0FBRUEsZ0JBUkcsMkNBUUksVUFBUztBQUFBLElBQ1osVUFBVTtBQUFBO0FBQUEsSUFDVixlQUFlO0FBQUE7QUFBQSxJQUNmLFlBQVk7QUFBQTtBQUFBLElBQ1osVUFBVTtBQUFBO0FBQUEsSUFDVixXQUFXO0FBQUE7QUFBQSxFQUNmOzs7QUM3QkosU0FBTyxXQUFXLFlBQVksTUFBTTtBQUdwQyxXQUFTLFNBQVMsZ0JBQWdCLCtCQUFxQjtBQUN2RCxXQUFTLFNBQVMsZ0JBQWdCLCtCQUFxQjtBQUN2RCxXQUFTLFNBQVMsa0JBQWtCLGlDQUF1QjtBQUMzRCxXQUFTLFNBQVMsa0JBQWtCLGlDQUF1QjtBQUMzRCxXQUFTLFNBQVMsMEJBQTBCLHlDQUE4QjtBQUcxRSxNQUFJLE1BQXdDO0FBQzFDLGFBQVMsUUFBUTtBQUNqQixZQUFRLElBQUkseURBQXlELFNBQVMsT0FBTyxtQkFBbUI7QUFBQSxFQUMxRztBQUVBLFVBQVEsSUFBSSw0Q0FBNEM7IiwKICAibmFtZXMiOiBbImVycm9yIiwgImZldGNoIiwgIm1hdGNoIiwgIm9sZFZhbHVlIiwgImVycm9yIiwgIl9hIiwgImNvbnN0cnVjdG9yIiwgImVsZW1lbnQiLCAiX2EiLCAiZXJyb3IiLCAiX2EiLCAiZXJyb3IiLCAiX2EiLCAiZXJyb3IiLCAiX2EiLCAiZXJyb3IiXQp9Cg==
