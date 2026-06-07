(function(){

    window.mychat = window.mychat || {};
    // _________________________________For Chat Chat DIv and its IFrame___________________________________
    var widget_chat_iframe_div = document.createElement('div');
    widget_chat_iframe_div.id = "chat-outer-widget-container-div";
    var styles = {
        "border": "0px",
        "background": "#FFFFFF",
        "z-index": "2147483639",
        "position": "fixed",
        "bottom": "0px",
        'width': '300px',
        'height': '460px',
        "overflow": "hidden",
        "opacity": "1",
        "max-width": "100%",
        "max-height": "100%",
        "bottom": "20px",
        "right": "20px",
        "box-shadow": "0 0 5px 0 rgba(0, 0, 0, .2)",
        "display": "flex",
        "flex-flow": "column",
        "border-radius":" 10px 10px 0 0",
        "display":"none"
        
    };
    Object.assign(widget_chat_iframe_div.style, styles);

    //For CHat iframe
    var widget_chat_iframe = document.createElement('iframe');
    widget_chat_iframe.src = 'http://localhost/WebBot/tvaibwc-widget.html';
    widget_chat_iframe.id = "chat-inner-widget-iframe";
    widget_chat_iframe.allow = "autoplay; camera; microphone";
    widget_chat_iframe.setAttribute('frameborder', '0');
    widget_chat_iframe.setAttribute('allowtransparency', 'true');
    widget_chat_iframe.allow = "autoplay; camera; microphone";
    var styles = {
        "pointer-events": "all",
        "background": "#FFFFFF",
        "border": "0px",
        "float": "none",
        "inset": "0px",
        'width': '300px',
        'margin-right': '32px',
        'height': '460px',
        "min-height": "0px",
        
    };
    Object.assign(widget_chat_iframe.style, styles);
    widget_chat_iframe_div.appendChild(widget_chat_iframe);
    document.querySelector('body').append(widget_chat_iframe_div);

    // _________________________________For Chat Avatar DIv and its IFrame___________________________________
    //For CHat Avatar div
    var widget_avatar_iframe_div = document.createElement('div');
    widget_avatar_iframe_div.id = "avatar-outer-widget-container-div";
    var styles = {
        "border": "0px",
        "background": "#FFFFFF",
        "z-index": "2147483639",
        "position": "fixed",
        "bottom": "0px",
        'width': '70px',
        'height': '70px',
        "overflow": "hidden",
        "opacity": "1",
        "bottom": "20px",
        "right": "20px",
        "box-shadow": "0 0 5px 0 rgba(0, 0, 0, .2)",
        "display": "flex",
        "flex-flow": "column",
        "border-radius":"50%",
        "display":"none"
        
    };
    Object.assign(widget_avatar_iframe_div.style, styles);

    //For Avatar IFrame
    var widget_avatar_iframe = document.createElement('iframe');
    widget_avatar_iframe.src = 'http://localhost/WebBot/tvaibwc-avatar.html';
    widget_avatar_iframe.id = "chat-avatar-widget-iframe";
    widget_avatar_iframe.allow = "autoplay; camera; microphone";
    widget_avatar_iframe.setAttribute('frameborder', '0');
    widget_avatar_iframe.setAttribute('allowtransparency', 'true');
    widget_avatar_iframe.allow = "autoplay; camera; microphone";
    var styles = {
        
    };
    
    Object.assign(widget_avatar_iframe.style, styles);
    widget_avatar_iframe_div.appendChild(widget_avatar_iframe);

    document.querySelector('body').append(widget_avatar_iframe_div);

})();

setTimeout(() => {
    //Avatar Icon
    $("#avatar-outer-widget-container-div").css("display", "block");
    $('#chat-avatar-widget-iframe').contents().find('#webchat-avatar').attr("src", chat_confige.icon);
}, 1000);

