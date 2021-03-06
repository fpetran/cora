/*
 * Copyright (C) 2015 Marcel Bollmann <bollmann@linguistics.rub.de>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 * the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
 * FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
 * COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
 * IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
 * CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */

cora.flags = {
    'flag_general_error': {
        elem: 'div.editTableError',
        class: 'editTableErrorChecked',
        displayname: "EditorTab.Forms.searchForm.errorMarkup", // "Fehler-Markierung"
        eventString: 'click:relay(div.editTableError)'
    },
    'flag_lemma_verified': {
        elem: 'div.editTableLemma',
        class: 'editTableLemmaChecked',
        displayname: "EditorTab.Forms.searchForm.lemmaMarkup", // "Lemma-Markierung"
        eventString: 'click:relay(div.editTableLemma)'
    },
    'flag_boundary': {
        elem: 'div.editTableBoundary',
        class: 'editTableBoundaryChecked',
        displayname: "EditorTab.Forms.searchForm.sentBoundaryMarkup", // "Satzgrenzen-Markierung"
        eventString: 'click:relay(div.editTableBoundary)'
    }
};

/* Class: FlagHandler

   An addition to the tagset-specific classes, this class handles all annotation
   changes to status flags ("error" annotation, verified lemma, etc.).

   Behaves similarly to Tagset, but is separate for now since flags are global
   to all texts without needing to be linked to them.
 */
var FlagHandler = new Class({
    /* Function: initialize
     */
    initialize: function() {
        this.flags = cora.flags;
    },

    /* Function: getEventData

       Return event types and event handlers for all flags.

       Returns:
         An array of objects with the following properties:
           type - An event type that should be registered by a DataTable
                  containing flags.
           handler - A function that handles this event for the respective flag.
     */
    getEventData: function() {
        var data = [];
        Object.each(this.flags, function(options, flag) {
            var event = {type: options.eventString};
            event.handler = function(event, target) {
                var value = (target.hasClass(options.class) ? 0 : 1);
                return {cls: flag, value: value};
            };
            data.push(event);
        });
        return data;
    },

    /* Function: getValues

       Gets the flag annotation values from a token data object.

       Parameters:
         data - An object possibly containing annotations ({anno_pos: ...} etc.)
     */
    getValues: function(data) {
        return Object.map(this.flags, function(options, flag) {
            return (data[flag] == 1) ? 1 : 0;
        });
    },

    /* Function: fill

       Fill the appropriate elements in a <tr> with annotation from a token data
       object.

       Parameters:
         tr - Table row to fill
         data - An object possibly containing annotations ({anno_pos: ...} etc.)
     */
    fill: function(tr, data) {
        var ref = this;
        Object.each(this.flags, function(options, flag) {
            var elem = tr.getElement(options.elem);
            if (elem !== null) {
                ref._setFlag(elem, options.class, data[flag]);
            }
        });
    },

    /* Function: update

       Triggered method to call whenever an annotation changes.  Allows the
       FlagHandler to react to a change and/or store the result.

       Parameters:
         tr - Table row where the change happened
         data - An object possibly containing annotations ({anno_pos: ...}),
                in the state *before* the update
         changes - An object containing any changed values *after* the update
         cls - Tagset class of the annotation
         value - New value of the annotation
     */
    update: function(tr, data, changes, cls, value) {
        var ref = this;
        Object.each(this.flags, function(options, flag) {
            var elem, flagvalue = null;
            if (cls === flag) {
                flagvalue = value;
                changes[flag] = flagvalue;
            } else if (flag in changes) {
                flagvalue = changes[flag];
            }
            if (flagvalue !== null) {
                elem = tr.getElement(options.elem);
                if (elem !== null)
                    ref._setFlag(elem, options.class, flagvalue);
            }
        });
    },

    _setFlag: function(elem, css_class, value) {
        if (value == 1)
            elem.addClass(css_class);
        else
            elem.removeClass(css_class);
    }
});
