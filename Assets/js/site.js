function CollapseSidebar() {
    if ($(window).width() > 991) {
        $('body').toggleClass('CollapseSidebar')
    } else {
        $('body').toggleClass('showSidebar')
    }
}
