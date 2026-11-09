// Sends the sign-in form to its action, then goes back to the page asked
// for, or to the home page: the session travels in its cookie, nothing is
// kept by this script
document.getElementById('loginForm').addEventListener('submit', async function( submitEvnt ){
	submitEvnt.preventDefault();
	const mssgBox = document.getElementById('loginMssg');
	mssgBox.textContent = '';
	try{
		const rplyObj = await fetch(this.action, { method: 'POST', body: new URLSearchParams(new FormData(this)), credentials: 'same-origin' });
		if( rplyObj.ok ){
			// Back to the page asked for when it is one of this site and the session may read it, home otherwise
			const backUrl = new URLSearchParams(window.location.search).get('back') || '';
			let goalUrl = '/';
			// A path of this site: one leading slash, no backslash, no control character
			if( /^\/(?![\/\\])/.test(backUrl) && !/[\u0000-\u001F\u007F\\]/.test(backUrl) ){
				let backAdr = null;
				try{
					backAdr = new URL(backUrl, window.location.origin);
				}catch( e ){
					backAdr = null;
				}
				// Compared as the browser reads it, and opened as that absolute address: a path such as /..//host is never assigned as written
				if( backAdr!==null && backAdr.origin===window.location.origin ){
					const backObj = await fetch(backAdr.href, { method: 'HEAD', credentials: 'same-origin', redirect: 'manual' });
					if( backObj.ok ){
						goalUrl = backAdr.href;
					}
				}
			}
			window.location.assign(goalUrl);
			return;
		}
		const rplyArr = await rplyObj.json();
		mssgBox.textContent = rplyArr.error || 'Sign-in refused';
	}catch( e ){
		mssgBox.textContent = 'The server cannot be reached';
	}
});
