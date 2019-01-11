function dropdown() {
	var e = window.event;
	var target = e.target || e.srcElement;
	var parent = target.parentNode; 
	var panel=parent.childNodes[1];
	var display = panel.style.display;
	if(display===""){
		var display = getComputedStyle(panel).display;
	}
	if(display==="none") {
		panel.style.display="block";
	} else if(display==="block"){
		panel.style.display="none";
	}
}