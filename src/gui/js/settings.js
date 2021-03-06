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

/* File: settings.js

   Defines the global cora.settings variable, which manages user-specific
   settings and controls the "settings" tab.
*/
cora.settings = {
    languageDiv: 'editorLanguageSettings',
    lineSettingsDiv: 'editLineSettings',
    columnVisibilityDiv: 'editorSettingsHiddenColumns',
    textPreviewDiv: 'editorSettingsTextPreview',
    inputAidsDiv: 'editorSettingsInputAids',

    initialize: function() {
        this._activateLanguageDiv();
        this._activateLineSettingsDiv();
        this._activateColumnVisibilityDiv();
        this._activateTextPreviewDiv();
        this._activateInputAidsDiv();
        this._activatePasswordChangeForm();
    },

    /* Function: _activateLanguageDiv

       Activates the radio boxes to change language settings.
     */
    _activateLanguageDiv: function() {
        var elem, div = $(this.languageDiv);
        if (div === null || typeof(div) === "undefined")
            return;

        // Pre-select currently chosen value
        elem = div.getElement('input[value="'+this.get('locale')+'"]');
        if(elem !== null)
            elem.set('checked', 'yes');

        div.addEvent(
            'change:relay(input)',
            function(event, target) {
                var value = div.getElement('input:checked').get('value');
                this.set('locale', value);
                gui.useLocale(value);
		new Request({url: 'request.php'}).get(
		    {'do': 'setUserEditorSetting',
		     'name': 'locale',
		     'value': value}
		);
            }.bind(this)
        );
    },

    /* Function: _activateLineSettingsDiv

       Activates the form request for the line number/context lines setting in
       the settings tab.
     */
    _activateLineSettingsDiv: function() {
        var div = $(this.lineSettingsDiv);
        if (div === null || typeof(div) === "undefined")
            return;

	// validate input
	div.getElement("input[type='submit']").addEvent(
	    'click',
	    function(e) {
		var cl = div.getElement('input[name="contextLines"]').get('value').toInt();
		var pl = div.getElement('input[name="noPageLines"]').get('value').toInt();
		if (isNaN(cl) || isNaN(pl)) {
                    gui.showNotice('error', _("Banner.numbersOnly"));
		    e.stop(); return;
		}
		if (cl >= pl) {
                    gui.showNotice('error', _("Banner.overlappingLines"));
		    e.stop(); return;
		}
		if (pl > 50) {
                    // TODO: change to gui.confirm --- but doesn't work with Form.Request
		    var doit = confirm(_("Banner.manyLinesWarning"));
		    if (!doit) { e.stop(); return; }
		}
	    }
	);

        // request
        new Form.Request(div, '', {
            resetForm: false,
            extraData: {'do': 'saveEditorUserSettings'},
            onSuccess: function(){
		var cl, pl, em, range;
		em = cora.editor;
                if (em !== null)
                    range = em.dataTable.pages.getRange(em.dataTable.pages.activePage);
		cl = div.getElement('input[name="contextLines"]').get('value').toInt();
		pl = div.getElement('input[name="noPageLines"]').get('value').toInt();
                this.set('contextLines', cl).set('noPageLines', pl);
		if (em !== null) {
                    em.dataTable.pages.update().setPageByLine(range.from).render();
		    gui.changeTab('edit');
		}
                gui.showNotice('ok', _("Banner.changesApplied"));
            }.bind(this),
            onFailure: function(){
                gui.showNotice('error', _("Banner.changesNotApplied"));
            }
	});
    },

    /* Function: _activateColumnVisibilityDiv
     */
    _activateColumnVisibilityDiv: function() {
	var div = $(this.columnVisibilityDiv);
	this.get('hiddenColumns').split(",").each(function(value) {
	    div.getElements('input[value="'+value+'"]').set('checked', false);
	});
	div.addEvent(
	    'change:relay(input)',
	    function(event, target) {
		var checked = target.get('checked');
		var value = target.get('value');
                var setting = this.get('hiddenColumns');
		if(cora.editor !== null) {
		    cora.editor.setColumnVisibility(value, checked);
		}
		if (checked) {
                    this.set('hiddenColumns', setting.replace(value+",",""));
		} else {
		    this.set('hiddenColumns', setting + value + ",");
		}
		new Request({url: 'request.php'}).get(
		    {'do': 'setUserEditorSetting',
		     'name': 'columns_hidden',
		     'value': this.get('hiddenColumns')}
		);
	    }.bind(this)
	);
    },

    /* Function: _activateTextPreviewDiv
     */
    _activateTextPreviewDiv: function() {
        var elem, div = $(this.textPreviewDiv);
        elem = div.getElement('input[value="'+this.get('textPreview')+'"]');
        if(elem !== null) {
            elem.set('checked', 'yes');
        }
        div.addEvent(
            'change:relay(input)',
            function(event, target) {
                var value = div.getElement('input:checked').get('value');
                this.set('textPreview', value);
                if (cora.editor !== null) {
                    cora.editor.horizontalTextView
                        .setPreviewType(value)
                        .redraw();
                }
		new Request({url: 'request.php'}).get(
		    {'do': 'setUserEditorSetting',
		     'name': 'text_preview',
		     'value': value}
		);
            }.bind(this)
        );
    },

    /* Function: _activateInputAidsDiv
     */
    _activateInputAidsDiv: function() {
	var div = $(this.inputAidsDiv);
	div.getElement('input[name="show_error"]')
            .set('checked', this.get('showInputErrors'));
	div.addEvent(
	    'change:relay(input)',
	    function(event, target) {
		var checked = target.get('checked');
		var value = target.get('value');
                this.set(value, checked);
		if(value == "show_error") {
                    this.set('showInputErrors', checked);
		    if (cora.editor !== null)
			cora.editor.updateShowInputErrors();
		}
		new Request({url: 'request.php'}).get(
		    {'do': 'setUserEditorSetting',
		     'name': value,
		     'value': checked ? 1 : 0}
		);
	    }.bind(this)
	);
    },

    /* Function: _activatePasswordChangeForm
     */
    _activatePasswordChangeForm: function() {
        /* Change password */
        var pwch = new mBox.Modal({
	    title: 'changePasswordForm_title',
	    content: 'changePasswordFormDiv',
	    attach: 'changePasswordLink'
        });
        new mForm.Submit({
	    form: 'changePasswordForm',
	    ajax: true,
	    validate: true,
	    blinkErrors: true,
	    bounceSubmitButton: false,
	    onSubmit: function() {
	        var pw1 = this.form.getElement('input[name="newpw"]').get('value');
	        var pw2 = this.form.getElement('input[name="newpw2"]').get('value');
	        if (pw1=="" && pw2=="") {
		    // mForm deals with this automatically ...
		    this.form.getElements('.error_text').hide();
	        }
	        else if (pw1==pw2) {
		    this.blockSubmit = false;
		    this.form.getElements('.error_text').hide();
	        } else {
		    this.blockSubmit = true;
		    this.showErrors([
		        this.form.getElement('input[name="newpw"]'),
		        this.form.getElement('input[name="newpw2"]')
		    ]);
		    $('changePasswordErrorNew').show();
	        }
	    },
	    onComplete: function(response) {
	        response = JSON.decode(response);
	        if(response.success) {
		    pwch.close();
		    form.reset($('changePasswordForm'));
		    new mBox.Notice({
		        content: _("SettingsTab.passwordForm.passwordChanged"),
		        type: 'ok',
		        position: {x: 'right'}
		    });
	        } else if (response.errcode!=null && response.errcode=="oldpwmm") {
		    $('changePasswordErrorOld').show();
		    this.showErrors(this.form.getElement('input[name="oldpw"]'));
	        }
	    }
        });
    },

    /* Function: get

       Retrieve value of a specific user setting.
     */
    get: function(name) {
        return userdata[name];
    },

    /* Function: set

       Set value of a specific user setting.
     */
    set: function(name, value) {
        userdata[name] = value;
        return this;
    },

    /* Function: isColumnVisible

       Checks whether a given column is set to be visible in the settings tab.
     */
    isColumnVisible: function(name) {
        var elem = $(this.columnVisibilityDiv).getElement('input[value="'+name+'"]');
        if (elem != null)
            return elem.get('checked');
        return true;
    },

    /* Function: setColumnActive

       Sets a given column to active or inactive, determining whether it is
       shown in the settings tab or not.
     */
    setColumnActive: function(name, active) {
        var div = $(this.columnVisibilityDiv);
        if(active) {
            div.getElements('label#eshc-'+name).show();
        } else {
            div.getElements('label#eshc-'+name).hide();
        }
    }
};

cora.isAdmin = function() {
    var admin = cora.settings.get('admin');
    return (Boolean(admin) && admin !== '0');
};
