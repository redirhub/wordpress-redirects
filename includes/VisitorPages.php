<?php
/**
 * The pages a visitor sees instead of a redirect: a password form and the WeChat page.
 *
 * @package RedirHub\Redirects
 */

namespace RedirHub\Redirects;

defined( 'ABSPATH' ) || exit;

use RedirHub\Redirects\Core\Pages;

/**
 * The same pages, in the same words and languages, as RedirHub's edge shows for the link, so a
 * visitor cannot tell which of the two served it. The language follows the visitor's
 * Accept-Language (English, Simplified Chinese, Spanish, as on the edge), not the site's: these
 * pages belong to the link, and they are answered before WordPress loads its translations.
 */
final class VisitorPages {

	/**
	 * The edge's strings (om-script redirector/internal/pages/i18n.go), by language.
	 *
	 * @var array<string, array<string, string>>
	 */
	const STRINGS = array(
		'en' => array(
			'pw.title' => 'Protected link',
			'pw.pill' => 'Password required',
			'pw.h1' => 'This link is protected.',
			'pw.body' => 'Enter the password you were given to continue. The destination stays hidden until you unlock it.',
			'pw.label' => 'Password',
			'pw.submit' => 'Unlock link',
			'pw.wrong' => 'That password didn’t match. Try again.',
			'pw.limited' => 'Too many attempts. Wait a minute and try again.',
			'pw.hidden' => 'hidden',
			'pw.owner' => 'Own this link? Change its password in %s',
			'wc.title' => 'Open in your browser',
			'wc.pill' => 'Can’t open inside WeChat',
			'wc.body' => 'Open this link in your browser to continue.',
			'wc.step1' => 'Tap ··· in the top-right corner',
			'wc.step2' => 'Choose “Open in Browser”',
			'wc.hint' => 'Tap here',
			'wc.copy' => 'Copy link',
			'wc.copied' => 'Copied',
		),
		'zh-CN' => array(
			'pw.title' => '受保护的链接',
			'pw.pill' => '需要密码',
			'pw.h1' => '这个链接受密码保护。',
			'pw.body' => '输入你收到的密码后继续。解锁前不会显示目标地址。',
			'pw.label' => '密码',
			'pw.submit' => '解锁链接',
			'pw.wrong' => '密码不正确，请重试。',
			'pw.limited' => '尝试次数过多，请稍等一分钟再试。',
			'pw.hidden' => '已隐藏',
			'pw.owner' => '你是链接所有者？在 %s 中修改密码',
			'wc.title' => '请在浏览器中打开',
			'wc.pill' => '微信内无法直接打开',
			'wc.body' => '在浏览器中打开这个链接后继续访问。',
			'wc.step1' => '点击右上角「···」',
			'wc.step2' => '选择「在浏览器中打开」',
			'wc.hint' => '点这里',
			'wc.copy' => '复制链接',
			'wc.copied' => '已复制',
		),
		'es' => array(
			'pw.title' => 'Enlace protegido',
			'pw.pill' => 'Se requiere contraseña',
			'pw.h1' => 'Este enlace está protegido.',
			'pw.body' => 'Introduce la contraseña que te dieron para continuar. El destino permanece oculto hasta que lo desbloquees.',
			'pw.label' => 'Contraseña',
			'pw.submit' => 'Desbloquear enlace',
			'pw.wrong' => 'La contraseña no coincide. Inténtalo de nuevo.',
			'pw.limited' => 'Demasiados intentos. Espera un minuto e inténtalo de nuevo.',
			'pw.hidden' => 'oculto',
			'pw.owner' => '¿Es tu enlace? Cambia su contraseña en %s',
			'wc.title' => 'Abre en tu navegador',
			'wc.pill' => 'No se puede abrir en WeChat',
			'wc.body' => 'Abre este enlace en tu navegador para continuar.',
			'wc.step1' => 'Toca ··· en la esquina superior derecha',
			'wc.step2' => 'Elige “Abrir en el navegador”',
			'wc.hint' => 'Toca aquí',
			'wc.copy' => 'Copiar enlace',
			'wc.copied' => 'Copiado',
		),
	);

