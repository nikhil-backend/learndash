learndash.registration=learndash.registration||{},learndash.registration.checkout=learndash.registration.checkout||{},((e,t,a)=>{a.handleRadioButtonChange=e=>{if(!e.detail.wrapper.classList.contains("ld-registration-order__checkout"))return;const t=e.detail.newId.replace("ld-payment_type__",""),a=e.detail.oldId.replace("ld-payment_type__",""),r=e.detail.wrapper.querySelector(`.ld-registration-order__checkout-button-${t}`),d=e.detail.wrapper.querySelector(`.ld-registration-order__checkout-button-${a}`);r?.classList.add("ld--selected"),d?.classList.remove("ld--selected")},t.addEventListener("learndashRadioButtonChange",a.handleRadioButtonChange)})(window,document,learndash.registration.checkout);