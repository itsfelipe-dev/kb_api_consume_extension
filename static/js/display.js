const ws = new WebSocket("ws://127.0.0.1:8000/ws");
let messageTimeout;
let slideshowActive = false;
let idleTimeSlideshow = 600000;
// WebSocket message event
ws.onmessage = function (event) {
    const data = JSON.parse(event.data);

    if (Object.hasOwn(data, "message")) {
        orderPayed(data);
    }
    if (Object.hasOwn(data, "correo")) {
        customerData(data);
    }
    displayProductData(data);


    // Reset to original state only if initializeSlideshow is active
    if (slideshowActive) {
        resetToOriginalState()
        slideshowActive = false;
        clearTimeout(messageTimeout);
    }

    startMessageTimeout();


    // Start or restart the timeout
};

function startMessageTimeout() {
    clearTimeout(messageTimeout);
    messageTimeout = setTimeout(() => {
        initializeSlideshow();
        slideshowActive = true;
    }, idleTimeSlideshow);
}

function customerData(data) {
    Swal.fire({
        title: "Valida tus datos",
        timer: 20000,
        html: `
     <div class="text-2xl">
            <p"><strong>Nombre:</strong> ${data.nombre ? data.nombre : ''}</p>
            <p><strong>Correo:</strong> ${data.correo}</p>
            <p><strong>Teléfono:</strong> ${data.telefono ? data.telefono : ''}</p>
        </div>
`,
        icon: 'info',
        confirmButtonText: 'Aceptar'
    });
};


function orderPayed(message) {
    Swal.fire({
        title: '¡Pago Exitoso!',
        timer: 20000,
        html: `
   <div class="card animate__animated animate__fadeIn">
    <h2>¡Tu pago ha sido procesado con éxito!</h2>
    <p class="mb-5">Gracias por tu compra.</p>

        <br></br>

    <select class="star-rating ml-5 pt-10 animate__animated animate__wobble" id="pwd">
        <option value="">Selecciona una calificación</option>
        <option value="5">Excelente</option>
        <option value="4">Muy bueno</option>
        <option value="3">Promedio</option>
        <option value="2">Pobre</option>
        <option value="1">Terrible</option>
    </select>
</div>
`,
        icon: 'success',
        confirmButtonText: 'Aceptar'
    });

    var stars = new StarRating('.star-rating', {
        tooltip: 'Selecciona una calificación ',
        clearable: false,
    });

    var e = document.getElementById("pwd");
    e.onchange = function () {
        var strUser = e.options[e.selectedIndex].value; console.log('You selected: ', strUser);
    }


};
function formatNumber(input) {
    // input = parseFloat(input);
    // return input % 1 == 0 ? input : parseFloat(input).toFixed(1);
    return input
};


function displayProductData(data) {
    const tableBody = document.getElementById('productTableBody');
    const totalElement = document.querySelector('.big-total');
    const totalElementInfo = document.querySelector('.big-total-info');


    tableBody.innerHTML = '';
    totalElement.innerHTML = '';
    totalItems = 0;
    data.products.forEach(item => {
        const row = document.createElement('tr');
        row.className = "py-4";
        if (item.productName) {

            totalItems = totalItems + parseInt(item.productQty);
            item.productName = item.productName.replace("-", "").toUpperCase()
            row.innerHTML = `
                <th scope="row" class=" text-xl py-4 text-gray-700 ">${item.productName}</th>
                <td class="  text-xl text-gray-300 py-4">${item.productQty}</td>
                <td class="  text-xl px-2 text-gray-300 py-4">$${formatNumber(item.productPrice)}</td>
                <td class=" font-bold text-xl px-2 text-gray-700 py-4">$${formatNumber(item.productSubtotal)}</td>
            `;
            tableBody.appendChild(row);
        }

        if (data.bigTotal) {
            totalElement.innerHTML = `
                <h3 class="font-semibold text-xl">TOTAL:</h3>
                <h1 class="text-4xl animate__animated animate__bounce">$${data.bigTotal}</h1>

            `;
            totalElementInfo.innerHTML=`
                <h3 class="font-semibold text-xl animate__animated animate__fadeIn">PRODUCTOS: ${totalItems}</h3>
`
        }
    });
}


ws.onclose = function () {
    console.log("WebSocket connection closed");
};

startMessageTimeout();