	/**
	 * The best supported language for an Accept-Language header, as the edge picks it: any `zh-*`
	 * tag is Simplified Chinese; English is the fallback.
	 *
	 * @param string $accept The Accept-Language header.
	 */
	public static function language( string $accept ): string {
		$tags = array();
		foreach ( explode( ',', $accept ) as $position => $part ) {
			$fields = explode( ';', trim( $part ) );
			$name   = strtolower( trim( $fields[0] ) );
			if ( '' === $name ) {
				continue;
			}
			$q = 1.0;
			foreach ( array_slice( $fields, 1 ) as $field ) {
				$field = trim( $field );
				if ( 0 === strpos( $field, 'q=' ) && is_numeric( substr( $field, 2 ) ) ) {
					$q = (float) substr( $field, 2 );
				}
			}
			if ( $q > 0 ) {
				$tags[] = array( $name, $q, $position );
			}
		}
		usort(
			$tags,
			static function ( array $a, array $b ): int {
				return $b[1] <=> $a[1] ?: $a[2] <=> $b[2];
			}
		);
		foreach ( $tags as list( $name ) ) {
			foreach ( array( 'zh' => 'zh-CN', 'es' => 'es', 'en' => 'en' ) as $prefix => $language ) {
				if ( $name === $prefix || 0 === strpos( $name, $prefix . '-' ) ) {
					return $language;
				}
			}
		}

		return 'en';
	}

