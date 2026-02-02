/**
 * URL State Management
 * 
 * Global utility for managing page state in URL query parameters
 */

/**
 * Read all query parameters from URL
 * @returns {URLSearchParams} URL search params object
 */
export function getURLParams() {
    return new URLSearchParams(window.location.search);
}

/**
 * Get a single parameter value from URL
 * @param {string} key - Parameter key
 * @param {string} defaultValue - Default value if not found
 * @returns {string} Parameter value
 */
export function getParam(key, defaultValue = '') {
    return getURLParams().get(key) || defaultValue;
}

/**
 * Get a parameter as integer
 * @param {string} key - Parameter key
 * @param {number} defaultValue - Default value if not found
 * @returns {number} Parameter value as integer
 */
export function getParamInt(key, defaultValue = 0) {
    const value = getURLParams().get(key);
    return value ? parseInt(value, 10) : defaultValue;
}

/**
 * Get all values for a parameter (for multi-select)
 * @param {string} key - Parameter key
 * @returns {string[]} Array of parameter values
 */
export function getParamAll(key) {
    return getURLParams().getAll(key);
}

/**
 * Update URL with new state (replaces history)
 * @param {Object} state - State object to encode in URL
 * @param {Object} options - Options for URL encoding
 * @param {string[]} options.exclude - Keys to exclude from URL
 * @param {Object} options.defaults - Default values (if value matches default, exclude from URL)
 */
export function updateURL(state, options = {}) {
    const { exclude = [], defaults = {} } = options;
    const params = new URLSearchParams();
    
    for (const [key, value] of Object.entries(state)) {
        // Skip excluded keys
        if (exclude.includes(key)) {
            continue;
        }
        
        // Skip if value is null or undefined
        if (value === null || value === undefined) {
            continue;
        }
        
        // Skip if value matches default
        if (defaults[key] !== undefined && value === defaults[key]) {
            continue;
        }
        
        // Handle arrays (for multi-select)
        if (Array.isArray(value)) {
            value.forEach(v => {
                if (v !== null && v !== undefined && v !== '') {
                    params.append(key, String(v));
                }
            });
        }
        // Handle empty strings
        else if (value === '') {
            // Skip empty strings unless they're explicitly different from default
            if (defaults[key] !== '') {
                continue;
            }
        }
        // Handle other values
        else {
            params.set(key, String(value));
        }
    }
    
    const newURL = params.toString() 
        ? `${window.location.pathname}?${params.toString()}`
        : window.location.pathname;
    
    window.history.replaceState({}, '', newURL);
}

/**
 * Read state from URL into an object
 * @param {Object} schema - Schema defining expected parameters
 * @returns {Object} State object
 * 
 * Schema format:
 * {
 *   key: { type: 'string'|'int'|'array', default: any }
 * }
 */
export function readStateFromURL(schema) {
    const state = {};
    const params = getURLParams();
    
    for (const [key, config] of Object.entries(schema)) {
        const { type = 'string', default: defaultValue = null } = config;
        
        switch (type) {
            case 'int':
            case 'integer':
            case 'number':
                state[key] = getParamInt(key, defaultValue);
                break;
                
            case 'array':
                const values = getParamAll(key);
                state[key] = values.length > 0 ? values : (defaultValue || []);
                break;
                
            case 'bool':
            case 'boolean':
                const boolVal = params.get(key);
                if (boolVal === null) {
                    state[key] = defaultValue;
                } else {
                    state[key] = boolVal === '1' || boolVal === 'true';
                }
                break;
                
            case 'string':
            default:
                state[key] = getParam(key, defaultValue);
                break;
        }
    }
    
    return state;
}

/**
 * Apply URL state to form elements
 * @param {Object} state - State object
 * @param {Object} elementMap - Map of state keys to element IDs or elements
 */
export function applyStateToForm(state, elementMap) {
    for (const [key, elementIdOrElement] of Object.entries(elementMap)) {
        const value = state[key];
        if (value === undefined || value === null) {
            continue;
        }
        
        const element = typeof elementIdOrElement === 'string'
            ? document.getElementById(elementIdOrElement)
            : elementIdOrElement;
        
        if (!element) {
            continue;
        }
        
        // Handle select elements (including multi-select)
        if (element.tagName === 'SELECT') {
            if (element.multiple) {
                // Multi-select
                const values = Array.isArray(value) ? value : [value];
                Array.from(element.options).forEach(opt => {
                    opt.selected = values.includes(opt.value);
                });
            } else {
                // Single select
                element.value = value;
            }
        }
        // Handle checkboxes
        else if (element.type === 'checkbox') {
            element.checked = Boolean(value);
        }
        // Handle text inputs and textareas
        else {
            element.value = value;
        }
    }
}

/**
 * Create a state manager for a page
 * @param {Object} schema - State schema
 * @param {Object} options - Options
 * @returns {Object} State manager with get/set/update methods
 */
export function createStateManager(schema, options = {}) {
    let currentState = readStateFromURL(schema);
    
    return {
        /**
         * Get current state
         */
        get() {
            return { ...currentState };
        },
        
        /**
         * Get a specific state value
         */
        getValue(key) {
            return currentState[key];
        },
        
        /**
         * Set state and update URL
         */
        set(newState) {
            currentState = { ...currentState, ...newState };
            updateURL(currentState, options);
        },
        
        /**
         * Update a single state value
         */
        setValue(key, value) {
            currentState[key] = value;
            updateURL(currentState, options);
        },
        
        /**
         * Reload state from URL (useful after browser back/forward)
         */
        reload() {
            currentState = readStateFromURL(schema);
            return { ...currentState };
        },
        
        /**
         * Apply current state to form elements
         */
        applyToForm(elementMap) {
            applyStateToForm(currentState, elementMap);
        }
    };
}
