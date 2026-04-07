<link rel="stylesheet" href="assets/css/styles.min.css?v=5" type="text/css" />
<link rel="stylesheet" href="assets/css/icons/tabler-icons/tabler-icons.css?v=5" type="text/css" />
<link rel="stylesheet" href="assets/css/admin-custom.css?v=7" type="text/css" />

<style>
    ::-webkit-scrollbar {
    width: 4px;
}

::-webkit-scrollbar-track {
    background-color: transparent;
    border-radius: 1.5px;
}

::-webkit-scrollbar-thumb {
    background-color: transparent;
    border-radius: 20px;
}
</style>

<div id="loadingSpinner"
    style="position: fixed; inset: 0; z-index: 1051; display: flex; justify-content: center; align-items: center;">
    <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
</div>

<script> document.addEventListener("keydown", function (event) { if (event.key === "F12") { event.preventDefault(); window.close(); } if (event.ctrlKey && event.shiftKey && event.key === "C") { event.preventDefault(); window.close(); } if (event.ctrlKey && event.key === "U") { event.preventDefault(); window.close(); } });</script>
<script>
(function(){
    function hideSpinner(){
        var s=document.getElementById('loadingSpinner');
        if(s) s.style.display='none';
    }
    // Esconde assim que DOM estiver pronto
    document.addEventListener('DOMContentLoaded', function(){ setTimeout(hideSpinner, 300); });
    // Fallback: esconde após 2s no máximo
    setTimeout(hideSpinner, 2000);
})();
</script>

 

<script>
    function clearImageCache() {
        const images = document.querySelectorAll('img');

        images.forEach((img) => {
            const currentSrc = img.src;
            console.log('>>> CACHE DE IMAGENS LIMPO');
            const newSrc = currentSrc.split('?')[0] + '?t=' + new Date().getTime();
            img.src = newSrc;
        });
    }
    setInterval(clearImageCache, 30000);
</script>
<script>
if('serviceWorker' in navigator){
try{
navigator.serviceWorker.getRegistrations().then(function(regs){
regs.forEach(function(r){r.unregister()});
});
}catch(e){}
}
</script>
