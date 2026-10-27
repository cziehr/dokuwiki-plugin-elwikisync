jQuery(function(){
    /**
     * Attach diff popup
     */
    jQuery('a[class=elwikisync_popup]').click(function(e){ 
            window.open(jQuery(this).attr("href"),'diff',"width=700,height=500,left=100,top=100,menubar=no,location=no,resizable=yes,scrollbars=yes,status=no,toolbar=no");
            e.preventDefault();
            return false;
        });

    /**
     * Attach the select all actions
     */
    jQuery('#elwikisync__push').click(function(){ elwikisync_select('push'); }).addClass('elwikisync__action');
    jQuery('#elwikisync__skip').click(function(){ elwikisync_select('skip'); }).addClass('elwikisync__action');
    jQuery('#elwikisync__pull').click(function(){ elwikisync_select('pull'); }).addClass('elwikisync__action');
});

/**
 * Check all radio buttons of the given type
 */
function elwikisync_select(type){
     jQuery('input[class=elwikisync'+type+']').prop('checked',true);
}
