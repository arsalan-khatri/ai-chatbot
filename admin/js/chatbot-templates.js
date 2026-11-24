/**
 * Appearance screen: templates, tabs and live preview.
 */
jQuery(function ($) {
    'use strict';

    var optName = 'wp_ai_chatbot_custom_style';
    var $form = $('#wp-ai-customizer-form');
    var preview = document.querySelector('.akdk-preview-stage .wp-ai-chatbot-window');
    if (!$form.length) return;

    var templates = {
        akdk_light: { header_bg: '#fd9400', header_text: '#0a0a0a', chat_bg: '#ffffff', general_border_color: '#ececec', bot_bubble_bg: '#f5f5f5', bot_bubble_text: '#0a0a0a', user_bubble_bg: '#0a0a0a', user_bubble_text: '#ffffff', send_btn_bg: '#fd9400', send_btn_hover_bg: '#e58600', send_btn_text: '#0a0a0a', send_btn_icon_hover: '#0a0a0a', del_btn_bg: '#d63638', del_btn_hover_bg: '#b32d2e', close_btn_color: '#0a0a0a', close_btn_hover_bg: 'rgba(0,0,0,0.08)', chat_radius: '18', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#e5e5e5', input_border_focus_color: '#fd9400', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        akdk_dark: { header_bg: '#0a0a0a', header_text: '#ffffff', chat_bg: '#0a0a0a', general_border_color: '#232323', bot_bubble_bg: '#171717', bot_bubble_text: '#f5f5f5', user_bubble_bg: '#fd9400', user_bubble_text: '#0a0a0a', send_btn_bg: '#fd9400', send_btn_hover_bg: '#ffa733', send_btn_text: '#0a0a0a', send_btn_icon_hover: '#0a0a0a', del_btn_bg: '#ef4444', del_btn_hover_bg: '#dc2626', close_btn_color: '#ffffff', close_btn_hover_bg: 'rgba(255,255,255,0.12)', chat_radius: '18', chat_width: '380', input_bg_color: '#141414', input_border_sides: 'all', input_border_width: '1', input_border_color: '#2a2a2a', input_border_focus_color: '#fd9400', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        chatgpt_light: { header_bg: '#ffffff', header_text: '#000000', chat_bg: '#ffffff', bot_bubble_bg: '#f7f7f8', bot_bubble_text: '#374151', user_bubble_bg: '#ffffff', user_bubble_text: '#374151', send_btn_bg: '#10a37f', send_btn_hover_bg: '#0e906f', send_btn_text: '#ffffff', del_btn_bg: '#ef4444', del_btn_hover_bg: '#dc2626', chat_radius: '12', chat_width: '400', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#e5e7eb', input_border_focus_color: '#10a37f', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        chatgpt_dark: { header_bg: '#212121', header_text: '#ffffff', chat_bg: '#212121', bot_bubble_bg: '#2f2f2f', bot_bubble_text: '#ececf1', user_bubble_bg: '#212121', user_bubble_text: '#ececf1', send_btn_bg: '#10a37f', send_btn_hover_bg: '#0e906f', send_btn_text: '#ffffff', del_btn_bg: '#ef4444', del_btn_hover_bg: '#dc2626', chat_radius: '12', chat_width: '400', input_bg_color: '#2f2f2f', input_border_sides: 'all', input_border_width: '1', input_border_color: '#4d4d4f', input_border_focus_color: '#10a37f', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        claude_light: { header_bg: '#f3f0e8', header_text: '#2d2d2d', chat_bg: '#f3f0e8', bot_bubble_bg: '#ffffff', bot_bubble_text: '#2d2d2d', user_bubble_bg: '#d97757', user_bubble_text: '#ffffff', send_btn_bg: '#d97757', send_btn_hover_bg: '#b86246', send_btn_text: '#ffffff', del_btn_bg: '#2d2d2d', del_btn_hover_bg: '#000000', chat_radius: '16', chat_width: '420', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#d1cbbd', input_border_focus_color: '#d97757', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        whatsapp: { header_bg: '#075e54', header_text: '#ffffff', chat_bg: '#e5ddd5', bot_bubble_bg: '#ffffff', bot_bubble_text: '#303030', user_bubble_bg: '#dcf8c6', user_bubble_text: '#303030', send_btn_bg: '#128c7e', send_btn_hover_bg: '#0c6e62', send_btn_text: '#ffffff', del_btn_bg: '#d63638', del_btn_hover_bg: '#b32d2e', chat_radius: '16', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'none', input_border_width: '0', input_border_color: '#ffffff', input_border_focus_color: '#ffffff', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        messenger: { header_bg: '#ffffff', header_text: '#000000', chat_bg: '#ffffff', bot_bubble_bg: '#f0f2f5', bot_bubble_text: '#050505', user_bubble_bg: '#0084ff', user_bubble_text: '#ffffff', send_btn_bg: '#0084ff', send_btn_hover_bg: '#006bce', send_btn_text: '#ffffff', del_btn_bg: '#fa383e', del_btn_hover_bg: '#d1292e', chat_radius: '20', chat_width: '380', input_bg_color: '#f0f2f5', input_border_sides: 'all', input_border_width: '1', input_border_color: '#e4e6eb', input_border_focus_color: '#0084ff', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        instagram: { header_bg: '#fafafa', header_text: '#262626', chat_bg: '#fafafa', bot_bubble_bg: '#efefef', bot_bubble_text: '#262626', user_bubble_bg: '#3797f0', user_bubble_text: '#ffffff', send_btn_bg: '#3797f0', send_btn_hover_bg: '#2b7bc4', send_btn_text: '#ffffff', del_btn_bg: '#ed4956', del_btn_hover_bg: '#c93844', chat_radius: '18', chat_width: '380', input_bg_color: '#efefef', input_border_sides: 'all', input_border_width: '1', input_border_color: '#dbdbdb', input_border_focus_color: '#3797f0', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        twitter: { header_bg: '#ffffff', header_text: '#0f1419', chat_bg: '#ffffff', bot_bubble_bg: '#eff3f4', bot_bubble_text: '#0f1419', user_bubble_bg: '#1da1f2', user_bubble_text: '#ffffff', send_btn_bg: '#1da1f2', send_btn_hover_bg: '#1a91da', send_btn_text: '#ffffff', del_btn_bg: '#f4212e', del_btn_hover_bg: '#df1b27', chat_radius: '16', chat_width: '400', input_bg_color: '#eff3f4', input_border_sides: 'none', input_border_width: '0', input_border_color: '#eff3f4', input_border_focus_color: '#1da1f2', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        telegram: { header_bg: '#ffffff', header_text: '#000000', chat_bg: '#e6ebf0', bot_bubble_bg: '#ffffff', bot_bubble_text: '#000000', user_bubble_bg: '#eef8fe', user_bubble_text: '#000000', send_btn_bg: '#3390ec', send_btn_hover_bg: '#287bc9', send_btn_text: '#ffffff', del_btn_bg: '#e53935', del_btn_hover_bg: '#c62828', chat_radius: '12', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'none', input_border_width: '0', input_border_color: '#ffffff', input_border_focus_color: '#ffffff', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        discord: { header_bg: '#36393f', header_text: '#ffffff', chat_bg: '#36393f', bot_bubble_bg: '#2f3136', bot_bubble_text: '#dcddde', user_bubble_bg: '#5865F2', user_bubble_text: '#ffffff', send_btn_bg: '#5865F2', send_btn_hover_bg: '#4752C4', send_btn_text: '#ffffff', del_btn_bg: '#ed4245', del_btn_hover_bg: '#c93b3e', chat_radius: '8', chat_width: '400', input_bg_color: '#40444b', input_border_sides: 'all', input_border_width: '1', input_border_color: '#202225', input_border_focus_color: '#5865F2', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        discord_dark: { header_bg: '#202225', header_text: '#ffffff', chat_bg: '#202225', bot_bubble_bg: '#2f3136', bot_bubble_text: '#dcddde', user_bubble_bg: '#292b2f', user_bubble_text: '#ffffff', send_btn_bg: '#5865F2', send_btn_hover_bg: '#4752C4', send_btn_text: '#ffffff', del_btn_bg: '#ed4245', del_btn_hover_bg: '#c93b3e', chat_radius: '8', chat_width: '400', input_bg_color: '#40444b', input_border_sides: 'all', input_border_width: '1', input_border_color: '#4f545c', input_border_focus_color: '#5865F2', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        linkedin: { header_bg: '#ffffff', header_text: '#000000', chat_bg: '#f3f2ef', bot_bubble_bg: '#ffffff', bot_bubble_text: '#000000', user_bubble_bg: '#0a66c2', user_bubble_text: '#ffffff', send_btn_bg: '#0a66c2', send_btn_hover_bg: '#004182', send_btn_text: '#ffffff', del_btn_bg: '#cc1016', del_btn_hover_bg: '#a30c11', chat_radius: '8', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#e0dfdc', input_border_focus_color: '#0a66c2', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        slack: { header_bg: '#3F0E40', header_text: '#ffffff', chat_bg: '#ffffff', bot_bubble_bg: '#f8f8f8', bot_bubble_text: '#1d1c1d', user_bubble_bg: '#1164A3', user_bubble_text: '#ffffff', send_btn_bg: '#007a5a', send_btn_hover_bg: '#006148', send_btn_text: '#ffffff', del_btn_bg: '#e01e5a', del_btn_hover_bg: '#b11847', chat_radius: '10', chat_width: '400', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#dddddd', input_border_focus_color: '#1164a3', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        imessage: { header_bg: '#f5f5f7', header_text: '#000000', chat_bg: '#ffffff', bot_bubble_bg: '#e5e5ea', bot_bubble_text: '#000000', user_bubble_bg: '#34c759', user_bubble_text: '#ffffff', send_btn_bg: '#34c759', send_btn_hover_bg: '#2da84a', send_btn_text: '#ffffff', del_btn_bg: '#ff3b30', del_btn_hover_bg: '#d63228', chat_radius: '24', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#c7c7cc', input_border_focus_color: '#34c759', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        android_sms: { header_bg: '#ffffff', header_text: '#202124', chat_bg: '#ffffff', bot_bubble_bg: '#f1f3f4', bot_bubble_text: '#202124', user_bubble_bg: '#1a73e8', user_bubble_text: '#ffffff', send_btn_bg: '#1a73e8', send_btn_hover_bg: '#155dbb', send_btn_text: '#ffffff', del_btn_bg: '#d93025', del_btn_hover_bg: '#b2271e', chat_radius: '20', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#dadce0', input_border_focus_color: '#1a73e8', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        teams: { header_bg: '#ebebe1', header_text: '#242424', chat_bg: '#f5f5f5', bot_bubble_bg: '#ffffff', bot_bubble_text: '#242424', user_bubble_bg: '#e5e5f1', user_bubble_text: '#242424', send_btn_bg: '#6264A7', send_btn_hover_bg: '#4b4c80', send_btn_text: '#ffffff', del_btn_bg: '#c4314b', del_btn_hover_bg: '#9e273d', chat_radius: '6', chat_width: '400', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#e0e0e0', input_border_focus_color: '#6264A7', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        material_ui: { header_bg: '#6200EE', header_text: '#ffffff', chat_bg: '#fafafa', bot_bubble_bg: '#ffffff', bot_bubble_text: '#000000', user_bubble_bg: '#6200EE', user_bubble_text: '#ffffff', send_btn_bg: '#03DAC6', send_btn_hover_bg: '#01a595', send_btn_text: '#000000', del_btn_bg: '#B00020', del_btn_hover_bg: '#8a0019', chat_radius: '16', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#000000', input_border_focus_color: '#6200EE', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        fluent_design: { header_bg: '#f3f2f1', header_text: '#201f1e', chat_bg: '#faf9f8', bot_bubble_bg: '#ffffff', bot_bubble_text: '#201f1e', user_bubble_bg: '#0078d4', user_bubble_text: '#ffffff', send_btn_bg: '#0078d4', send_btn_hover_bg: '#005a9e', send_btn_text: '#ffffff', del_btn_bg: '#d13438', del_btn_hover_bg: '#a4262c', chat_radius: '4', chat_width: '400', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#edebe9', input_border_focus_color: '#0078d4', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        cyberpunk: { header_bg: '#fcee0a', header_text: '#000000', chat_bg: '#0b0b0b', bot_bubble_bg: '#ff003c', bot_bubble_text: '#ffffff', user_bubble_bg: '#00f0ff', user_bubble_text: '#000000', send_btn_bg: '#fcee0a', send_btn_hover_bg: '#c9bc05', send_btn_text: '#000000', del_btn_bg: '#ff003c', del_btn_hover_bg: '#cc0030', chat_radius: '0', chat_width: '420', input_bg_color: '#1c1c1c', input_border_sides: 'all', input_border_width: '2', input_border_color: '#00f0ff', input_border_focus_color: '#ff003c', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        monochrome: { header_bg: '#000000', header_text: '#ffffff', chat_bg: '#ffffff', bot_bubble_bg: '#f5f5f5', bot_bubble_text: '#000000', user_bubble_bg: '#000000', user_bubble_text: '#ffffff', send_btn_bg: '#000000', send_btn_hover_bg: '#333333', send_btn_text: '#ffffff', del_btn_bg: '#666666', del_btn_hover_bg: '#000000', chat_radius: '0', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '2', input_border_color: '#000000', input_border_focus_color: '#000000', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        glassmorphism: { header_bg: '#1f2937', header_text: '#ffffff', chat_bg: '#111827', bot_bubble_bg: '#374151', bot_bubble_text: '#f3f4f6', user_bubble_bg: '#6366f1', user_bubble_text: '#ffffff', send_btn_bg: '#6366f1', send_btn_hover_bg: '#4f46e5', send_btn_text: '#ffffff', del_btn_bg: '#ef4444', del_btn_hover_bg: '#dc2626', chat_radius: '20', chat_width: '400', input_bg_color: '#1f2937', input_border_sides: 'all', input_border_width: '1', input_border_color: '#4b5563', input_border_focus_color: '#6366f1', toggle_icon_transparent: '1', chat_bg_transparent: '1' },
        
        spotify: { header_bg: '#1DB954', header_text: '#ffffff', chat_bg: '#121212', bot_bubble_bg: '#282828', bot_bubble_text: '#ffffff', user_bubble_bg: '#1DB954', user_bubble_text: '#ffffff', send_btn_bg: '#1DB954', send_btn_hover_bg: '#1ed760', send_btn_text: '#ffffff', del_btn_bg: '#e91429', del_btn_hover_bg: '#ff1a35', chat_radius: '12', chat_width: '380', input_bg_color: '#282828', input_border_sides: 'none', input_border_width: '0', input_border_color: '#282828', input_border_focus_color: '#1DB954', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        netflix: { header_bg: '#E50914', header_text: '#ffffff', chat_bg: '#141414', bot_bubble_bg: '#333333', bot_bubble_text: '#ffffff', user_bubble_bg: '#E50914', user_bubble_text: '#ffffff', send_btn_bg: '#E50914', send_btn_hover_bg: '#f40612', send_btn_text: '#ffffff', del_btn_bg: '#737373', del_btn_hover_bg: '#555555', chat_radius: '4', chat_width: '400', input_bg_color: '#333333', input_border_sides: 'all', input_border_width: '1', input_border_color: '#555555', input_border_focus_color: '#E50914', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        twitch: { header_bg: '#9146FF', header_text: '#ffffff', chat_bg: '#18181B', bot_bubble_bg: '#303032', bot_bubble_text: '#efeff1', user_bubble_bg: '#9146FF', user_bubble_text: '#ffffff', send_btn_bg: '#9146FF', send_btn_hover_bg: '#a970ff', send_btn_text: '#ffffff', del_btn_bg: '#e02424', del_btn_hover_bg: '#eb0404', chat_radius: '6', chat_width: '380', input_bg_color: '#303032', input_border_sides: 'all', input_border_width: '2', input_border_color: '#464649', input_border_focus_color: '#9146FF', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        github: { header_bg: '#24292e', header_text: '#ffffff', chat_bg: '#ffffff', bot_bubble_bg: '#f6f8fa', bot_bubble_text: '#24292e', user_bubble_bg: '#2ea44f', user_bubble_text: '#ffffff', send_btn_bg: '#2ea44f', send_btn_hover_bg: '#2c974b', send_btn_text: '#ffffff', del_btn_bg: '#cb2431', del_btn_hover_bg: '#b31d28', chat_radius: '6', chat_width: '400', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#e1e4e8', input_border_focus_color: '#0366d6', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        github_dark: { header_bg: '#161b22', header_text: '#ffffff', chat_bg: '#0d1117', bot_bubble_bg: '#21262d', bot_bubble_text: '#c9d1d9', user_bubble_bg: '#238636', user_bubble_text: '#ffffff', send_btn_bg: '#238636', send_btn_hover_bg: '#2ea043', send_btn_text: '#ffffff', del_btn_bg: '#da3633', del_btn_hover_bg: '#f85149', chat_radius: '6', chat_width: '400', input_bg_color: '#0d1117', input_border_sides: 'all', input_border_width: '1', input_border_color: '#30363d', input_border_focus_color: '#58a6ff', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        notion: { header_bg: '#ffffff', header_text: '#37352f', chat_bg: '#ffffff', bot_bubble_bg: '#f7f6f3', bot_bubble_text: '#37352f', user_bubble_bg: '#2eaadc', user_bubble_text: '#ffffff', send_btn_bg: '#37352f', send_btn_hover_bg: '#2f2d27', send_btn_text: '#ffffff', del_btn_bg: '#e03e3e', del_btn_hover_bg: '#c93636', chat_radius: '8', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'bottom', input_border_width: '1', input_border_color: '#e1dfdd', input_border_focus_color: '#37352f', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        pinterest: { header_bg: '#ffffff', header_text: '#111111', chat_bg: '#ffffff', bot_bubble_bg: '#efefef', bot_bubble_text: '#111111', user_bubble_bg: '#e60023', user_bubble_text: '#ffffff', send_btn_bg: '#e60023', send_btn_hover_bg: '#ad081b', send_btn_text: '#ffffff', del_btn_bg: '#111111', del_btn_hover_bg: '#000000', chat_radius: '24', chat_width: '380', input_bg_color: '#efefef', input_border_sides: 'none', input_border_width: '0', input_border_color: '#efefef', input_border_focus_color: '#e60023', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        snapchat: { header_bg: '#FFFC00', header_text: '#000000', chat_bg: '#ffffff', bot_bubble_bg: '#f1f1f1', bot_bubble_text: '#000000', user_bubble_bg: '#000000', user_bubble_text: '#ffffff', send_btn_bg: '#FFFC00', send_btn_hover_bg: '#d4d100', send_btn_text: '#000000', del_btn_bg: '#ff004f', del_btn_hover_bg: '#d60042', chat_radius: '16', chat_width: '380', input_bg_color: '#f1f1f1', input_border_sides: 'none', input_border_width: '0', input_border_color: '#f1f1f1', input_border_focus_color: '#000000', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        reddit: { header_bg: '#FF4500', header_text: '#ffffff', chat_bg: '#DAE0E6', bot_bubble_bg: '#ffffff', bot_bubble_text: '#1c1c1c', user_bubble_bg: '#0079D3', user_bubble_text: '#ffffff', send_btn_bg: '#FF4500', send_btn_hover_bg: '#e03d00', send_btn_text: '#ffffff', del_btn_bg: '#878A8C', del_btn_hover_bg: '#6a6c6d', chat_radius: '8', chat_width: '400', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#ccc', input_border_focus_color: '#FF4500', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        tiktok: { header_bg: '#010101', header_text: '#ffffff', chat_bg: '#ffffff', bot_bubble_bg: '#f1f1f2', bot_bubble_text: '#161823', user_bubble_bg: '#FE2C55', user_bubble_text: '#ffffff', send_btn_bg: '#25F4EE', send_btn_hover_bg: '#20d6d0', send_btn_text: '#010101', del_btn_bg: '#FE2C55', del_btn_hover_bg: '#e3264b', chat_radius: '16', chat_width: '380', input_bg_color: '#f1f1f2', input_border_sides: 'none', input_border_width: '0', input_border_color: '#f1f1f2', input_border_focus_color: '#FE2C55', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        dracula: { header_bg: '#282a36', header_text: '#f8f8f2', chat_bg: '#282a36', bot_bubble_bg: '#44475a', bot_bubble_text: '#f8f8f2', user_bubble_bg: '#bd93f9', user_bubble_text: '#282a36', send_btn_bg: '#50fa7b', send_btn_hover_bg: '#43d969', send_btn_text: '#282a36', del_btn_bg: '#ff5555', del_btn_hover_bg: '#d64545', chat_radius: '10', chat_width: '420', input_bg_color: '#44475a', input_border_sides: 'all', input_border_width: '1', input_border_color: '#6272a4', input_border_focus_color: '#bd93f9', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        monokai: { header_bg: '#272822', header_text: '#f8f8f2', chat_bg: '#272822', bot_bubble_bg: '#3e3d32', bot_bubble_text: '#f8f8f2', user_bubble_bg: '#a6e22e', user_bubble_text: '#272822', send_btn_bg: '#fd971f', send_btn_hover_bg: '#e08316', send_btn_text: '#272822', del_btn_bg: '#f92672', del_btn_hover_bg: '#d3205f', chat_radius: '8', chat_width: '420', input_bg_color: '#3e3d32', input_border_sides: 'all', input_border_width: '1', input_border_color: '#75715e', input_border_focus_color: '#a6e22e', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        nord: { header_bg: '#2e3440', header_text: '#eceff4', chat_bg: '#2e3440', bot_bubble_bg: '#3b4252', bot_bubble_text: '#e5e9f0', user_bubble_bg: '#81a1c1', user_bubble_text: '#2e3440', send_btn_bg: '#88c0d0', send_btn_hover_bg: '#77abbb', send_btn_text: '#2e3440', del_btn_bg: '#bf616a', del_btn_hover_bg: '#a35259', chat_radius: '10', chat_width: '400', input_bg_color: '#3b4252', input_border_sides: 'all', input_border_width: '1', input_border_color: '#4c566a', input_border_focus_color: '#81a1c1', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        synthwave: { header_bg: '#262335', header_text: '#ff7edb', chat_bg: '#262335', bot_bubble_bg: '#34294f', bot_bubble_text: '#36f9f6', user_bubble_bg: '#ff7edb', user_bubble_text: '#262335', send_btn_bg: '#f97e72', send_btn_hover_bg: '#d66a60', send_btn_text: '#262335', del_btn_bg: '#fe4450', del_btn_hover_bg: '#d63a43', chat_radius: '0', chat_width: '400', input_bg_color: '#34294f', input_border_sides: 'all', input_border_width: '2', input_border_color: '#ff7edb', input_border_focus_color: '#36f9f6', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        solarized_dark: { header_bg: '#002b36', header_text: '#839496', chat_bg: '#002b36', bot_bubble_bg: '#073642', bot_bubble_text: '#93a1a1', user_bubble_bg: '#b58900', user_bubble_text: '#002b36', send_btn_bg: '#2aa198', send_btn_hover_bg: '#22857c', send_btn_text: '#002b36', del_btn_bg: '#dc322f', del_btn_hover_bg: '#b52926', chat_radius: '4', chat_width: '400', input_bg_color: '#073642', input_border_sides: 'all', input_border_width: '1', input_border_color: '#586e75', input_border_focus_color: '#2aa198', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        solarized_light: { header_bg: '#fdf6e3', header_text: '#657b83', chat_bg: '#fdf6e3', bot_bubble_bg: '#eee8d5', bot_bubble_text: '#586e75', user_bubble_bg: '#268bd2', user_bubble_text: '#fdf6e3', send_btn_bg: '#859900', send_btn_hover_bg: '#6c7c00', send_btn_text: '#fdf6e3', del_btn_bg: '#cb4b16', del_btn_hover_bg: '#aa3d11', chat_radius: '4', chat_width: '400', input_bg_color: '#eee8d5', input_border_sides: 'all', input_border_width: '1', input_border_color: '#93a1a1', input_border_focus_color: '#268bd2', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        windows_11: { header_bg: '#ffffff', header_text: '#000000', chat_bg: '#f3f3f3', bot_bubble_bg: '#ffffff', bot_bubble_text: '#000000', user_bubble_bg: '#005fb8', user_bubble_text: '#ffffff', send_btn_bg: '#005fb8', send_btn_hover_bg: '#004c93', send_btn_text: '#ffffff', del_btn_bg: '#d13438', del_btn_hover_bg: '#b32c30', chat_radius: '8', chat_width: '400', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#e5e5e5', input_border_focus_color: '#005fb8', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        macos: { header_bg: '#e8e8e8', header_text: '#000000', chat_bg: '#ffffff', bot_bubble_bg: '#f0f0f0', bot_bubble_text: '#000000', user_bubble_bg: '#007aff', user_bubble_text: '#ffffff', send_btn_bg: '#007aff', send_btn_hover_bg: '#0062cc', send_btn_text: '#ffffff', del_btn_bg: '#ff3b30', del_btn_hover_bg: '#cc2f26', chat_radius: '12', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#d1d1d6', input_border_focus_color: '#007aff', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        neon_glow: { header_bg: '#000000', header_text: '#39ff14', chat_bg: '#000000', bot_bubble_bg: '#111111', bot_bubble_text: '#ffffff', user_bubble_bg: '#39ff14', user_bubble_text: '#000000', send_btn_bg: '#bc13fe', send_btn_hover_bg: '#990fd1', send_btn_text: '#ffffff', del_btn_bg: '#ff0000', del_btn_hover_bg: '#cc0000', chat_radius: '8', chat_width: '400', input_bg_color: '#111111', input_border_sides: 'all', input_border_width: '2', input_border_color: '#39ff14', input_border_focus_color: '#bc13fe', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        pastel_dream: { header_bg: '#fdfbfb', header_text: '#5b5b5b', chat_bg: '#fff0f5', bot_bubble_bg: '#ffffff', bot_bubble_text: '#5b5b5b', user_bubble_bg: '#ffb6c1', user_bubble_text: '#ffffff', send_btn_bg: '#add8e6', send_btn_hover_bg: '#99c2cf', send_btn_text: '#ffffff', del_btn_bg: '#ff6b81', del_btn_hover_bg: '#e65c71', chat_radius: '20', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '2', input_border_color: '#ffe4e1', input_border_focus_color: '#ffb6c1', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        ocean_breeze: { header_bg: '#006994', header_text: '#ffffff', chat_bg: '#e0f7fa', bot_bubble_bg: '#ffffff', bot_bubble_text: '#004d6e', user_bubble_bg: '#008b8b', user_bubble_text: '#ffffff', send_btn_bg: '#20b2aa', send_btn_hover_bg: '#1b9992', send_btn_text: '#ffffff', del_btn_bg: '#ff7f50', del_btn_hover_bg: '#db6b42', chat_radius: '16', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'bottom', input_border_width: '2', input_border_color: '#b2dfdb', input_border_focus_color: '#008b8b', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        forest_green: { header_bg: '#2d5a27', header_text: '#ffffff', chat_bg: '#f1f8e9', bot_bubble_bg: '#ffffff', bot_bubble_text: '#1b3317', user_bubble_bg: '#388e3c', user_bubble_text: '#ffffff', send_btn_bg: '#66bb6a', send_btn_hover_bg: '#54a058', send_btn_text: '#ffffff', del_btn_bg: '#e53935', del_btn_hover_bg: '#c62828', chat_radius: '12', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#c8e6c9', input_border_focus_color: '#388e3c', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        sunset_orange: { header_bg: '#e65100', header_text: '#ffffff', chat_bg: '#fff3e0', bot_bubble_bg: '#ffffff', bot_bubble_text: '#4a1900', user_bubble_bg: '#ff9800', user_bubble_text: '#ffffff', send_btn_bg: '#ffb74d', send_btn_hover_bg: '#db9b3f', send_btn_text: '#ffffff', del_btn_bg: '#b71c1c', del_btn_hover_bg: '#991515', chat_radius: '18', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#ffe0b2', input_border_focus_color: '#ff9800', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        luxury_gold: { header_bg: '#1a1a1a', header_text: '#d4af37', chat_bg: '#0d0d0d', bot_bubble_bg: '#262626', bot_bubble_text: '#e6e6e6', user_bubble_bg: '#d4af37', user_bubble_text: '#1a1a1a', send_btn_bg: '#d4af37', send_btn_hover_bg: '#b5952f', send_btn_text: '#1a1a1a', del_btn_bg: '#800000', del_btn_hover_bg: '#660000', chat_radius: '0', chat_width: '400', input_bg_color: '#1a1a1a', input_border_sides: 'all', input_border_width: '1', input_border_color: '#d4af37', input_border_focus_color: '#ffffff', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        matte_black: { header_bg: '#121212', header_text: '#ffffff', chat_bg: '#1e1e1e', bot_bubble_bg: '#2c2c2c', bot_bubble_text: '#ffffff', user_bubble_bg: '#404040', user_bubble_text: '#ffffff', send_btn_bg: '#ffffff', send_btn_hover_bg: '#cccccc', send_btn_text: '#000000', del_btn_bg: '#ff3b30', del_btn_hover_bg: '#cc2f26', chat_radius: '8', chat_width: '400', input_bg_color: '#2c2c2c', input_border_sides: 'all', input_border_width: '1', input_border_color: '#555555', input_border_focus_color: '#ffffff', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        hacker: { header_bg: '#000000', header_text: '#00ff00', chat_bg: '#000000', bot_bubble_bg: '#000000', bot_bubble_text: '#00ff00', user_bubble_bg: '#003300', user_bubble_text: '#00ff00', send_btn_bg: '#00ff00', send_btn_hover_bg: '#00cc00', send_btn_text: '#000000', del_btn_bg: '#cc0000', del_btn_hover_bg: '#990000', chat_radius: '0', chat_width: '450', input_bg_color: '#000000', input_border_sides: 'all', input_border_width: '1', input_border_color: '#00ff00', input_border_focus_color: '#ffffff', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        deep_space: { header_bg: '#0b0c10', header_text: '#58a6ff', chat_bg: '#0b0c10', bot_bubble_bg: '#161b22', bot_bubble_text: '#c9d1d9', user_bubble_bg: '#1f6feb', user_bubble_text: '#ffffff', send_btn_bg: '#238636', send_btn_hover_bg: '#2ea043', send_btn_text: '#ffffff', del_btn_bg: '#da3633', del_btn_hover_bg: '#f85149', chat_radius: '14', chat_width: '400', input_bg_color: '#0d1117', input_border_sides: 'all', input_border_width: '1', input_border_color: '#30363d', input_border_focus_color: '#58a6ff', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        coral: { header_bg: '#ff7f50', header_text: '#ffffff', chat_bg: '#fff5f0', bot_bubble_bg: '#ffffff', bot_bubble_text: '#4a2517', user_bubble_bg: '#ff6b35', user_bubble_text: '#ffffff', send_btn_bg: '#ff7f50', send_btn_hover_bg: '#e66b3e', send_btn_text: '#ffffff', del_btn_bg: '#d63031', del_btn_hover_bg: '#b32526', chat_radius: '20', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'bottom', input_border_width: '2', input_border_color: '#ffccbc', input_border_focus_color: '#ff6b35', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        mocha: { header_bg: '#4e342e', header_text: '#d7ccc8', chat_bg: '#efebe9', bot_bubble_bg: '#ffffff', bot_bubble_text: '#3e2723', user_bubble_bg: '#5d4037', user_bubble_text: '#ffffff', send_btn_bg: '#795548', send_btn_hover_bg: '#624438', send_btn_text: '#ffffff', del_btn_bg: '#c62828', del_btn_hover_bg: '#9e1c1c', chat_radius: '12', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#d7ccc8', input_border_focus_color: '#5d4037', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        mint: { header_bg: '#00bfa5', header_text: '#ffffff', chat_bg: '#e0f2f1', bot_bubble_bg: '#ffffff', bot_bubble_text: '#004d40', user_bubble_bg: '#00897b', user_bubble_text: '#ffffff', send_btn_bg: '#26a69a', send_btn_hover_bg: '#1d887e', send_btn_text: '#ffffff', del_btn_bg: '#e53935', del_btn_hover_bg: '#c62828', chat_radius: '16', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#b2dfdb', input_border_focus_color: '#00897b', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        lavender: { header_bg: '#7e57c2', header_text: '#ffffff', chat_bg: '#ede7f6', bot_bubble_bg: '#ffffff', bot_bubble_text: '#311b92', user_bubble_bg: '#5e35b1', user_bubble_text: '#ffffff', send_btn_bg: '#9575cd', send_btn_hover_bg: '#7a5bb1', send_btn_text: '#ffffff', del_btn_bg: '#e53935', del_btn_hover_bg: '#c62828', chat_radius: '20', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#d1c4e9', input_border_focus_color: '#5e35b1', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        peach: { header_bg: '#ffab91', header_text: '#bf360c', chat_bg: '#fbe9e7', bot_bubble_bg: '#ffffff', bot_bubble_text: '#bf360c', user_bubble_bg: '#ff8a65', user_bubble_text: '#ffffff', send_btn_bg: '#ff7043', send_btn_hover_bg: '#e05c34', send_btn_text: '#ffffff', del_btn_bg: '#d84315', del_btn_hover_bg: '#b5350e', chat_radius: '18', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '2', input_border_color: '#ffccbc', input_border_focus_color: '#ff8a65', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        teal: { header_bg: '#00695c', header_text: '#ffffff', chat_bg: '#e0f2f1', bot_bubble_bg: '#ffffff', bot_bubble_text: '#004d40', user_bubble_bg: '#00897b', user_bubble_text: '#ffffff', send_btn_bg: '#009688', send_btn_hover_bg: '#007a6e', send_btn_text: '#ffffff', del_btn_bg: '#d32f2f', del_btn_hover_bg: '#b52323', chat_radius: '8', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#80cbc4', input_border_focus_color: '#00897b', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        slate: { header_bg: '#455a64', header_text: '#ffffff', chat_bg: '#eceff1', bot_bubble_bg: '#ffffff', bot_bubble_text: '#263238', user_bubble_bg: '#607d8b', user_bubble_text: '#ffffff', send_btn_bg: '#546e7a', send_btn_hover_bg: '#435863', send_btn_text: '#ffffff', del_btn_bg: '#d32f2f', del_btn_hover_bg: '#b52323', chat_radius: '12', chat_width: '400', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#cfd8dc', input_border_focus_color: '#607d8b', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        crimson: { header_bg: '#b71c1c', header_text: '#ffffff', chat_bg: '#ffebee', bot_bubble_bg: '#ffffff', bot_bubble_text: '#880e4f', user_bubble_bg: '#d32f2f', user_bubble_text: '#ffffff', send_btn_bg: '#e53935', send_btn_hover_bg: '#c62828', send_btn_text: '#ffffff', del_btn_bg: '#c62828', del_btn_hover_bg: '#9e1c1c', chat_radius: '16', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#ffcdd2', input_border_focus_color: '#d32f2f', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        azure: { header_bg: '#0277bd', header_text: '#ffffff', chat_bg: '#e1f5fe', bot_bubble_bg: '#ffffff', bot_bubble_text: '#01579b', user_bubble_bg: '#0288d1', user_bubble_text: '#ffffff', send_btn_bg: '#039be5', send_btn_hover_bg: '#0283c4', send_btn_text: '#ffffff', del_btn_bg: '#e53935', del_btn_hover_bg: '#c62828', chat_radius: '16', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#b3e5fc', input_border_focus_color: '#0288d1', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        indigo: { header_bg: '#283593', header_text: '#ffffff', chat_bg: '#e8eaf6', bot_bubble_bg: '#ffffff', bot_bubble_text: '#1a237e', user_bubble_bg: '#3f51b5', user_bubble_text: '#ffffff', send_btn_bg: '#5c6bc0', send_btn_hover_bg: '#4756a8', send_btn_text: '#ffffff', del_btn_bg: '#e53935', del_btn_hover_bg: '#c62828', chat_radius: '12', chat_width: '400', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#c5cae9', input_border_focus_color: '#3f51b5', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        amber: { header_bg: '#ff8f00', header_text: '#ffffff', chat_bg: '#fff8e1', bot_bubble_bg: '#ffffff', bot_bubble_text: '#ff6f00', user_bubble_bg: '#ffb300', user_bubble_text: '#000000', send_btn_bg: '#ffca28', send_btn_hover_bg: '#e5b31f', send_btn_text: '#000000', del_btn_bg: '#d84315', del_btn_hover_bg: '#b5350e', chat_radius: '16', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#ffecb3', input_border_focus_color: '#ffb300', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        emerald: { header_bg: '#2e7d32', header_text: '#ffffff', chat_bg: '#e8f5e9', bot_bubble_bg: '#ffffff', bot_bubble_text: '#1b5e20', user_bubble_bg: '#43a047', user_bubble_text: '#ffffff', send_btn_bg: '#4caf50', send_btn_hover_bg: '#409c44', send_btn_text: '#ffffff', del_btn_bg: '#c62828', del_btn_hover_bg: '#9e1c1c', chat_radius: '12', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#c8e6c9', input_border_focus_color: '#43a047', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        fuchsia: { header_bg: '#c2185b', header_text: '#ffffff', chat_bg: '#fce4ec', bot_bubble_bg: '#ffffff', bot_bubble_text: '#880e4f', user_bubble_bg: '#d81b60', user_bubble_text: '#ffffff', send_btn_bg: '#e91e63', send_btn_hover_bg: '#cc1554', send_btn_text: '#ffffff', del_btn_bg: '#c62828', del_btn_hover_bg: '#9e1c1c', chat_radius: '20', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'bottom', input_border_width: '2', input_border_color: '#f8bbd0', input_border_focus_color: '#d81b60', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        rose: { header_bg: '#e91e63', header_text: '#ffffff', chat_bg: '#fce4ec', bot_bubble_bg: '#ffffff', bot_bubble_text: '#880e4f', user_bubble_bg: '#ff4081', user_bubble_text: '#ffffff', send_btn_bg: '#f06292', send_btn_hover_bg: '#d6527e', send_btn_text: '#ffffff', del_btn_bg: '#d32f2f', del_btn_hover_bg: '#b52323', chat_radius: '16', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#f8bbd0', input_border_focus_color: '#ff4081', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        violet: { header_bg: '#673ab7', header_text: '#ffffff', chat_bg: '#f3e5f5', bot_bubble_bg: '#ffffff', bot_bubble_text: '#4a148c', user_bubble_bg: '#7e57c2', user_bubble_text: '#ffffff', send_btn_bg: '#9575cd', send_btn_hover_bg: '#7a5bb1', send_btn_text: '#ffffff', del_btn_bg: '#d32f2f', del_btn_hover_bg: '#b52323', chat_radius: '16', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#e1bee7', input_border_focus_color: '#7e57c2', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        cyan: { header_bg: '#0097a7', header_text: '#ffffff', chat_bg: '#e0f7fa', bot_bubble_bg: '#ffffff', bot_bubble_text: '#006064', user_bubble_bg: '#00bcd4', user_bubble_text: '#000000', send_btn_bg: '#26c6da', send_btn_hover_bg: '#1dabbd', send_btn_text: '#000000', del_btn_bg: '#d32f2f', del_btn_hover_bg: '#b52323', chat_radius: '12', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#b2ebf2', input_border_focus_color: '#00bcd4', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        lime: { header_bg: '#afb42b', header_text: '#ffffff', chat_bg: '#f9fbe7', bot_bubble_bg: '#ffffff', bot_bubble_text: '#827717', user_bubble_bg: '#cddc39', user_bubble_text: '#000000', send_btn_bg: '#d4e157', send_btn_hover_bg: '#bccc45', send_btn_text: '#000000', del_btn_bg: '#e53935', del_btn_hover_bg: '#c62828', chat_radius: '16', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#f0f4c3', input_border_focus_color: '#cddc39', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        emerald_dark: { header_bg: '#1b5e20', header_text: '#ffffff', chat_bg: '#000000', bot_bubble_bg: '#111111', bot_bubble_text: '#4caf50', user_bubble_bg: '#2e7d32', user_bubble_text: '#ffffff', send_btn_bg: '#388e3c', send_btn_hover_bg: '#2d7830', send_btn_text: '#ffffff', del_btn_bg: '#c62828', del_btn_hover_bg: '#9e1c1c', chat_radius: '4', chat_width: '400', input_bg_color: '#111111', input_border_sides: 'all', input_border_width: '1', input_border_color: '#2e7d32', input_border_focus_color: '#4caf50', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        midnight: { header_bg: '#1a237e', header_text: '#ffffff', chat_bg: '#000000', bot_bubble_bg: '#111111', bot_bubble_text: '#c5cae9', user_bubble_bg: '#283593', user_bubble_text: '#ffffff', send_btn_bg: '#3f51b5', send_btn_hover_bg: '#304199', send_btn_text: '#ffffff', del_btn_bg: '#d32f2f', del_btn_hover_bg: '#b52323', chat_radius: '8', chat_width: '420', input_bg_color: '#111111', input_border_sides: 'bottom', input_border_width: '2', input_border_color: '#283593', input_border_focus_color: '#5c6bc0', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        valentine: { header_bg: '#e91e63', header_text: '#ffffff', chat_bg: '#fce4ec', bot_bubble_bg: '#ffffff', bot_bubble_text: '#880e4f', user_bubble_bg: '#ff4081', user_bubble_text: '#ffffff', send_btn_bg: '#f50057', send_btn_hover_bg: '#d1004a', send_btn_text: '#ffffff', del_btn_bg: '#c51162', del_btn_hover_bg: '#9e0d4e', chat_radius: '24', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '2', input_border_color: '#f8bbd0', input_border_focus_color: '#ff4081', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        halloween: { header_bg: '#e65100', header_text: '#000000', chat_bg: '#121212', bot_bubble_bg: '#262626', bot_bubble_text: '#ff9800', user_bubble_bg: '#ff6d00', user_bubble_text: '#000000', send_btn_bg: '#ff9800', send_btn_hover_bg: '#db7d00', send_btn_text: '#000000', del_btn_bg: '#dd2c00', del_btn_hover_bg: '#bd2400', chat_radius: '16', chat_width: '400', input_bg_color: '#262626', input_border_sides: 'all', input_border_width: '1', input_border_color: '#e65100', input_border_focus_color: '#ff9800', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        royal_purple: { header_bg: '#4a148c', header_text: '#ffffff', chat_bg: '#f3e5f5', bot_bubble_bg: '#ffffff', bot_bubble_text: '#4a148c', user_bubble_bg: '#7b1fa2', user_bubble_text: '#ffffff', send_btn_bg: '#9c27b0', send_btn_hover_bg: '#7b1fa2', send_btn_text: '#ffffff', del_btn_bg: '#d32f2f', del_btn_hover_bg: '#b52323', chat_radius: '12', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'all', input_border_width: '1', input_border_color: '#e1bee7', input_border_focus_color: '#7b1fa2', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        golden_hour: { header_bg: '#ffb300', header_text: '#000000', chat_bg: '#fff8e1', bot_bubble_bg: '#ffffff', bot_bubble_text: '#ff6f00', user_bubble_bg: '#ffca28', user_bubble_text: '#000000', send_btn_bg: '#ffd54f', send_btn_hover_bg: '#e0bc46', send_btn_text: '#000000', del_btn_bg: '#d84315', del_btn_hover_bg: '#b5350e', chat_radius: '16', chat_width: '380', input_bg_color: '#ffffff', input_border_sides: 'bottom', input_border_width: '2', input_border_color: '#ffecb3', input_border_focus_color: '#ffca28', toggle_icon_transparent: '0', chat_bg_transparent: '0' },
        frost_glass: { header_bg: 'rgba(255,255,255,0.2)', header_text: '#333333', chat_bg: 'rgba(255,255,255,0.6)', bot_bubble_bg: 'rgba(255,255,255,0.8)', bot_bubble_text: '#333333', user_bubble_bg: 'rgba(34,113,177,0.8)', user_bubble_text: '#ffffff', send_btn_bg: '#2271b1', send_btn_hover_bg: '#1a5b91', send_btn_text: '#ffffff', del_btn_bg: '#d63638', del_btn_hover_bg: '#b32d2e', chat_radius: '20', chat_width: '400', input_bg_color: 'rgba(255,255,255,0.5)', input_border_sides: 'all', input_border_width: '1', input_border_color: '#ffffff', input_border_focus_color: '#2271b1', toggle_icon_transparent: '1', chat_bg_transparent: '1' }
    };

    /* Field key -> CSS variable used by the widget. */
    var VARS = {
        general_border_color: '--wp-ai-border-color', header_bg: '--wp-ai-header-bg', header_text: '--wp-ai-header-text',
        chat_bg: '--wp-ai-chat-bg', bot_bubble_bg: '--wp-ai-bot-bg', bot_bubble_text: '--wp-ai-bot-text',
        user_bubble_bg: '--wp-ai-user-bg', user_bubble_text: '--wp-ai-user-text',
        send_btn_bg: ['--wp-ai-send-bg', '--wp-ai-primary-color'], send_btn_hover_bg: '--wp-ai-send-hover-bg',
        send_btn_text: '--wp-ai-send-text', send_btn_icon_hover: '--wp-ai-send-icon-hover',
        del_btn_bg: '--wp-ai-del-bg', del_btn_hover_bg: '--wp-ai-del-hover-bg',
        close_btn_color: '--wp-ai-close-color', close_btn_hover_bg: '--wp-ai-close-hover-bg',
        close_bg: '--wp-ai-x-bg', close_icon: '--wp-ai-x-icon', close_hover_bg: '--wp-ai-x-hover-bg', close_hover_icon: '--wp-ai-x-hover-icon',
        input_bg_color: '--wp-ai-input-bg', input_border_color: '--wp-ai-input-border-color',
        input_border_focus_color: '--wp-ai-input-focus-color'
    };
    var PX_VARS = {
        close_btn_size: '--wp-ai-close-size', input_border_width: '--wp-ai-input-border-width',
        chat_width: '--wp-ai-chat-width', chat_height: '--wp-ai-chat-height', chat_radius: '--wp-ai-chat-radius'
    };

    function field(key) { return $form.find('[name="' + optName + '[' + key + ']"]').not('[type="hidden"]'); }

    /* ---------- Chat icon (launcher) ---------- */
    var stage = document.querySelector('.akdk-preview-stage');
    var launcherRow = document.querySelector('.akdk-preview-launcher');
    var iconGrid = document.querySelector('.akdk-icon-grid');
    var ICON_VARS = {
        icon_main: '--akic-main', icon_face: '--akic-face', icon_eye: '--akic-eye',
        icon_accent: '--akic-accent', icon_ink: '--akic-ink-light', icon_ink_dark: '--akic-ink-dark'
    };
    var ICON_DEFAULTS = { icon_main: '#fd9400', icon_face: '#0a0a0a', icon_eye: '#ffffff', icon_accent: '#fd9400', icon_ink: '#0a0a0a', icon_ink_dark: '#ffffff' };

    function launcher() { return launcherRow ? launcherRow.querySelector('.wp-ai-chatbot-toggle') : null; }

    function setIconStyle(style) {
        var t = launcher();
        if (!t) return;
        var keep = t.querySelectorAll('.wp-ai-greet, .wp-ai-launcher-x');
        t.classList.remove('wp-ai-toggle-animated', 'wp-ai-toggle-classic', 'wp-ai-toggle-custom');
        [].slice.call(t.childNodes).forEach(function (n) { if (!n.classList || (!n.classList.contains('wp-ai-greet') && !n.classList.contains('wp-ai-launcher-x'))) t.removeChild(n); });
        var first = t.firstChild;
        var card = iconGrid ? iconGrid.querySelector('input[value="' + style + '"]') : null;
        var url = field('toggle_icon').val();
        if (style === 'custom' && url) {
            var img = document.createElement('img'); img.className = 'wp-ai-toggle-img'; img.src = url; img.alt = '';
            t.insertBefore(img, first); t.classList.add('wp-ai-toggle-custom');
        } else if (style !== 'custom' && style !== 'classic' && card) {
            t.insertBefore(card.parentNode.querySelector('svg.akic').cloneNode(true), first); t.classList.add('wp-ai-toggle-animated');
        } else {
            var classic = iconGrid ? iconGrid.querySelector('.akic-classic-dot') : null;
            if (classic) { [].slice.call(classic.querySelectorAll('svg')).forEach(function (svg) { t.insertBefore(svg.cloneNode(true), first); }); }
            t.classList.add('wp-ai-toggle-classic');
        }
        $('.akdk-custom-icon-row').prop('hidden', style !== 'custom');
        $('.akic-card').removeClass('is-selected');
        if (card) $(card).closest('.akic-card').addClass('is-selected');
        return keep;
    }

    function setSite(site) {
        $('.akdk-site-btn').removeClass('is-active').filter('[data-site="' + site + '"]').addClass('is-active');
        if (iconGrid) iconGrid.classList.toggle('akdk-site-dark', site === 'dark');
        if (stage) stage.classList.toggle('akdk-stage-dark', site === 'dark');
    }

    function setIconMode(mode) {
        [iconGrid, launcher()].forEach(function (el) {
            if (!el) return;
            el.classList.remove('akic-mode-light', 'akic-mode-dark', 'akic-mode-auto');
            el.classList.add('akic-mode-' + mode);
        });
        if (mode !== 'auto') setSite(mode);
    }

    function setGreeting() {
        var t = launcher();
        if (!t) return;
        var on = field('launcher_greet').is(':checked');
        var text = $.trim(field('launcher_greeting').val() || '');
        var g = t.querySelector('.wp-ai-greet');
        if (!on || !text) { if (g) g.parentNode.removeChild(g); return; }
        if (!g) { g = document.createElement('span'); g.className = 'wp-ai-greet'; g.setAttribute('aria-hidden', 'true'); t.insertBefore(g, t.querySelector('.wp-ai-launcher-x')); }
        g.textContent = text;
    }

    function updateIcon(key, value) {
        if (ICON_VARS[key]) {
            var v = value || ICON_DEFAULTS[key];
            [iconGrid, launcher()].forEach(function (el) { if (el) el.style.setProperty(ICON_VARS[key], v); });
            return true;
        }
        switch (key) {
            case 'launcher_style': setIconStyle(value); return true;
            case 'launcher_mode': setIconMode(value); return true;
            case 'launcher_greet': case 'launcher_greeting': setGreeting(); return true;
            case 'toggle_icon':
                var img = launcher() && launcher().querySelector('.wp-ai-toggle-img');
                if (img && value) img.src = value;
                var thumb = document.querySelector('.akic-custom-thumb');
                if (thumb && value) thumb.innerHTML = '<img src="' + String(value).replace(/"/g, '&quot;') + '" alt="">';
                if (!img && value && $form.find('input[data-key="launcher_style"]:checked').val() === 'custom') setIconStyle('custom');
                return true;
            case 'icon_size':
                if (launcherRow) launcherRow.style.setProperty('--wp-ai-icon-size', (parseInt(value, 10) || 64) + 'px');
                return true;
            case 'toggle_icon_transparent':
                if (launcherRow) launcherRow.classList.toggle('wp-ai-toggle-transparent', value === '1');
                return true;
            case 'position':
                if (launcherRow) launcherRow.classList.toggle('wp-ai-pos-left', value === 'left');
                return false;
            case 'header_bg': case 'header_text':
                if (launcherRow) launcherRow.style.setProperty(key === 'header_bg' ? '--wp-ai-header-bg' : '--wp-ai-header-text', value);
                $('.akic-classic-dot').css(key === 'header_bg' ? 'background' : 'color', value);
                return false;
        }
        return false;
    }

    $('.akdk-site-btn').on('click', function () { setSite($(this).data('site')); });
    $('#akdk-icon-colors-reset').on('click', function () {
        Object.keys(ICON_DEFAULTS).forEach(function (k) { setField(k, ICON_DEFAULTS[k]); });
        markDirty();
    });

    function updatePreview(key, value) {
        if (updateIcon(key, value)) return;
        if (!preview) return;
        if (VARS[key]) {
            [].concat(VARS[key]).forEach(function (v) { preview.style.setProperty(v, value); });
        } else if (PX_VARS[key]) {
            preview.style.setProperty(PX_VARS[key], (parseInt(value, 10) || 0) + 'px');
        } else if (key === 'bot_name') {
            var n = preview.querySelector('.wp-ai-bot-name'); if (n) n.textContent = value || 'AI Assistant';
            var i = preview.querySelector('.wp-ai-avatar-initial'); if (i) i.textContent = (value || 'A').trim().charAt(0).toUpperCase();
        } else if (key === 'header_subtitle') {
            var sub = preview.querySelector('.wp-ai-subtitle');
            if (!sub) { sub = document.createElement('span'); sub.className = 'wp-ai-subtitle'; preview.querySelector('.wp-ai-chat-title').appendChild(sub); }
            sub.textContent = value; sub.style.display = value ? '' : 'none';
        } else if (key === 'input_border_sides') {
            preview.setAttribute('data-input-border', value);
        } else if (key === 'quick_actions_align') {
            var w = preview.querySelector('.wp-ai-quick-actions-wrapper');
            if (w) w.className = w.className.replace(/wp-ai-qa-align-\w+/, 'wp-ai-qa-align-' + value);
        } else if (key === 'show_branding') {
            var b = preview.querySelector('.wp-ai-branding');
            if (!b && value === '1') {
                b = document.createElement('div'); b.className = 'wp-ai-branding';
                b.innerHTML = 'Powered by <a href="https://akdeepknowledge.com" target="_blank" rel="noopener">AK Deep Knowledge</a>';
                preview.querySelector('.wp-ai-dock').appendChild(b);
            }
            if (b) b.style.display = value === '1' ? '' : 'none';
        } else if (key === 'voice_input') {
            var mic = preview.querySelector('.wp-ai-voice-btn'); if (mic) mic.hidden = value !== '1';
        } else if (key === 'chat_bg_transparent') {
            preview.classList.toggle('wp-ai-glass', value === '1');
        }
    }

    function currentValue($el) {
        if ($el.is(':checkbox')) return $el.is(':checked') ? '1' : '0';
        if ($el.is(':radio')) return $form.find('[name="' + $el.attr('name') + '"]:checked').val();
        return $el.val();
    }

    function markDirty() { $('#akdk-unsaved').prop('hidden', false); }

    // Colour pickers update the preview while dragging.
    $form.find('.akdk-color-picker').each(function () {
        var $input = $(this);
        $input.wpColorPicker({
            change: function (event, ui) { updatePreview($input.data('key'), ui.color.toString()); markDirty(); },
            clear: function () { updatePreview($input.data('key'), ''); markDirty(); }
        });
    });

    $form.on('input change', '[data-key]', function () {
        var $el = $(this);
        if ($el.hasClass('akdk-color-picker')) return;
        updatePreview($el.data('key'), currentValue($el));
        markDirty();
    });

    function setField(key, value) {
        if (value === undefined || value === null) return;
        var $el = field(key);
        if (!$el.length) return;
        if ($el.is(':radio')) {
            $el.prop('checked', false).filter('[value="' + String(value) + '"]').prop('checked', true);
            updatePreview(key, String(value));
        } else if ($el.hasClass('akdk-color-picker')) {
            $el.wpColorPicker('color', String(value));
            updatePreview(key, String(value));
        } else if ($el.is(':checkbox')) {
            $el.prop('checked', String(value) === '1');
            updatePreview(key, String(value) === '1' ? '1' : '0');
        } else {
            $el.val(String(value));
            updatePreview(key, String(value));
        }
    }

    function applySettings(data) {
        var merged = $.extend({
            close_btn_color: data.header_text,
            close_btn_hover_bg: 'rgba(127,127,127,0.18)',
            send_btn_icon_hover: data.send_btn_text,
            general_border_color: '#e5e7eb'
        }, data);
        Object.keys(merged).forEach(function (key) { setField(key, merged[key]); });
        markDirty();
    }

    // Tabs (remembered per browser).
    function openTab(name) {
        $('.akdk-tab').removeClass('is-active').filter('[data-tab="' + name + '"]').addClass('is-active');
        $('.akdk-panel').removeClass('is-active').filter('[data-panel="' + name + '"]').addClass('is-active');
        // The chat icon is shown under the chat window while its settings are open.
        if (stage) stage.classList.toggle('akdk-show-launcher', name === 'icons' || name === 'layout');
        try { window.localStorage.setItem('akdk_customizer_tab', name); } catch (e) {}
    }
    $('.akdk-tab').on('click', function () { openTab($(this).data('tab')); });
    try { var saved = window.localStorage.getItem('akdk_customizer_tab'); if (saved && $('.akdk-tab[data-tab="' + saved + '"]').length) openTab(saved); } catch (e) {}

    $('.apply-template-btn').on('click', function (e) {
        e.preventDefault();
        var tpl = $(this).data('template');
        if (!templates[tpl]) return;
        applySettings(templates[tpl]);
        $('.apply-template-btn').removeClass('is-selected');
        $(this).addClass('is-selected');
    });

    $('#wp-ai-reset-btn').on('click', function (e) {
        e.preventDefault();
        if (typeof defaultSettings === 'undefined') return;
        if (window.confirm('Reset all appearance settings to the AK Deep Knowledge default?')) {
            applySettings(defaultSettings);
        }
    });

    // Preview chips/buttons are for show only.
    $(preview).on('click', 'button, a', function (e) { e.preventDefault(); });
});
