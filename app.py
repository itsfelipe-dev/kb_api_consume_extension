from fastapi import FastAPI, WebSocket, WebSocketDisconnect, Request
from fastapi.responses import HTMLResponse
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from pydantic import BaseModel
import json
from fastapi.middleware.cors import CORSMiddleware

app = FastAPI()
origins = [
    "*",
]
app.add_middleware(
    CORSMiddleware,
    allow_origins=origins,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
    expose_headers=["*"],
)
# Templates setup
templates = Jinja2Templates(directory="templates")
app.mount("/static", StaticFiles(directory="static"), name="static")


# Define a model for individual products
class ProductModel(BaseModel):
    productName: str
    productPrice: str
    productQty: str
    productSubtotal: str


# Define the main data model that holds products and the big total
class DataModel(BaseModel):
    products: list[ProductModel]  # List of products
    bigTotal: str  # Total price


# Store received data and WebSocket connections
received_data = DataModel(products=[], bigTotal="0")
active_connections = []


# WebSocket manager to handle connections
class ConnectionManager:
    def __init__(self):
        self.active_connections: list[WebSocket] = []

    async def connect(self, websocket: WebSocket):
        await websocket.accept()
        self.active_connections.append(websocket)

    def disconnect(self, websocket: WebSocket):
        self.active_connections.remove(websocket)

    async def send_data(self, data: dict):
        for connection in self.active_connections:
            await connection.send_text(json.dumps(data))


manager = ConnectionManager()
class DatonModel(BaseModel):
    message: str  # List of products

manager = ConnectionManager()
class CustomerData(BaseModel):
    telefono: str
    correo: str
    nombre: str



# POST endpoint to receive JSON data and notify connected clients
@app.post("/data")
async def receive_data(data: DataModel):
    global received_data
    received_data = data
    # Notify all WebSocket clients
    await manager.send_data(received_data.dict())
    return {"message": "Data received successfully!"}


@app.post("/pos/pay")
async def receive_data(data: DatonModel):
    global received_data
    received_data = data
    # Notify all WebSocket clients
    await manager.send_data(received_data.dict())
    return {"message": "Data received successfully!"}


@app.post("/pos/customer_data")
async def receive_data(data: CustomerData):
    global received_data
    received_data = data
    # Notify all WebSocket clients
    await manager.send_data(received_data.dict())
    return {"message": "Data received successfully!"}


# WebSocket endpoint for client connections
@app.websocket("/ws")
async def websocket_endpoint(websocket: WebSocket):
    await manager.connect(websocket)
    try:
        while True:
            # Keep connection alive
            await websocket.receive_text()
    except WebSocketDisconnect:
        manager.disconnect(websocket)


# Route to serve the page
@app.get("/", response_class=HTMLResponse)
async def read_root(request: Request):
    return templates.TemplateResponse(
        "base.html", {"request": request, "data": received_data.dict()}
    )


# @app.get("/pos/pay", response_class=HTMLResponse)
# async def pos_pay(request: Request):
#     return templates.TemplateResponse(
#         "base.html",
#         {"request": request, "data": received_data.dict(), "thank_you": True},
#     )
