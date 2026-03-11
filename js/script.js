// ===============================
// DASHBOARD FINANCEIRO
// ===============================

const tabs = document.querySelectorAll(".tab-btn");
const contents = document.querySelectorAll(".tab-content");

const form = document.getElementById("formFinanceiro");
const lista = document.getElementById("listaMovimentacoes");
const saldoEl = document.getElementById("saldoTotal");

let dados = [];

// ===============================
// SISTEMA DE ABAS
// ===============================

tabs.forEach(btn => {

btn.addEventListener("click", () => {

const target = btn.dataset.tab;

contents.forEach(content => {

content.classList.remove("active");

});

document.getElementById(target).classList.add("active");

tabs.forEach(b => b.classList.remove("active"));

btn.classList.add("active");

});

});

// ===============================
// ANIMAÇÃO DO SALDO
// ===============================

function animarSaldo(valor){

let atual = 0;
let incremento = valor / 40;

let intervalo = setInterval(() => {

atual += incremento;

if(atual >= valor){
atual = valor;
clearInterval(intervalo);
}

saldoEl.innerText = "R$ " + atual.toFixed(2);

},20);

}

// ===============================
// CALCULAR SALDO
// ===============================

function calcularSaldo(){

let total = 0;

dados.forEach(d => {

if(d.tipo === "receita"){
total += d.valor;
}else{
total -= d.valor;
}

});

animarSaldo(total);

if(total < 0){

saldoEl.style.color = "#ef4444";

}else{

saldoEl.style.color = "#22c55e";

}

}

// ===============================
// CRIAR CARD DE MOVIMENTAÇÃO
// ===============================

function criarMovimentacao(item){

const div = document.createElement("div");

div.classList.add("mov-item");

if(item.tipo === "receita"){
div.classList.add("receita");
}else{
div.classList.add("gasto");
}

div.innerHTML = `

<div class="mov-info">
<h4>${item.descricao}</h4>
<span>${item.tipo}</span>
</div>

<div class="mov-valor">
R$ ${item.valor.toFixed(2)}
</div>

`;

lista.prepend(div);

setTimeout(()=>{
div.classList.add("mostrar");
},100);

}

// ===============================
// SALVAR
// ===============================

function salvar(){

localStorage.setItem("financasDashboard", JSON.stringify(dados));

}

// ===============================
// CARREGAR
// ===============================

function carregar(){

const storage = localStorage.getItem("financasDashboard");

if(storage){

dados = JSON.parse(storage);

dados.forEach(criarMovimentacao);

calcularSaldo();

}

}

// ===============================
// FORM
// ===============================

form.addEventListener("submit", function(e){

e.preventDefault();

const descricao = form.descricao.value;
const valor = parseFloat(form.valor.value);
const tipo = form.tipo.value;

const item = {
descricao,
valor,
tipo
};

dados.push(item);

criarMovimentacao(item);

calcularSaldo();

salvar();

form.reset();

animarCard();

});

// ===============================
// ANIMAÇÃO DE CARDS
// ===============================

function animarCard(){

const cards = document.querySelectorAll(".mov-item");

cards.forEach(card => {

card.style.transform = "scale(0.95)";

setTimeout(()=>{

card.style.transform = "scale(1)";

},200);

});

}

// ===============================
// FILTRO
// ===============================

function filtrar(tipo){

lista.innerHTML = "";

dados
.filter(d => tipo === "todos" || d.tipo === tipo)
.forEach(criarMovimentacao);

}

// ===============================
// ENTRADA DA PÁGINA
// ===============================

window.addEventListener("load", () => {

document.body.classList.add("loaded");

carregar();

});