// Sends the sign-out form to its action, then goes back to the sign-in page
document.getElementById('leaveForm').addEventListener('submit', async function( submitEvnt ){
	submitEvnt.preventDefault();
	const mssgBox = document.getElementById('leaveMssg');
	mssgBox.textContent = '';
	try{
		const rplyObj = await fetch(this.action, { method: 'POST', credentials: 'same-origin' });
		if( rplyObj.ok ){
			window.location.assign('/login');
			return;
		}
		const rplyArr = await rplyObj.json();
		mssgBox.textContent = rplyArr.error || 'Sign-out refused';
	}catch( e ){
		mssgBox.textContent = 'The server cannot be reached';
	}
});