// Listen for messages from the iframe
window.addEventListener('message', function(event) {
    if (event.data === 'chat_clicked') {
        var chat_widget = document.getElementById('chat-outer-widget-container-div');
        var avatar_widget = document.getElementById('avatar-outer-widget-container-div');

        chat_widget.style.display = chat_widget.style.display = 'none';
        avatar_widget.style.display = avatar_widget.style.display = 'block';
    }
    if (event.data === 'avatar_clicked') {
        var chat_widget = document.getElementById('chat-outer-widget-container-div');
        var avatar_widget = document.getElementById('avatar-outer-widget-container-div');
        
        chat_widget.style.display = chat_widget.style.display = 'block';
        avatar_widget.style.display = avatar_widget.style.display = 'none';
        // $('#chat-outer-widget-container-div').LoadingOverlay("show");
    }
    setTimeout(() => {
        // var divInsideIframe = $('#chat-inner-widget-iframe').contents().find('#webchat-top');
        // var divInsideIframe = iframeWindow.document.getElementById('webchat-top');
        // iframeWindow.document.getElementsByClassName('webchat-btn')[0].style.backgroundColor = chat_confige.chat_head_buttons_bg_color;
        // iframeWindow.document.getElementsByClassName('webchat-btn')[0].style.color = chat_confige.chat_head_buttons_font_color;
    
        //Head section 
    
        $('#chat-inner-widget-iframe').contents().find('#webchat-top').css('backgroundColor', chat_confige.chat_head_bg_color);
        $('#chat-inner-widget-iframe').contents().find('#webchat-title').css('color' ,chat_confige.title_font_color);
        $('#chat-inner-widget-iframe').contents().find('#webchat-title').text(chat_confige.title);
        $('#chat-inner-widget-iframe').contents().find('#webchat_logo').attr("src", chat_confige.icon);
    
        $('#chat-inner-widget-iframe').contents().find('.webchat-btn i').css('backgroundColor', chat_confige.chat_head_buttons_bg_color);
        $('#chat-inner-widget-iframe').contents().find('.webchat-btn i').css('color', chat_confige.chat_head_buttons_font_color);
    
        //Chat Section 
        $('#chat-inner-widget-iframe').contents().find('#webchat-conversation').css('backgroundColor', chat_confige.chat_bg_color);
    
        $('#chat-inner-widget-iframe').contents().find('.chat-conversation .left .conversation-list .ctext-wrap .ctext-wrap-content').css('backgroundColor', chat_confige.left_chat_bg_color);
        $('#chat-inner-widget-iframe').contents().find('.chat-conversation .left .conversation-list .ctext-wrap .ctext-wrap-content').css('color', chat_confige.left_chat_font_color);
    
        $('#chat-inner-widget-iframe').contents().find('.chat-conversation .right .conversation-list .ctext-wrap .ctext-wrap-content').css('backgroundColor', chat_confige.right_chat_bg_color);
        $('#chat-inner-widget-iframe').contents().find('.chat-conversation .right .conversation-list .ctext-wrap .ctext-wrap-content').css('color', chat_confige.right_chat_font_color);
    
        //Footer Side
        $('#chat-inner-widget-iframe').contents().find('.chat-send').css('backgroundColor', chat_confige.chat_footer_buttons_bg_color);
        $('#chat-inner-widget-iframe').contents().find('.chat-send i').css('color', chat_confige.chat_footer_buttons_font_color);
        
        $('#chat-inner-widget-iframe').contents().find('#btn_attachments i').css('color', chat_confige.chat_footer_buttons_bg_color);
        $('#chat-inner-widget-iframe').contents().find('#btn_quick_messages i').css('color', chat_confige.chat_footer_buttons_bg_color);

        $('#chat-inner-widget-iframe').contents().find('.chat-input-section').css('backgroundColor', chat_confige.chat_footer_bg_color);
    
        //Avatar Icone
        $('#chat-avatar-widget-iframe').contents().find('#webchat-avatar').attr("src", chat_confige.icon);
        // $('#chat-outer-widget-container-div').LoadingOverlay("hide",true);
    }, 1000);
    

});