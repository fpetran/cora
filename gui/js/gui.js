/** @file
 * GUI-related functions
 *
 * @author Marcel Bollmann
 * @date January 2012
 */

var gui = {
    activeSpinner: null,
    keepaliveRequest: null,

    initialize: function() {
	this.addToggleEvents();
	this.activateKeepalive();
    },

    /* Function: addToggleEvents

       Enable clappable div containers.

       Adds onClick events to the .clapp element in each .clappable
       container that toggle the visibility of its contents.  Also,
       automatically hides all contents of .starthidden containers.
    */
    addToggleEvents: function() {
	$$('.clappable').each(function (clappable) {
            var clapper, content;
	    
            // add toggle event
            clapper = clappable.getElement('.clapp');
            content = clappable.getElement('div');
            if (clapper !== null) {
		clapper.addEvent('click', function () {
                    content.toggle();
		});
            }
            // hide content by default, if necessary
            if (clappable.hasClass('starthidden')) {
		content.hide();
            }
	});
    },

    /* Function: activateKeepalive

       Sets up a periodical server request with the sole purpose of
       keeping the connection alive, i.e., telling the server that the
       user is still active (and has not, e.g., closed the browser
       window without logging out).
    */
    activateKeepalive: function() {
	if(userdata.name != undefined) {
	    this.keepaliveRequest = new Request({
		url: 'request.php?do=keepalive',
		method: 'get',
		initialDelay: 60000,
		delay: 300000,
		limit: 300000
	    });
	    this.keepaliveRequest.startTimer();
	}
    },

    /* Function: changeTab

       Selects a new tab.

       Shows the content div corresponding to the selected menu item,
       while hiding all others and highlighting the correct menu
       button.

       Parameters:
        tabName - Internal name of the tab to be selected
    */
    changeTab: function(tabName) {
	var contentBox, tabButton, activeTab, i;

	// hide all tabs
	contentBox = $$(".content");
	for (i = 0; i < contentBox.length; i++) {
            contentBox[i].setStyle("display", "none");
	}
	
	// select correct tab button
	tabButton = $$(".tabButton");
	for (i = 0; i < tabButton.length; i++) {
            if (tabButton[i].id === tabName + "TabButton") {
		tabButton[i].set("active", "true");
            } else {
		tabButton[i].set("active", "false");
            }
	}

	// show active tab
	activeTab = $(tabName + "Div");
	if (activeTab === null) {
            alert(tabName + " tab not implemented!");
	}
	activeTab.setStyle("display", "block");
    },

    /* Function: showNotice

       Displays a floating notice, e.g., to indicate success.

       Parameters:
        ntype - Type of the notice ('ok' or 'error')
        message - String to appear in the notice
    */
    showNotice: function(ntype, message) {
	new mBox.Notice({
	    type: ntype,
	    position: {x: 'right'},
	    content: message
	});
    },

    /* Function: showSpinner

       Displays a "loading" spinner.

       Parameters: 
        options - An object which may contain the following options:
	           * message - Message to display (default: none)
    */
    showSpinner: function(options) {
	var options = options || {};
	var spinmsg = options.message || null;

	$('overlay').show();
	$('spin-overlay').show();
	this.activeSpinner = new Spinner($('spin-overlay'),
					 {message: spinmsg});
	this.activeSpinner.show();
    },

    /* Function: hideSpinner

       Hides the currently displayed spinner.
    */
    hideSpinner: function() {
	if(this.activeSpinner !== undefined && this.activeSpinner !== null) {
	    this.activeSpinner.hide();
	    $('overlay').hide();
	    $('spin-overlay').hide();
	}
    },

}


/** Perform initialization. Adds JavaScript events to interactive
 * navigation elements, e.g.\ clappable div containers, and selects
 * the default tab.
 */
function onLoad() {
    gui.initialize();

    // default item defined in content.php, variable set in gui.php
    gui.changeTab(default_tab);
}

function onBeforeUnload() {
    if (typeof edit!="undefined" && edit.editorModel!==null) {
	var chl = edit.editorModel.changedLines.length;
	if (chl>0) {
	    var zeile = (chl>1) ? "Zeilen" : "Zeile";
	    return ("Im geöffneten Dokument gibt es noch ungespeicherte Änderungen in "+chl+" "+zeile+", die verloren gehen, wenn Sie fortfahren.");
	}
    }
}
