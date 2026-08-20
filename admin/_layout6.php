<?php
session_save_path('C:/xampp1/htdocs/pos/pos/pos/sessions');
session_start();
$_SESSION['user_id'] = 3;
$_SESSION['user_name'] = 'SHIRT';
$_SESSION['user_email'] = 'asifkst20242300@gmail.com';
$_SESSION['user_role'] = 'admin';
$_SESSION['store_id'] = null;
$_SESSION['owner_id'] = 3;
$_GET['ids'] = '21,76';
$_GET['qty'] = '1';
$_GET['sw'] = '';
$_GET['sh'] = '';
$_GET['pw'] = '';
$_GET['ph'] = '';
ob_start();
include 'C:/xampp1/htdocs/pos/pos/pos/admin/print-labels.php';
$html = ob_get_clean();
echo str_replace('</body>', '<script>(function(){var out=document.createElement("pre");var info="";var labels=document.querySelectorAll(".label");info+="labels:"+labels.length+"\n";for(var j=0;j<labels.length;j++){var lbl=labels[j];var lr=lbl.getBoundingClientRect();info+="LABEL "+j+": "+lr.width+"x"+lr.height+"\n";var sel=[".label-shop",".label-name",".label-price",".label-barcode",".label-barcode-text"];for(var i=0;i<sel.length;i++){var el=lbl.querySelector(sel[i]);if(!el){info+=sel[i]+": MISSING\n";continue;}var r=el.getBoundingClientRect();var cs=getComputedStyle(el);var txt=(el.textContent||"").trim().replace(/\s+/g," ").substring(0,25);info+=sel[i]+": "+r.width+"x"+r.height+" fs:"+cs.fontSize+" top:"+(r.top-lr.top).toFixed(1)+" bottom:"+(r.top+r.height-lr.top).toFixed(1)+" txt:"+txt+"\n";}info+="overflow:"+(lbl.scrollHeight>lbl.clientHeight)+"\n";}out.textContent=info;document.body.appendChild(out);})();</script></body>', $html);