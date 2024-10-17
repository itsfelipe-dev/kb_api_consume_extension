// Open a WebSocket connection

const ws = new WebSocket("ws://127.0.0.1:8000/ws");


ws.onopen = function () {
    console.log("WebSocket connection established.");
};



ws.onmessage = function (event) {
    const data = JSON.parse(event.data);

    if (Object.hasOwn(data, "message")) {

        orderPayed(data)
    } else {
        displayProductData(data);
    }


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
    input = parseFloat(input);
    return input % 1 == 0 ? input : parseFloat(input).toFixed(1);
};


function displayProductData(data) {
    const tableBody = document.getElementById('productTableBody');
    const totalElement = document.querySelector('.big-total');

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
                <h3 class=" text-2xl animate__animated animate__bounce">$${data.bigTotal}</h3>
                <h4 class=" text-xl animate__animated animate__fadeIn">ITEMS: ${totalItems}</h4>



            `;
        }
    });
}


ws.onclose = function () {
    console.log("WebSocket connection closed");
};