	/**
	 * The password form of a protected link.
	 *
	 * @param string $language A language of STRINGS.
	 * @param string $host     The hostname the visitor asked for.
	 * @param string $path     The path the visitor asked for.
	 * @param string $error    `pw.wrong`, `pw.limited` or an empty string.
	 */
	public static function password( string $language, string $host, string $path, string $error = '' ): string {
		$l    = self::STRINGS[ $language ] ?? self::STRINGS['en'];
		$e    = array( Pages::class, 'escape' );
		$fail = '' === $error ? '' : ' aria-invalid="true" aria-describedby="pw-err"';
		$main = '<div class="route" aria-hidden="true"><div class="chip mono">' . $e( $host . $path ) . '</div><div class="seg"></div>'
			. '<div class="node"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"></rect><path d="M8 11V8a4 4 0 0 1 8 0v3"></path></svg></div>'
			. '<div class="seg dash"></div><div class="chip ghost mono">' . $e( $l['pw.hidden'] ) . '</div></div>'
			. '<div class="head"><div class="pill">' . $e( $l['pw.pill'] ) . '</div><h1>' . $e( $l['pw.h1'] ) . '</h1><p class="lead">' . $e( $l['pw.body'] ) . '</p></div>'
			. '<form method="post" autocomplete="off"><label for="pw">' . $e( $l['pw.label'] ) . '</label><div class="field">'
			. '<input id="pw" name="password" type="password" autocomplete="current-password" required autofocus' . $fail . '>'
			. '<button type="submit" class="btn">' . $e( $l['pw.submit'] ) . ' <svg viewBox="0 0 24 24"><path d="M5 12h12"></path><path d="M12 6l6 6-6 6"></path></svg></button></div>'
			. ( '' === $error ? '' : '<p class="err" id="pw-err" role="alert">' . $e( $l[ $error ] ?? '' ) . '</p>' )
			. '</form>';
		$foot = '<a href="' . $e( Settings::dashboard() . '/links' ) . '" rel="nofollow">' . $e( sprintf( $l['pw.owner'], 'RedirHub' ) ) . '</a>';

		return '<!doctype html><html lang="' . $e( $language ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex"><title>' . $e( $l['pw.title'] ) . ' · RedirHub</title><style>' . self::CSS . '</style></head>'
			. '<body class="t-accent"><header><div class="brand">' . self::MARK . 'RedirHub</div><div class="host mono">' . $e( $host ) . '</div></header>'
			. '<main><div class="wrap">' . $main . '</div></main><footer>' . $foot . '</footer></body></html>' . "\n";
	}

	/**
	 * The page that asks a WeChat or QQ in-app browser to open the link in the system browser.
	 *
	 * @param string $language A language of STRINGS.
	 */
	public static function wechat( string $language ): string {
		$l = self::STRINGS[ $language ] ?? self::STRINGS['en'];
		$e = array( Pages::class, 'escape' );

		return '<!doctype html><html lang="' . $e( $language ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">'
			. '<meta name="robots" content="noindex"><title>' . $e( $l['wc.title'] ) . '</title><style>' . self::WECHAT_CSS . '</style></head><body>'
			. '<div class="hint" aria-hidden="true"><span>' . $e( $l['wc.hint'] ) . ' ···</span><svg viewBox="0 0 34 34"><path d="M4 30C10 18 18 10 28 6"></path><path d="M19 5h9v9"></path></svg></div>'
			. '<main><div class="pill">' . $e( $l['wc.pill'] ) . '</div><h1>' . $e( $l['wc.title'] ) . '</h1><p>' . $e( $l['wc.body'] ) . '</p>'
			. '<ol><li><b>1</b>' . $e( $l['wc.step1'] ) . '</li><li><b>2</b>' . $e( $l['wc.step2'] ) . '</li></ol>'
			. '<button type="button" id="copy" data-done="' . $e( $l['wc.copied'] ) . '">' . $e( $l['wc.copy'] ) . '</button></main>'
			. '<script>(function(){var b=document.getElementById("copy");b.addEventListener("click",function(){var u=location.href,done=function(){b.textContent=b.getAttribute("data-done")};if(navigator.clipboard){navigator.clipboard.writeText(u).then(done)}else{var t=document.createElement("textarea");t.value=u;document.body.appendChild(t);t.select();try{document.execCommand("copy");done()}catch(e){}document.body.removeChild(t)}});'
			. 'setInterval(function(){var ua=navigator.userAgent.toLowerCase();if(ua.indexOf("micromessenger/")===-1&&ua.indexOf("qq/")===-1){location.reload()}},1000)})();</script>'
			. '</body></html>' . "\n";
	}

	/** RedirHub's mark (assets/logo.svg), inline: the visitor's page loads nothing else. */
	const MARK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="120 114 368 412" width="25" height="28" aria-hidden="true"><path fill="#E49426" fill-rule="evenodd" d="M130 124H428A50 50 0 0 1 478 174V350L400 428V200H207ZM150 144H268V152H158ZM177 171H268V179H185Z"/><path fill="#20A795" d="M343 251V383L211 515V383Z"/></svg>';

	/** The edge's page style, trimmed to what the password page uses. */
	const CSS = ':root{--bg:#F6F7F6;--ink:#0E1513;--body:#4B5754;--muted:#5F6B68;--line:#DCE2E0;--line2:#EEF1F0;--accent:#0F7A68;--accent-ink:#0B5E50;--accent-tint:#E3F2EE;--red-ink:#A51C12}'
		. '*{box-sizing:border-box}html,body{margin:0;background:var(--bg);color:var(--ink)}'
		. 'body{min-height:100vh;display:flex;flex-direction:column;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei","Noto Sans",Helvetica,Arial,sans-serif}'
		. 'a{color:var(--accent-ink)}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}'
		. 'header{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:24px 40px}.brand{display:flex;align-items:center;gap:10px;font-weight:600;font-size:15px}.host{font-size:13px;color:var(--muted)}'
		. 'main{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 20px}.wrap{width:100%;max-width:580px;display:flex;flex-direction:column;gap:28px}'
		. '.route{display:flex;align-items:center}.chip{flex:0 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:6px 12px;border-radius:999px;background:#fff;border:1px solid var(--line);font-size:13px}'
		. '.chip.ghost{background:transparent;border-style:dashed;border-color:#C3CBC8;color:var(--muted)}.seg{flex:1;min-width:16px;height:2px;background:var(--ink)}.seg.dash{height:0;border-top:2px dashed #C3CBC8;background:none}'
		. '.node{flex:none;width:36px;height:36px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:var(--accent);box-shadow:0 0 0 6px var(--accent-tint)}'
		. '.node svg{width:16px;height:16px;stroke:#fff;fill:none;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}'
		. '.head{display:flex;flex-direction:column;gap:16px}.pill{align-self:flex-start;display:inline-flex;align-items:center;gap:8px;padding:5px 12px;border-radius:999px;font-size:13px;font-weight:500;background:var(--accent-tint);color:var(--accent-ink)}'
		. '.pill::before{content:"";width:7px;height:7px;border-radius:999px;background:currentColor}h1{margin:0;font-size:52px;line-height:1.05;letter-spacing:-.035em;font-weight:600;text-wrap:balance}'
		. '.lead{margin:0;font-size:18px;line-height:1.55;color:var(--body)}form{display:flex;flex-direction:column;gap:10px}label{font-size:14px;font-weight:500}.field{display:flex;flex-wrap:wrap;gap:12px}'
		. '.field input{flex:1 1 260px;min-width:0;min-height:48px;padding:0 16px;border-radius:12px;border:1px solid #B9C3C0;background:#fff;font:inherit;color:var(--ink)}.field input:focus{outline:2px solid var(--accent);outline-offset:1px}'
		. '.btn{display:inline-flex;align-items:center;gap:10px;min-height:48px;padding:0 22px;border-radius:12px;border:0;background:var(--accent);color:#fff;font:inherit;font-weight:600;cursor:pointer}.btn:hover{background:#0B5E50}'
		. '.btn svg{width:16px;height:16px;stroke:#fff;fill:none;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}.err{margin:0;font-size:14px;color:var(--red-ink)}'
		. 'footer{display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;padding:20px 40px 28px;border-top:1px solid #E4E8E6;font-size:13px;color:var(--muted)}'
		. '@media (max-width:600px){header,footer{padding-left:20px;padding-right:20px}h1{font-size:38px}.lead{font-size:17px}}';

	/** The edge's WeChat page style. */
	const WECHAT_CSS = '*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#F6F7F6;color:#0E1513}'
		. 'body{min-height:100vh;display:flex;flex-direction:column;font:16px/1.5 -apple-system,BlinkMacSystemFont,"PingFang SC","Hiragino Sans GB","Microsoft YaHei","Segoe UI","Noto Sans",Helvetica,Arial,sans-serif}'
		. '.hint{position:fixed;top:10px;right:14px;display:flex;align-items:flex-start;gap:6px;animation:nudge 1.6s ease-in-out infinite}'
		. '.hint span{margin-top:26px;padding:6px 10px;border-radius:10px;background:#0E1513;color:#fff;font-size:13px;font-weight:500}'
		. '.hint svg{width:34px;height:34px;fill:none;stroke:#0E1513;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}'
		. '@keyframes nudge{0%,100%{transform:translate(0,0)}50%{transform:translate(4px,-4px)}}@media (prefers-reduced-motion: reduce){*{animation:none !important}}'
		. 'main{flex:1;display:flex;flex-direction:column;justify-content:center;gap:24px;width:100%;max-width:480px;margin:0 auto;padding:72px 20px 32px}'
		. '.pill{align-self:flex-start;display:inline-flex;align-items:center;gap:8px;padding:5px 12px;border-radius:999px;background:#E3F2EE;color:#0B5E50;font-size:13px;font-weight:500}'
		. '.pill::before{content:"";width:7px;height:7px;border-radius:999px;background:#0F7A68}h1{margin:0;font-size:32px;line-height:1.15;letter-spacing:-.02em;font-weight:700}p{margin:0;color:#4B5754}'
		. 'ol{margin:0;padding:0;list-style:none;border:1px solid #DCE2E0;border-radius:14px;background:#fff;overflow:hidden}li{display:flex;align-items:center;gap:14px;padding:16px;border-bottom:1px solid #EEF1F0;font-weight:500}li:last-child{border-bottom:0}'
		. 'li b{flex:none;width:26px;height:26px;border-radius:999px;background:#0E1513;color:#fff;font-size:13px;display:flex;align-items:center;justify-content:center}'
		. 'button{min-height:50px;border-radius:12px;border:1px solid #DCE2E0;background:#fff;color:#0E1513;font:inherit;font-weight:500;cursor:pointer}';
}
