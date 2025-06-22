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
			if( /^\/(?![\/\\])/.test(backUrl) && !backUrl.includes('\\') ){
				const backObj = await fetch(backUrl, { method: 'HEAD', credentials: 'same-origin', redirect: 'manual' });
				if( backObj.ok ){
					goalUrl = backUrl;
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
