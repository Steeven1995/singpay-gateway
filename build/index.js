(()=>{"use strict";
    const t = window.React,
        e = window.wp.htmlEntities,
        a = window.wp.i18n,
        n = window.wc.wcBlocksRegistry,
        i = window.wc.wcSettings;
    
    // Fonction pour obtenir les données de configuration de SingPay
    const l = () => {
        const t = (0, i.getSetting)("singpay_data", null);
        if (!t) throw new Error("Sing initialization data is not available");
        return t;
    };
    
    // Fonction pour décoder la description de SingPay
    const s = () => (0, e.decodeEntities)(l()?.description || "");
    
    // Enregistrement de la méthode de paiement SingPay
    (0, n.registerPaymentMethod)({
        name: "singpay",
        label: (0, t.createElement)(
            () => (0, t.createElement)("img", { src: l()?.logo_url, alt: l()?.title }),
            null
        ),
        ariaLabel: (0, a.__)("Singpay payment method", "woocommerce-gateway-singpay"),
        canMakePayment: () => !0,
        content: (0, t.createElement)(s, null),
        edit: (0, t.createElement)(s, null),
        supports: {
            features: l()?.supports ?? []
        }
    });
    })();
    