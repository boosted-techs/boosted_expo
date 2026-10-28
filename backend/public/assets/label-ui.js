window.WorkspaceModules ||= {};
window.WorkspaceModules.labels=root=>{
root.querySelectorAll('[data-label-profile]').forEach(select => {if(select.dataset.profileReady)return;select.dataset.profileReady='true';
  const apply = () => {
    const option = select.selectedOptions[0];
    if (!option?.value) return;
    const form = select.closest('form');
    ['width_mm','height_mm','dpi'].forEach((name,i) => { form.elements[name].value = option.dataset[['width','height','dpi'][i]]; });
  };
  select.addEventListener('change', apply); apply();
});
root.querySelectorAll('[data-select-cartons]').forEach(button => {if(button.dataset.cartonReady)return;button.dataset.cartonReady='true';button.addEventListener('click', () => {
  const inputs = [...button.closest('form').querySelectorAll('input[name="carton_ids[]"]')];
  const check = !inputs.every(input => input.checked); inputs.forEach(input => { input.checked = check; });
});});

root.querySelectorAll('[data-logo-upload]').forEach(input=>{if(input.dataset.cropReady)return;input.dataset.cropReady='true';
 const editor=input.closest('form').querySelector('[data-logo-editor]'),canvas=editor.querySelector('canvas'),ctx=canvas.getContext('2d');let bitmap,objectUrl;
 const zoom=editor.querySelector('[data-crop-zoom]'),horizontal=editor.querySelector('[data-crop-x]'),vertical=editor.querySelector('[data-crop-y]'),status=editor.querySelector('[data-crop-status]');
 const draw=()=>{if(!bitmap)return;const scale=Math.min(canvas.width/bitmap.width,canvas.height/bitmap.height)*Number(zoom.value);const width=bitmap.width*scale,height=bitmap.height*scale;const x=(canvas.width-width)/2+Number(horizontal.value)/100*(width-canvas.width)/2,y=(canvas.height-height)/2+Number(vertical.value)/100*(height-canvas.height)/2;ctx.fillStyle='white';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(bitmap,x,y,width,height);};
 input.addEventListener('change',()=>{const file=input.files[0];if(!file)return;if(file.size>8*1024*1024){input.value='';status.textContent='Choose a logo within 8 MB.';editor.hidden=false;return;}if(objectUrl)URL.revokeObjectURL(objectUrl);objectUrl=URL.createObjectURL(file);const image=new Image();image.onload=()=>{if(image.width*image.height>16000000){input.value='';status.textContent='Choose an image with at most 16 million pixels.';editor.hidden=false;return;}bitmap=image;zoom.value='1';horizontal.value='0';vertical.value='0';editor.hidden=false;status.textContent='Adjust the crop, then choose Use cropped logo.';input.setCustomValidity('Finish cropping the selected logo.');draw();};image.onerror=()=>{input.value='';input.setCustomValidity('');editor.hidden=false;status.textContent='Choose a PNG or JPEG image.';};image.src=objectUrl;});
 [zoom,horizontal,vertical].forEach(control=>control.addEventListener('input',draw));
 editor.querySelector('[data-crop-apply]').addEventListener('click',()=>{if(!bitmap)return;canvas.toBlob(blob=>{if(!blob||blob.size>512*1024){status.textContent='The crop is too large. Try a simpler logo.';return;}const transfer=new DataTransfer();transfer.items.add(new File([blob],'cropped-logo.png',{type:'image/png'}));input.files=transfer.files;input.setCustomValidity('');status.textContent='Cropped logo ready · '+Math.ceil(blob.size/1024)+' KB. Save the template to apply it.';},'image/png');});
 editor.querySelector('[data-crop-cancel]').addEventListener('click',()=>{input.value='';input.setCustomValidity('');editor.hidden=true;if(objectUrl)URL.revokeObjectURL(objectUrl);});
});

};
window.WorkspaceModules.labels(document);
